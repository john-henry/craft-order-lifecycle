<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\base;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\events\MailEvent;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\events\RefundTransactionEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\queue\jobs\SendEmail as SendEmailJob;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Emails;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments;
use craft\commerce\services\Transactions;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Console;
use craft\queue\Queue;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use johnhenry\orderlifecycle\controllers\AiController;
use johnhenry\orderlifecycle\controllers\ExportController;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\fields\OrderLifecycleField;
use johnhenry\orderlifecycle\jobs\PruneLogs;
use johnhenry\orderlifecycle\models\SettingsModel;
use johnhenry\orderlifecycle\OrderLifecycle;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use johnhenry\orderlifecycle\variables\OrderLifecycleVariable;
use johnhenry\orderlifecycle\widgets\AiInsightsWidget;
use johnhenry\orderlifecycle\widgets\OrderLifecycleStatsWidget;
use Throwable;
use yii\base\Application;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\Exception as DbException;
use yii\queue\ExecEvent;
use yii\queue\Queue as QueueAlias;

/**
 * Wires the plugin's event listeners, CP URL rules and lifecycle overrides.
 *
 * Keeps the main plugin class a thin shell: event listeners defined here only
 * wire Commerce/Craft events to logger service methods and never implement
 * domain logic inline.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait PluginTrait
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array|null The CP nav item definition, or null if unavailable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('order-lifecycle', 'Order Lifecycle');

        $user = Craft::$app->getUser();
        $subnav = [];

        // Craft already hides the whole section unless the user has its built-in
        // "Access Order Lifecycle" permission, so the Overview page checks that one too
        if ($user->checkPermission('accessplugin-order-lifecycle')) {
            $subnav['overview'] = ['label' => Craft::t('order-lifecycle', 'Overview'), 'url' => 'order-lifecycle'];
        }

        if (OrderLifecycle::$plugin->getSettings()->getAnthropicApiKey() && $user->checkPermission(AiController::PERMISSION_GENERATE_INSIGHTS)) {
            $subnav['insights'] = ['label' => Craft::t('order-lifecycle', 'AI Insights'), 'url' => 'order-lifecycle/insights'];
        }

        if ($user->checkPermission(ExportController::PERMISSION_EXPORT_EVENTS) && $user->checkPermission('commerce-manageOrders')) {
            $subnav['export'] = ['label' => Craft::t('order-lifecycle', 'Export'), 'url' => 'order-lifecycle/export'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'order-lifecycle/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    /**
     * The plugin settings, typed.
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    public function getSettings(): SettingsModel
    {
        $settings = parent::getSettings();
        assert($settings instanceof SettingsModel);

        return $settings;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Registers the plugin's field types.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = OrderLifecycleField::class;
            }
        );
    }

    /**
     * Registers the Twig variable for use in templates.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('orderLifecycle', OrderLifecycleVariable::class);
            }
        );
    }

    /**
     * Registers plugin permissions.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('order-lifecycle', 'Order Lifecycle'),
                    'permissions' => [
                        ExportController::PERMISSION_EXPORT_EVENTS => [
                            'label' => Craft::t('order-lifecycle', 'Export lifecycle events'),
                        ],
                        AiController::PERMISSION_GENERATE_INSIGHTS => [
                            'label' => Craft::t('order-lifecycle', 'Generate AI insights'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Registers the Control Panel URL rules.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // our rules first, so they take priority over Craft's own
                $event->rules = array_merge([
                    'settings/plugins/order-lifecycle' => 'order-lifecycle/settings/edit',
                    'order-lifecycle/settings' => 'order-lifecycle/settings/edit',
                    'order-lifecycle/export/csv' => 'order-lifecycle/export/csv',
                    'order-lifecycle/ai/insights' => 'order-lifecycle/ai/insights',
                    'order-lifecycle/ai/store-insights' => 'order-lifecycle/ai/store-insights',
                    'order-lifecycle' => 'order-lifecycle/dashboard/index',
                    'order-lifecycle/insights' => 'order-lifecycle/dashboard/insights',
                    'order-lifecycle/export' => 'order-lifecycle/dashboard/export',
                ], $event->rules);
            }
        );
    }

    /**
     * Registers the plugin's dashboard widgets.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = OrderLifecycleStatsWidget::class;
                $event->types[] = AiInsightsWidget::class;
            }
        );
    }

    /**
     * Registers the Commerce order lifecycle event listeners.
     *
     * Every listener delegates to a logger service method; no domain logic is
     * implemented in the closures themselves.
     *
     * @return void
     * @throws InvalidConfigException If the logger component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerListeners(): void
    {
        $logger = $this->getLogger();
        /** @var SettingsModel $settings */
        $settings = $this->getSettings();

        $this->_registerOrderSaveListeners($logger);

        if ($settings->logLineItems) {
            $this->_registerLineItemListeners($logger);
        }

        if ($settings->logStatusChanges) {
            $this->_registerStatusListener($logger);
        }

        $this->_registerCompletionListeners($logger, $settings);
        $this->_registerPaymentListeners($logger, $settings);

        if ($settings->logEmailSent) {
            $this->_registerEmailListeners($logger);
        }

        // A queue worker lives for many jobs, and the logger's per-request
        // memory of each order's latest snapshot would otherwise outlive the
        // job that filled it, grow without end, and be preferred over rows
        // written since by other processes.
        foreach ([QueueAlias::EVENT_AFTER_EXEC, QueueAlias::EVENT_AFTER_ERROR] as $queueEvent) {
            Event::on(Queue::class, $queueEvent, static function() use ($logger) {
                $logger->clearSnapshotCache();
            });
        }
    }

    /**
     * Registers order save/delete listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerOrderSaveListeners(OrderLifecycleLogger $logger): void
    {
        // A trashed order keeps its timeline: the event fires for a soft
        // delete as well as a hard one, and dateDeleted is set on the model
        // either way, so hardDelete is the only thing that tells them apart.
        // Restoring an order has to bring its history back with it. The
        // orderId foreign key already cascades on a real delete; this stands
        // in for it on an install where that key has gone missing.
        Event::on(Order::class, Element::EVENT_AFTER_DELETE, static function($e) use ($logger) {
            self::_logSafely(static function() use ($logger, $e) {
                /** @var Order $order */
                $order = $e->sender;
                if ($order->id && $order->hardDelete) {
                    $logger->deleteLogsForOrder((int)$order->id);
                }
            });
        });

        // Notes the order's state before Commerce fires line-item and status
        // events from inside the save, so the changes found afterwards are
        // compared against the order as it was, not as those events left it.
        // Bulk resaves and propagation change nothing a shopper or merchant
        // did, so they log nothing.
        Event::on(Order::class, Element::EVENT_BEFORE_SAVE, static function($e) use ($logger) {
            /** @var Order $order */
            $order = $e->sender;
            if ($order->resaving || $order->propagating) {
                return;
            }

            self::_logSafely(static fn() => $logger->beginOrderSave($order));
        });

        // Cart created/updated + granular change detection. Fires from
        // Order::updateOrderPaidInformation() inside Commerce's payment
        // try/catch on a successful charge, so a logging failure here must be
        // contained (see _logSafely()) or it would report the payment as failed.
        Event::on(Order::class, Element::EVENT_AFTER_SAVE, static function($e) use ($logger) {
            /** @var Order $order */
            $order = $e->sender;
            if ($order->resaving || $order->propagating) {
                return;
            }

            self::_logSafely(static function() use ($logger, $e, $order) {
                $logger->logOrderSaveChanges($order, (bool)$e->isNew);
            });
        });
    }

    /**
     * Registers line-item add/remove listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerLineItemListeners(OrderLifecycleLogger $logger): void
    {
        // The order being saved fires these, so it is the sender: asking the
        // line item for its order would load a fresh copy part way through
        // the save, missing whatever else the save is changing.
        Event::on(Order::class, Order::EVENT_AFTER_APPLY_ADD_LINE_ITEM,
            static function(LineItemEvent $e) use ($logger) {
                self::_logSafely(static function() use ($logger, $e) {
                    $li = $e->lineItem;
                    /** @var Order $order */
                    $order = $e->sender;
                    $logger->log($order, EventType::LINE_ITEM_ADDED, [
                        'sku' => $li->sku, 'qty' => $li->qty, 'subtotal' => $li->getSubtotal(),
                    ]);
                });
            }
        );

        Event::on(Order::class, Order::EVENT_AFTER_APPLY_REMOVE_LINE_ITEM,
            static function(LineItemEvent $e) use ($logger) {
                self::_logSafely(static function() use ($logger, $e) {
                    $li = $e->lineItem;
                    /** @var Order $order */
                    $order = $e->sender;
                    $logger->log($order, EventType::LINE_ITEM_REMOVED, [
                        'sku' => $li->sku, 'qty' => $li->qty,
                    ]);
                });
            }
        );
    }

    /**
     * Registers the order status change listener.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerStatusListener(OrderLifecycleLogger $logger): void
    {
        Event::on(OrderHistories::class, OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(OrderStatusEvent $e) use ($logger) {
                self::_logSafely(static function() use ($logger, $e) {
                    $order = $e->order;
                    $logger->log($order, EventType::STATUS_CHANGED, [
                        'oldStatusId' => $e->orderHistory->prevStatusId ?? null,
                        'newStatusId' => $order->orderStatusId,
                    ]);
                });
            }
        );
    }

    /**
     * Registers order completion / paid / authorized listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @param SettingsModel $settings The plugin settings.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCompletionListeners(OrderLifecycleLogger $logger, SettingsModel $settings): void
    {
        if ($settings->logOrderComplete) {
            Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER,
                static function($e) use ($logger) {
                    self::_logSafely(static function() use ($logger, $e) {
                        /** @var Order $order */
                        $order = $e->sender;
                        $logger->log($order, EventType::ORDER_COMPLETED, [
                            'totalPrice' => $order->getTotalPrice(), 'currency' => $order->currency,
                        ]);
                    });
                }
            );
        }

        if ($settings->logOrderPaid) {
            Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID,
                static function($e) use ($logger) {
                    self::_logSafely(static function() use ($logger, $e) {
                        /** @var Order $order */
                        $order = $e->sender;
                        $lastTransaction = $order->getLastTransaction();
                        $logger->log($order, EventType::ORDER_PAID, [
                            'totalPaid' => $order->getTotalPaid(),
                            'currency' => $order->currency,
                            'gatewayName' => $lastTransaction?->getGateway()?->name,
                        ]);
                    });
                }
            );
        }

        if ($settings->logPaymentAuthorized) {
            Event::on(Order::class, Order::EVENT_AFTER_ORDER_AUTHORIZED,
                static function($e) use ($logger) {
                    self::_logSafely(static function() use ($logger, $e) {
                        /** @var Order $order */
                        $order = $e->sender;
                        $logger->log($order, EventType::PAYMENT_AUTHORIZED, [
                            'totalAuthorized' => $order->getTotalAuthorized(),
                            'currency' => $order->currency,
                        ]);
                    });
                }
            );
        }
    }

    /**
     * Registers payment lifecycle listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @param SettingsModel $settings The plugin settings.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPaymentListeners(OrderLifecycleLogger $logger, SettingsModel $settings): void
    {
        if ($settings->logPaymentAttempts) {
            Event::on(Payments::class, Payments::EVENT_AFTER_PROCESS_PAYMENT,
                static function(ProcessPaymentEvent $e) use ($logger) {
                    self::_logSafely(static function() use ($logger, $e) {
                        $status = $e->transaction->status;

                        // Offsite and PaymentIntent gateways (Stripe) return an
                        // intermediate status when a payment is created but not yet
                        // resolved: pending while it waits, redirect when the customer
                        // is sent offsite, processing while the gateway settles. Stripe's
                        // initial "requires_payment_method" surfaces here as one of these,
                        // and it is the normal starting state of every checkout, not a
                        // failure. Only a terminal success or failure is a real outcome,
                        // so anything else is neither an attempt nor a processed payment.
                        if ($status !== TransactionRecord::STATUS_SUCCESS
                            && $status !== TransactionRecord::STATUS_FAILED) {
                            return;
                        }

                        $success = $status === TransactionRecord::STATUS_SUCCESS;

                        // a successful first try gets no attempt entry, only failures do
                        if (!$success) {
                            $logger->log($e->order, EventType::PAYMENT_ATTEMPT, [
                                'transactionType' => $e->transaction->type,
                            ]);
                        }

                        $logger->log($e->order, EventType::PAYMENT_PROCESSED, [
                            'success' => $success,
                            'transactionId' => $e->transaction->id,
                            'gatewayName' => $e->transaction->getGateway()?->name,
                            'amount' => $e->transaction->amount,
                            'currency' => $e->transaction->currency,
                        ]);
                    });
                }
            );
        }

        if ($settings->logPaymentCaptured) {
            Event::on(Payments::class, Payments::EVENT_AFTER_CAPTURE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    // $e->transaction is the capture Commerce just made, which
                    // comes back failed when the gateway turns it down.
                    self::_logSafely(static function() use ($logger, $e) {
                        $capture = $e->transaction;
                        $logger->log($capture->getOrder(), EventType::PAYMENT_CAPTURED, [
                            'transactionId' => $capture->id,
                            'type' => $capture->type,
                            'status' => $capture->status,
                            'success' => $capture->status === TransactionRecord::STATUS_SUCCESS,
                            'amount' => $capture->paymentAmount,
                            'currency' => $capture->paymentCurrency,
                            'gatewayName' => $capture->getGateway()?->name,
                        ]);
                    });
                }
            );
        }

        if ($settings->logPaymentRefunded) {
            Event::on(Payments::class, Payments::EVENT_AFTER_REFUND_TRANSACTION,
                static function(RefundTransactionEvent $e) use ($logger) {
                    // Commerce fires this for a refund the gateway declined or
                    // that threw, too: the refund transaction says which.
                    self::_logSafely(static function() use ($logger, $e) {
                        $refund = $e->refundTransaction;
                        $logger->log($e->transaction->getOrder(), EventType::PAYMENT_REFUNDED, [
                            'transactionId' => $refund->id,
                            'parentId' => $e->transaction->id,
                            'type' => $refund->type,
                            'status' => $refund->status,
                            'success' => $refund->status === TransactionRecord::STATUS_SUCCESS,
                            'amount' => $refund->paymentAmount,
                            'currency' => $refund->paymentCurrency,
                            'gatewayName' => $refund->getGateway()?->name,
                            'note' => $refund->note,
                        ]);
                    });
                }
            );
        }

        if ($settings->logPaymentTransactions) {
            Event::on(Transactions::class, Transactions::EVENT_AFTER_SAVE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    self::_logSafely(static function() use ($logger, $e) {
                        $logger->logTransaction($e->transaction);
                    });
                }
            );
        }
    }

    /**
     * Runs a logging callback in isolation so a failure inside it can never
     * propagate into the Commerce operation it fires from.
     *
     * Every listener that logs as a side effect of another operation routes
     * through here. The payment and transaction listeners are the sharpest
     * case: several fire within the try/catch in
     * {@see \craft\commerce\services\Payments::processPayment()} that turns any
     * caught exception into a PaymentException and reports the payment as
     * failed. The gateway charge can already have gone through by the time the
     * event fires, so letting a logging error (a DB deadlock, a snapshot build
     * problem, a queue push failure) escape would make a successful payment
     * look failed and invite the shopper to pay again, taking a second charge;
     * the same holds for a capture or refund that has already moved money. The
     * email listeners have the equivalent problem one layer out: they run
     * inside Commerce's SendEmailJob, where an escaping error fails the job and
     * has Commerce resend the mail on retry, and inside the queue's own
     * after-error handler, where it would derail the queue worker. Logging is a
     * side effect and must never affect the operation it observes, so any
     * throwable is logged and swallowed here.
     *
     * @param callable $fn The logging work to run.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _logSafely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            // A deadlock has already rolled back the transaction the order was
            // being saved in, so carrying on would report a save that didn't
            // happen. It goes back to Commerce, which fails the save as it
            // would for any other deadlock.
            if (OrderLifecycleLogger::isDeadlock($e)) {
                throw $e;
            }

            // A database error's message repeats the statement with its values
            // filled in, which here is an order snapshot full of customer
            // details, so only its error codes are logged. The class and stack
            // trace stay: if logging fails systematically every timeline gains
            // gaps and this line is the only signal.
            $detail = $e instanceof DbException
                ? 'SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ' (' . ($e->errorInfo[1] ?? '?') . ')'
                : $e->getMessage();

            Craft::error(
                'Failed to log an order lifecycle event: ' . $e::class . ': ' . $detail
                . "\n" . $e->getTraceAsString(),
                'order-lifecycle',
            );
        }
    }

    /**
     * Registers email send/failure listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerEmailListeners(OrderLifecycleLogger $logger): void
    {
        // these run inside Commerce's SendEmailJob::execute(), so they use
        // logDeferred() instead of log() - building a full snapshot inline
        // eats into the job's time-to-reserve, and if it runs out Commerce
        // resends the email on retry. routed through _logSafely() too, so a
        // logging failure here can never take the actual send down with it.
        // A send another plugin stops before it goes (isValid = false) is a
        // cancellation to Commerce, not a failure, so it isn't logged as one.
        Event::on(Emails::class, Emails::EVENT_AFTER_SEND_MAIL,
            static function(MailEvent $e) use ($logger) {
                self::_logSafely(static function() use ($logger, $e) {
                    $logger->logDeferred((int)$e->order->id, EventType::EMAIL_SENT, [
                        // craftEmail->name isn't populated by Commerce - use commerceEmail
                        'name' => $e->commerceEmail->name ?? null,
                        'subject' => $e->craftEmail->subject ?? null,
                        'to' => $e->craftEmail->to ?? null,
                    ]);
                });
            }
        );

        // Intercept Commerce SendEmail queue job failures. Fires from the
        // queue's own after-error handler, so a logging failure here must not
        // escape and derail the worker - _logSafely() keeps it contained.
        Event::on(Queue::class, QueueAlias::EVENT_AFTER_ERROR,
            static function(ExecEvent $e) use ($logger) {
                if (!$e->job instanceof SendEmailJob) {
                    return;
                }

                self::_logSafely(static function() use ($logger, $e) {
                    /** @var SendEmailJob $job */
                    $job = $e->job;
                    $logger->logFailedEmailJob(
                        $job->orderId ?? null,
                        $job->commerceEmailId ?? null,
                        $e->attempt,
                        $e->id ?? null,
                        $e->error,
                    );
                });
            }
        );
    }

    /**
     * Hooks into Craft's garbage collection to remove logs for deleted orders.
     *
     * Acts as a safety net alongside the EVENT_AFTER_DELETE listener - catches
     * anything that slipped through (bulk operations, direct DB changes, etc.).
     * Craft can run garbage collection at the start of an ordinary web request,
     * so there the sweep is handed to the queue; `craft gc` does it in place.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function(Event $e) {
            /** @var Gc $gc */
            $gc = $e->sender;
            $isConsole = Craft::$app instanceof ConsoleApplication;
            $verbose = !$gc->silent && $isConsole;

            try {
                if (!$isConsole) {
                    Craft::$app->getQueue()->push(new PruneLogs(['orphaned' => true]));

                    return;
                }

                if ($verbose) {
                    Console::stdout('    > deleting orphaned order lifecycle logs ... ');
                }

                $this->getLogger()->deleteOrphanedLogs();

                if ($verbose) {
                    Console::stdout("done\n", Console::FG_GREEN);
                }
            } catch (Throwable $exception) {
                Craft::warning('Deleting orphaned order lifecycle logs failed: ' . $exception->getMessage(), 'order-lifecycle');
            }
        });
    }

    /**
     * Queues the daily prune of old logs, when a retention period is set.
     *
     * Checked at the end of a request, but the deleting itself runs on the
     * queue in batches, so it never holds up the request that noticed it.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _setupAutoPrune(): void
    {
        $days = $this->getSettings()->autoPruneLogs;

        if ($days <= 0) {
            return;
        }

        Event::on(
            \craft\web\Application::class,
            Application::EVENT_AFTER_REQUEST,
            static function() use ($days) {
                // add() only succeeds for the first request to get here in a
                // day, so the job is queued once however busy the site is
                if (!Craft::$app->getCache()->add('orderlifecycle_last_auto_prune', time(), 86400)) {
                    return;
                }

                try {
                    Craft::$app->getQueue()->push(new PruneLogs(['days' => $days]));
                } catch (Throwable $e) {
                    Craft::warning('Queuing the order lifecycle log prune failed: ' . $e->getMessage(), 'order-lifecycle');
                }
            }
        );
    }
}
