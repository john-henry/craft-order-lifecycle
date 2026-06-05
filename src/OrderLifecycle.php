<?php

/**
 * Order Lifecycle plugin for Craft CMS 5.
 *
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\events\MailEvent;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\events\RefundTransactionEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\Plugin;
use craft\commerce\queue\jobs\SendEmail as SendEmailJob;
use craft\commerce\services\Emails;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments;
use craft\commerce\services\Transactions;

use craft\console\Application as ConsoleApplication;
use craft\db\Query;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
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
use johnhenry\orderlifecycle\helpers\Formatter;

use johnhenry\orderlifecycle\models\SettingsModel;
use johnhenry\orderlifecycle\services\OrderLifecycleLogger;
use johnhenry\orderlifecycle\services\StatsService;
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
 * Order Lifecycle plugin.
 *
 * Records granular Commerce order lifecycle events (cart creation, line item
 * changes, payments, emails, status changes) and surfaces them through a CP
 * dashboard, an order field, dashboard widgets and CSV export.
 *
 * @property-read OrderLifecycleLogger $logger
 * @property-read StatsService $stats
 * @property-read SettingsModel $settings
 * @author John Henry Donovan
 * @since 1.0.0
 */
class OrderLifecycle extends BasePlugin
{
    // =========================================================================
    // Static Properties
    // =========================================================================

