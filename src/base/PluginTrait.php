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
use craft\commerce\services\Emails;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments;
use craft\commerce\services\Transactions;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\queue\Queue;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Exception;
use johnhenry\orderlifecycle\enums\EventType;
use johnhenry\orderlifecycle\fields\OrderLifecycleField;
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
use yii\queue\ExecEvent;
use yii\queue\Queue as QueueAlias;

/**
 * Wires the plugin's event listeners, CP URL rules and lifecycle overrides.
 *
 * Keeps the main plugin class a thin shell: event listeners defined here only
 * wire Commerce/Craft events to logger service methods and never implement
 * domain logic inline.
 *
 * @author John Henry Donovan
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('order-lifecycle', 'Order Lifecycle');

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission('order-lifecycle:accessDashboard')) {
            $subnav['overview'] = ['label' => Craft::t('order-lifecycle', 'Overview'), 'url' => 'order-lifecycle'];
        }

        if (OrderLifecycle::$plugin->settings->getAnthropicApiKey() && $user->checkPermission('order-lifecycle:generateInsights')) {
            $subnav['insights'] = ['label' => Craft::t('order-lifecycle', 'AI Insights'), 'url' => 'order-lifecycle/insights'];
        }

        if ($user->checkPermission('order-lifecycle:exportEvents')) {
            $subnav['export'] = ['label' => Craft::t('order-lifecycle', 'Export'), 'url' => 'order-lifecycle/export'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'order-lifecycle/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
                        'order-lifecycle:accessDashboard' => [
                            'label' => Craft::t('order-lifecycle', 'Access dashboard'),
                        ],
                        'order-lifecycle:exportEvents' => [
                            'label' => Craft::t('order-lifecycle', 'Export lifecycle events'),
                        ],
                        'order-lifecycle:generateInsights' => [
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
     * @author John Henry Donovan
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
                    'order-lifecycle/ai/store-insights/status' => 'order-lifecycle/ai/store-insights-status',
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
    }

    /**
     * Registers order save/delete listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerOrderSaveListeners(OrderLifecycleLogger $logger): void
    {
        // Remove logs when an order is deleted so they don't skew stats.
        Event::on(Order::class, Element::EVENT_AFTER_DELETE, static function($e) use ($logger) {
            /** @var Order $order */
            $order = $e->sender;
            if ($order->id) {
                $logger->deleteLogsForOrder((int)$order->id);
            }
        });

        // Cart created/updated + granular change detection.
        Event::on(Order::class, Element::EVENT_AFTER_SAVE, static function($e) use ($logger) {
            /** @var Order $order */
            $order = $e->sender;
            $logger->logOrderSaveChanges($order, (bool)$e->isNew);
        });
    }

    /**
     * Registers line-item add/remove listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerLineItemListeners(OrderLifecycleLogger $logger): void
    {
        Event::on(Order::class, Order::EVENT_AFTER_APPLY_ADD_LINE_ITEM,
            static function(LineItemEvent $e) use ($logger) {
                $li = $e->lineItem;
                $logger->log($li->getOrder(), EventType::LINE_ITEM_ADDED, [
                    'sku' => $li->sku, 'qty' => $li->qty, 'subtotal' => $li->getSubtotal(),
                ]);
            }
        );

        Event::on(Order::class, Order::EVENT_AFTER_APPLY_REMOVE_LINE_ITEM,
            static function(LineItemEvent $e) use ($logger) {
                $li = $e->lineItem;
                $logger->log($li->getOrder(), EventType::LINE_ITEM_REMOVED, [
                    'sku' => $li->sku, 'qty' => $li->qty,
                ]);
            }
        );
    }

    /**
     * Registers the order status change listener.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerStatusListener(OrderLifecycleLogger $logger): void
    {
        Event::on(OrderHistories::class, OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(OrderStatusEvent $e) use ($logger) {
                $order = $e->order;
                $logger->log($order, EventType::STATUS_CHANGED, [
                    'oldStatusId' => $e->orderHistory->prevStatusId ?? null,
                    'newStatusId' => $order->orderStatusId,
                ]);
            }
        );
    }

    /**
     * Registers order completion / paid / authorized listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @param SettingsModel $settings The plugin settings.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerCompletionListeners(OrderLifecycleLogger $logger, SettingsModel $settings): void
    {
        if ($settings->logOrderComplete) {
            Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER,
                static function($e) use ($logger) {
                    /** @var Order $order */
                    $order = $e->sender;
                    $logger->log($order, EventType::ORDER_COMPLETED, [
                        'totalPrice' => $order->getTotalPrice(), 'currency' => $order->currency,
                    ]);
                }
            );
        }

        if ($settings->logOrderPaid) {
            Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID,
                static function($e) use ($logger) {
                    /** @var Order $order */
                    $order = $e->sender;
                    $lastTransaction = $order->getLastTransaction();
                    $logger->log($order, EventType::ORDER_PAID, [
                        'totalPaid' => $order->getTotalPaid(),
                        'currency' => $order->currency,
                        'gatewayName' => $lastTransaction?->getGateway()?->name,
                    ]);
                }
            );
        }

        if ($settings->logPaymentAuthorized) {
            Event::on(Order::class, Order::EVENT_AFTER_ORDER_AUTHORIZED,
                static function($e) use ($logger) {
                    /** @var Order $order */
                    $order = $e->sender;
                    $logger->log($order, EventType::PAYMENT_AUTHORIZED, [
                        'totalAuthorized' => $order->getTotalPaid(),
                    ]);
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
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerPaymentListeners(OrderLifecycleLogger $logger, SettingsModel $settings): void
    {
        if ($settings->logPaymentAttempts) {
            Event::on(Payments::class, Payments::EVENT_AFTER_PROCESS_PAYMENT,
                static function(ProcessPaymentEvent $e) use ($logger) {
                    $success = $e->transaction->status === 'success';

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
                }
            );
        }

        if ($settings->logPaymentCaptured) {
            Event::on(Payments::class, Payments::EVENT_AFTER_CAPTURE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    $logger->log($e->transaction->getOrder(), EventType::PAYMENT_CAPTURED, [
                        'transactionId' => $e->transaction->id,
                        'type' => $e->transaction->type,
                        'amount' => $e->transaction->amount,
                    ]);
                }
            );
        }

        if ($settings->logPaymentRefunded) {
            Event::on(Payments::class, Payments::EVENT_AFTER_REFUND_TRANSACTION,
                static function(RefundTransactionEvent $e) use ($logger) {
                    $logger->log($e->transaction->getOrder(), EventType::PAYMENT_REFUNDED, [
                        'transactionId' => $e->transaction->id,
                        'parentId' => $e->transaction->parentId,
                        'type' => $e->transaction->type,
                        'amount' => $e->amount,
                        'currency' => $e->transaction->currency,
                        'gatewayName' => $e->transaction->getGateway()?->name,
                        'note' => $e->refundTransaction->note,
                    ]);
                }
            );
        }

        if ($settings->logPaymentTransactions) {
            Event::on(Transactions::class, Transactions::EVENT_AFTER_SAVE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    $logger->logTransaction($e->transaction);
                }
            );
        }
    }

    /**
     * Registers email send/failure listeners.
     *
     * @param OrderLifecycleLogger $logger The logger service.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerEmailListeners(OrderLifecycleLogger $logger): void
    {
        // these run inside Commerce's SendEmailJob::execute(), so they use
        // logDeferred() instead of log() - building a full snapshot inline
        // eats into the job's time-to-reserve, and if it runs out Commerce
        // resends the email on retry. wrapped in try/catch too, so a logging
        // failure here can never take the actual send down with it
        Event::on(Emails::class, Emails::EVENT_BEFORE_SEND_MAIL,
            static function(MailEvent $e) use ($logger) {
                if ($e->isValid === false) {
                    try {
                        $logger->logDeferred((int)$e->order->id, EventType::EMAIL_FAILED, [
                            // craftEmail->name isn't populated by Commerce - use commerceEmail
                            'name' => $e->commerceEmail->name ?? null,
                            'subject' => $e->craftEmail->subject ?? null,
                            'to' => $e->craftEmail->to ?? null,
                            'reason' => 'Email sending was prevented (isValid = false)',
                        ], 'Email sending was prevented');
                    } catch (Throwable $exception) {
                        Craft::error('Failed to queue EMAIL_FAILED log: ' . $exception->getMessage(), 'order-lifecycle');
                    }
                }
            }
        );

        Event::on(Emails::class, Emails::EVENT_AFTER_SEND_MAIL,
            static function(MailEvent $e) use ($logger) {
                try {
                    $logger->logDeferred((int)$e->order->id, EventType::EMAIL_SENT, [
                        // craftEmail->name isn't populated by Commerce - use commerceEmail
                        'name' => $e->commerceEmail->name ?? null,
                        'subject' => $e->craftEmail->subject ?? null,
                        'to' => $e->craftEmail->to ?? null,
                    ]);
                } catch (Throwable $exception) {
                    Craft::error('Failed to queue EMAIL_SENT log: ' . $exception->getMessage(), 'order-lifecycle');
                }
            }
        );

        // Intercept Commerce SendEmail queue job failures.
        Event::on(Queue::class, QueueAlias::EVENT_AFTER_ERROR,
            static function(ExecEvent $e) use ($logger) {
                if (!$e->job instanceof SendEmailJob) {
                    return;
                }

                $logger->logFailedEmailJob(
                    $e->job->orderId ?? null,
                    $e->job->emailId ?? null,
                    $e->job->number ?? null,
                    $e->job->reference ?? null,
                    $e->attempt,
                    $e->id ?? null,
                    $e->error,
                );
            }
        );
    }

    /**
     * Hooks into Craft's garbage collection to remove logs for deleted orders.
     *
     * Acts as a safety net alongside the EVENT_AFTER_DELETE listener - catches
     * anything that slipped through (bulk operations, direct DB changes, etc.).
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function(Event $e) {
            /** @var Gc $gc */
            $gc = $e->sender;
            $verbose = !$gc->silent && Craft::$app instanceof ConsoleApplication;

            if ($verbose) {
                Console::stdout('    > deleting orphaned order lifecycle logs ... ');
            }

            $this->getLogger()->deleteOrphanedLogs();

            if ($verbose) {
                Console::stdout("done\n", Console::FG_GREEN);
            }
        });
    }

    /**
     * Sets up automatic log pruning on the after-request event.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _setupAutoPrune(): void
    {
        /** @var SettingsModel $settings */
        $settings = $this->getSettings();

        if ($settings->autoPruneLogs <= 0) {
            return;
        }

        Event::on(
            \craft\web\Application::class,
            Application::EVENT_AFTER_REQUEST,
            function() use ($settings) {
                $this->_maybeAutoPruneLogs($settings->autoPruneLogs);
            }
        );
    }

    /**
     * Auto-prunes logs if it has been more than a day since the last prune.
     *
     * @param int $days The age threshold in days; logs older than this are deleted.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _maybeAutoPruneLogs(int $days): void
    {
        $cacheKey = 'orderlifecycle_last_auto_prune';
        $cache = Craft::$app->getCache();
        $lastPrune = $cache->get($cacheKey);

        // Only run once per day.
        if ($lastPrune && (time() - $lastPrune) < 86400) {
            return;
        }

        $cache->set($cacheKey, time(), 86400 * 2);

        try {
            $cutoffDate = DateTimeHelper::toDateTime('-' . $days . ' days');

            $deleted = Craft::$app->getDb()->createCommand()
                ->delete('{{%orderlifecycle_logs}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                ->execute();

            if ($deleted > 0) {
                Craft::info("Auto-pruned $deleted log(s) older than $days days", __METHOD__);
            }
        } catch (Exception $e) {
            Craft::warning('Auto-prune logs failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}