    /**
     * @var OrderLifecycle The plugin instance.
     */
    public static OrderLifecycle $plugin;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin configuration, including registered service components.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'logger' => OrderLifecycleLogger::class,
                'stats' => StatsService::class,
            ],
        ];
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @throws InvalidConfigException
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->registerFieldTypes();
        $this->registerTwigVariable();
        $this->registerListeners();
        $this->registerGarbageCollection();

        $this->setupAutoPrune();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpUrlRules();
            $this->registerWidgets();
            $this->registerPermissions();
        }
    }

    /**
     * Returns the logger service.
     *
     * @return OrderLifecycleLogger The logger service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLogger(): OrderLifecycleLogger
    {
        $component = $this->get('logger');
        assert($component instanceof OrderLifecycleLogger);

        return $component;
    }

    /**
     * Returns the stats service.
     *
     * @return StatsService The stats service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStats(): StatsService
    {
        $component = $this->get('stats');
        assert($component instanceof StatsService);

        return $component;
    }

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

        if ($this->settings->getAnthropicApiKey() && $user->checkPermission('order-lifecycle:generateInsights')) {
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
     * Registers plugin permissions.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
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
     * Registers the Twig variable for use in templates.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('orderLifecycle', OrderLifecycleVariable::class);
            }
        );
    }


    /**
     * Registers the plugin's field types.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = OrderLifecycleField::class;
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
    private function registerCpUrlRules(): void
    {
        // CP route for full-page lifecycle view
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Merge so that settings controller action comes first (important!)
                $event->rules = array_merge([
                    'settings/plugins/order-lifecycle' => 'order-lifecycle/settings/edit',
                    'order-lifecycle/settings' => 'order-lifecycle/settings/edit',
                    'order-lifecycle/export/csv' => 'order-lifecycle/export/csv',
                    'order-lifecycle/ai/insights' => 'order-lifecycle/ai/insights',
                    'order-lifecycle/ai/store-insights' => 'order-lifecycle/ai/store-insights',
                    'order-lifecycle' => 'order-lifecycle/dashboard/index',
                    'order-lifecycle/insights' => 'order-lifecycle/dashboard/insights',
                    'order-lifecycle/export' => 'order-lifecycle/dashboard/export',
                ],
                    $event->rules
                );
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
    private function registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = OrderLifecycleStatsWidget::class;
                $event->types[] = AiInsightsWidget::class;
            }
        );
    }

    /**
     * Registers the Commerce order lifecycle event listeners.
     *
     * @return void
     * @throws InvalidConfigException If the logger component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function registerListeners(): void
    {
        $logger = $this->getLogger();
        /** @var SettingsModel $settings */
        $settings = $this->getSettings();

        // When an order is deleted (purged inactive carts, manual delete, trash emptied),
        // remove its logs so they don't skew stats.
        Event::on(Order::class, Element::EVENT_AFTER_DELETE, static function($e) {
            /** @var Order $order */
            $order = $e->sender;
            if ($order->id) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%orderlifecycle_logs}}', ['orderId' => $order->id])
                    ->execute();
            }
        });

        // Base: cart created/updated
        Event::on(Order::class, Element::EVENT_AFTER_SAVE, function($e) use ($logger, $settings) {
            /** @var Order $order */
            $order = $e->sender;

            // Always log cart creation
            if ($e->isNew) {
                $logger->log($order, EventType::CART_CREATED);
            }

            // Derived granular changes via last snapshot diff (coupon/addresses/shipping)
            $prev = $logger->getLastSnapshot((int)$order->id);
            if ($prev) {
                if ($settings->logCouponChanges) {
                    $before = $prev['order']['couponCode'] ?? null;
                    $after = $order->couponCode ?: null;
                    if ($before !== $after) {
                        $logger->log($order, $after ? EventType::COUPON_APPLIED : EventType::COUPON_REMOVED, compact('before', 'after'));
                    }
                }
                if ($settings->logAddressChanges) {
                    $pairs = [
                        ['shipping', 'getShippingAddress', EventType::SHIPPING_ADDRESS_SET, EventType::SHIPPING_ADDRESS_REMOVED],
                        ['billing', 'getBillingAddress', EventType::BILLING_ADDRESS_SET, EventType::BILLING_ADDRESS_REMOVED],
                    ];
                    foreach ($pairs as [$key, $getter, $eventTypeSet, $eventTypeRemoved]) {
                        $prevData = $prev['addresses'][$key] ?? null;
                        $currAddress = $order->$getter();
                        $currData = $currAddress ? [
                            'firstName' => $currAddress->firstName,
                            'lastName' => $currAddress->lastName,
                            'addressLine1' => $currAddress->addressLine1,
                            'addressLine2' => $currAddress->addressLine2,
                            'locality' => $currAddress->locality,
                            'administrativeArea' => $currAddress->administrativeArea,
                            'postalCode' => $currAddress->postalCode,
                            'countryCode' => $currAddress->countryCode,
                        ] : null;

                        if ($prevData !== $currData) {
                            $eventType = $currData ? $eventTypeSet : $eventTypeRemoved;
                            $message = Formatter::addressChange($prevData, $currData, $key);
                            $logger->log($order, $eventType, ['before' => $prevData, 'after' => $currData], $message);
                        }
                    }
                }

                if ($settings->logCustomerChanges) {
                    $before = $prev['customer']['email'] ?? null;
                    $after = $order->email ?: null;

                    // Normalize empty strings to null for comparison
                    $before = $before ?: null;
                    $after = $after ?: null;

                    // Only log if there's an actual change
                    if ($before !== $after) {
                        // Determine if it's a guest or registered customer
                        $customer = $order->getCustomer();
                        $customerType = $customer ? 'customer' : 'guest';
                        $customerId = $customer?->id;

                        $logger->log($order, $after ? EventType::CUSTOMER_SET : EventType::CUSTOMER_REMOVED, compact('before', 'after', 'customerType', 'customerId'));
                    }
                }

                if ($settings->logShippingMethodChanges) {
                    $before = $prev['order']['shippingMethodHandle'] ?? null;
                    $after = $order->shippingMethodHandle;
                    if ($before !== $after) {
                        $logger->log($order, EventType::SHIPPING_METHOD_SET, compact('before', 'after'));
                    }
                }

                // Check for line item quantity changes
                if ($settings->logLineItems && isset($prev['lineItems'])) {
                    $prevLineItems = $prev['lineItems'];
                    $currentLineItems = [];

                    // Index current line items by SKU
                    foreach ($order->getLineItems() as $lineItem) {
                        $currentLineItems[$lineItem->sku] = $lineItem;
                    }

                    // Check for quantity changes
                    foreach ($prevLineItems as $prevItem) {
                        $sku = $prevItem['sku'] ?? null;
                        $prevQty = $prevItem['qty'] ?? 0;

                        if ($sku && isset($currentLineItems[$sku])) {
                            $currentQty = $currentLineItems[$sku]->qty;

                            if ($prevQty !== $currentQty) {
                                $li = $currentLineItems[$sku];
                                $logger->log($order, EventType::LINE_ITEM_UPDATED, [
                                    'sku' => $li->sku,
                                    'qtyBefore' => $prevQty,
                                    'qtyAfter' => $currentQty,
                                    'subtotal' => $li->getSubtotal(),
                                ]);
                            }
                        }
                    }
                }
            } elseif (!$e->isNew) {
                // No previous snapshot but not new - log generic update
                $logger->log($order, EventType::CART_UPDATED);
            }
        });

        // Line items
        if ($settings->logLineItems) {
            Event::on(Order::class, Order::EVENT_AFTER_APPLY_ADD_LINE_ITEM,
                static function(LineItemEvent $e) use ($logger) {
                    $li = $e->lineItem;
                    $order = $li->getOrder();
                    $logger->log($order, EventType::LINE_ITEM_ADDED, [
                        'sku' => $li->sku, 'qty' => $li->qty, 'subtotal' => $li->getSubtotal(),
                    ]);
                }
            );
            Event::on(Order::class, Order::EVENT_AFTER_APPLY_REMOVE_LINE_ITEM,
                static function(LineItemEvent $e) use ($logger) {
                    $li = $e->lineItem;
                    $order = $li->getOrder();
                    $logger->log($order, EventType::LINE_ITEM_REMOVED, [
                        'sku' => $li->sku, 'qty' => $li->qty,
                    ]);
                }
            );
        }

        // Status changes
        if ($settings->logStatusChanges) {
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

        // Completion & paid/authorized
        if ($settings->logOrderComplete) {
            Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER,
                static function($e) use ($logger) {
                    /** @var Order $order */ $order = $e->sender;
                    $logger->log($order, EventType::ORDER_COMPLETED, [
                        'totalPrice' => $order->getTotalPrice(), 'currency' => $order->currency,
                    ]);
                }
            );
        }

        if ($settings->logOrderPaid) {
            Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID,
                static function($e) use ($logger) {
                    /** @var Order $order */ $order = $e->sender;
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
                    /** @var Order $order */ $order = $e->sender;
                    $logger->log($order, EventType::PAYMENT_AUTHORIZED, [
                        'totalAuthorized' => $order->getTotalPaid(), // best available aggregate
                    ]);
                }
            );
        }

        // Payment lifecycle via Payments service

        if ($settings->logPaymentAttempts) {
            Event::on(Payments::class, Payments::EVENT_AFTER_PROCESS_PAYMENT,
                static function(ProcessPaymentEvent $e) use ($logger) {
                    $success = $e->transaction->status === 'success';

                    // Only log a payment attempt when it failed — successful first-try
                    // payments produce no attempt entry, retries leave a trail.
                    if (!$success) {
                        $logger->log($e->order, EventType::PAYMENT_ATTEMPT, [
                            'transactionType' => $e->transaction->type,
                        ]);
                    }

                    $logger->log($e->order, EventType::PAYMENT_PROCESSED, [
                        'success' => $success,
                        'transactionId' => $e->transaction->id,
                    ]);
                }
            );
        }
        if ($settings->logPaymentCaptured) {
            Event::on(Payments::class, Payments::EVENT_AFTER_CAPTURE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    $order = $e->transaction->getOrder();
                    $logger->log($order, EventType::PAYMENT_CAPTURED, [
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
                    $order = $e->transaction->getOrder();
                    $logger->log($order, EventType::PAYMENT_REFUNDED, [
                        'transactionId' => $e->transaction->id,
                        'parentId' => $e->transaction->parentId,
                        'type' => $e->transaction->type,
                        'amount' => $e->amount,
                    ]);
                }
            );
        }
        // Log all transaction state changes (including errors/failures)
        if ($settings->logPaymentTransactions) {
            Event::on(Transactions::class, Transactions::EVENT_AFTER_SAVE_TRANSACTION,
                static function(TransactionEvent $e) use ($logger) {
                    $transaction = $e->transaction;
                    $order = $transaction->getOrder();

                    // Build log data
                    $logData = [
                        'transactionId' => $transaction->id,
                        'type' => $transaction->type,
                        'status' => $transaction->status,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'gateway' => $transaction->gateway->name,
                        'reference' => $transaction->reference,
                    ];

                    // Capture error information if transaction failed
                    $message = null;
                    if ($transaction->status === 'failed') {
                        $logData['code'] = $transaction->code;
                        $logData['message'] = $transaction->message;
                        $message = "Payment $transaction->type failed: $transaction->message";
                    } elseif ($transaction->status === 'success') {
                        $message = "Payment $transaction->type succeeded";
                    }

                    $logger->log($order, EventType::PAYMENT_TRANSACTION, $logData, $message);
                }
            );
        }


        // Email sending
        if ($settings->logEmailSent) {
            Event::on(Emails::class, Emails::EVENT_BEFORE_SEND_MAIL,
                static function(MailEvent $e) use ($logger) {
                    // Check if email sending was prevented
                    if ($e->isValid === false) {
                        $logger->log($e->order, EventType::EMAIL_FAILED, [
                            'name' => $e->craftEmail->name ?? null,
                            'subject' => $e->craftEmail->subject ?? null,
                            'to' => $e->craftEmail->to ?? null,
                            'reason' => 'Email sending was prevented (isValid = false)',
                        ], 'Email sending was prevented');
                    }
                }
            );

            Event::on(Emails::class, Emails::EVENT_AFTER_SEND_MAIL,
                static function(MailEvent $e) use ($logger) {
                    try {
                        $order = $e->order;
                        $logger->log($order, EventType::EMAIL_SENT, [
                            'name' => $e->craftEmail->name ?? null,
                            'subject' => $e->craftEmail->subject ?? null,
                            'to' => $e->craftEmail->to ?? null,
                        ]);
                    } catch (Throwable $exception) {
                        // Log the error if we can still access the order
                        if (isset($e->order)) {
                            $logger->log($e->order, EventType::EMAIL_FAILED, [
                                'name' => $e->craftEmail->name ?? null,
                                'subject' => $e->craftEmail->subject ?? null,
                                'error' => $exception->getMessage(),
                                'errorClass' => get_class($exception),
                            ], 'Email failed: ' . $exception->getMessage());
                        }
                    }
                }
            );

            // Intercept queue job failures for emails
            Event::on(Queue::class, QueueAlias::EVENT_AFTER_ERROR,
                static function(ExecEvent $e) use ($logger) {
                    // Check if the failed job is a SendEmail job (Craft Commerce)
                    $jobClass = $e->job ? get_class($e->job) : null;
                    if ($jobClass === SendEmailJob::class) {
                        // Access public properties of the job
                        $orderId = $e->job->orderId ?? null;
                        $emailId = $e->job->emailId ?? null;
                        $orderNumber = $e->job->number ?? null;
                        $orderReference = $e->job->reference ?? null;

                        // Try to get order from ID first, fall back to number/reference if needed
                        $order = null;
                        if ($orderId) {
                            $order = Order::findOne($orderId);
                        }

                        // If order not found by ID but we have number, try that
                        if (!$order && $orderNumber) {
                            $order = Order::find()
                                ->number($orderNumber)
                                ->one();
                        }

                        if ($order) {
                            // Get email name if possible
                            $emailName = null;
                            if ($emailId) {
                                $email = Plugin::getInstance()
                                    ->getEmails()
                                    ->getEmailById($emailId);
                                $emailName = $email->name ?? null;
                            }

                            $attemptNumber = $e->attempt;

                            // Check if we already have a log entry for this email failure
                            $existingLog = $logger->getLastLogForOrderAndType($order->id, EventType::EMAIL_FAILED);

                            // Check if it's the same email (within last 5 minutes to group retries)
                            $isSameEmail = false;
                            if ($existingLog) {
                                $existingSnapshot = Json::decodeIfJson($existingLog['snapshot']);
                                $existingEmailId = $existingSnapshot['payload']['emailId'] ?? null;
                                $timeDiff = time() - strtotime($existingLog['dateCreated']);
                                $isSameEmail = ($existingEmailId === $emailId && $timeDiff < 300); // 5 minutes
                            }

                            if ($isSameEmail && $existingLog) {
                                // Update existing log with new attempt count
                                $existingSnapshot = Json::decodeIfJson($existingLog['snapshot']);
                                $existingSnapshot['payload']['attempt'] = $attemptNumber;
                                $existingSnapshot['payload']['lastError'] = $e->error?->getMessage();
                                $existingSnapshot['payload']['lastErrorClass'] = $e->error ? get_class($e->error) : null;

                                $message = "Email queue job failed after $attemptNumber attempt(s): " . ($e->error?->getMessage() ?? 'Unknown error');

                                $logger->updateLog($existingLog['id'], [
                                    'message' => $message,
                                    'snapshot' => Json::encode($existingSnapshot),
                                ]);
                            } else {
                                // Create new log entry
                                $errorMsg = $e->error?->getMessage() ?? 'Unknown error';
                                $message = $attemptNumber > 1
                                    ? "Email queue job failed after $attemptNumber attempt(s): $errorMsg"
                                    : "Email queue job failed: $errorMsg";

                                $logger->log($order, EventType::EMAIL_FAILED, [
                                    'emailId' => $emailId,
                                    'emailName' => $emailName,
                                    'orderNumber' => $orderNumber,
                                    'orderReference' => $orderReference,
                                    'error' => $errorMsg,
                                    'errorClass' => $e->error ? get_class($e->error) : null,
                                    'attempt' => $attemptNumber,
                                    'jobId' => $e->id ?? null,
                                ], $message);
                            }
                        }
                    }
                }
            );
        }
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
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, static function(Event $e) {
            /** @var Gc $gc */
            $gc = $e->sender;
            $verbose = !$gc->silent && Craft::$app instanceof ConsoleApplication;

            if ($verbose) {
                Console::stdout('    > deleting orphaned order lifecycle logs ... ');
            }

            $orphanedIds = (new Query())
                ->select('orderId')
                ->from([
                    'tmp' => (new Query())
                        ->select('[[l.orderId]]')
                        ->from('{{%orderlifecycle_logs}} l')
                        ->leftJoin('{{%elements}} e', '[[e.id]] = [[l.orderId]]')
                        ->where(['OR',
                            ['e.id' => null],
                            ['NOT', ['e.dateDeleted' => null]],
                        ]),
                ]);

            Craft::$app->getDb()->createCommand()
                ->delete('{{%orderlifecycle_logs}}', ['in', 'orderId', $orphanedIds])
                ->execute();

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
    private function setupAutoPrune(): void
    {
        /** @var SettingsModel $settings */
        $settings = $this->getSettings();

        if ($settings->autoPruneLogs > 0) {
            Event::on(
                \craft\web\Application::class,
                Application::EVENT_AFTER_REQUEST,
                function() use ($settings) {
                    $this->maybeAutoPruneLogs($settings->autoPruneLogs);
                }
            );
        }
    }

    /**
     * Auto-prunes logs if it has been more than a day since the last prune.
     *
     * @param int $days The age threshold in days; logs older than this are deleted.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function maybeAutoPruneLogs(int $days): void
    {
        $cacheKey = 'orderlifecycle_last_auto_prune';
        $cache = Craft::$app->getCache();
        $lastPrune = $cache->get($cacheKey);

        // Only run once per day
        if ($lastPrune && (time() - $lastPrune) < 86400) {
            return;
        }

        // Mark as run
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
            Craft::warning("Auto-prune logs failed: " . $e->getMessage(), __METHOD__);
        }
    }
}
