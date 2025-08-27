<?php
namespace HEC\HolestPay\Controller\Result;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use HEC\HolestPay\Model\Order\StatusManager;
use HEC\HolestPay\Model\ConfigurationManager;

class Webhook extends Action implements CsrfAwareActionInterface
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var StatusManager */
    private $statusManager;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var ConfigurationManager */
    private $configurationManager;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        StatusManager $statusManager,
        OrderFactory $orderFactory,
        ConfigurationManager $configurationManager
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->statusManager = $statusManager;
        $this->orderFactory = $orderFactory;
        $this->configurationManager = $configurationManager;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }

    /**
     * Log error message to system.log
     *
     * @param string $message
     * @param array $context
     */
    private function logError(string $message, array $context = [])
    {
        $logger = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class);
        
        $contextString = !empty($context) ? ' Context: ' . json_encode($context) : '';
        $logger->error('[HolestPay Webhook] ' . $message . $contextString);
    }

    /**
     * Log warning message to system.log
     *
     * @param string $message
     * @param array $context
     */
    private function logWarning(string $message, array $context = [])
    {
        $logger = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class);
        
        $contextString = !empty($context) ? ' Context: ' . json_encode($context) : '';
        $logger->warning('[HolestPay Webhook] ' . $message . $contextString);
    }


    //.../holestpay/result/webhook
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $topic = $this->getRequest()->getParam('topic');
            
            if ($topic === 'posconfig-updated') {
                $this->handlePosConfigUpdated();
                $result->setData(['received' => 'OK', 'accept_result' => 'POS_CONFIG_UPDATED']);
            } elseif ($topic === 'payresult' || $topic === 'orderupdate') {
                $this->handlePaymentResult($topic);
                $result->setData(['received' => 'OK', 'accept_result' => strtoupper($topic)]);
            } else {
                // Handle legacy webhook logic (fallback)
                $orderIncrementId = $this->getRequest()->getParam('order_increment_id');
                $hpayStatus = $this->getRequest()->getParam('hpay_status');

                if (!$orderIncrementId || !$hpayStatus) {
                    throw new \InvalidArgumentException('Missing parameters');
                }

                $order = $this->orderRepository->get($this->loadOrderIdByIncrementId($orderIncrementId));
                $this->statusManager->setHPayStatus($order, (string)$hpayStatus);

                $result->setData(['success' => true]);
            }
        } catch (\Throwable $e) {
            $result->setHttpResponseCode(400);
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
        return $result;
    }

    /**
     * Handle posconfig-updated webhook
     * Updates the configuration parameter with new POS configuration
     */
    private function handlePosConfigUpdated()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                $this->logError('handlePosConfigUpdated: Invalid webhook data - empty or malformed JSON');
                throw new \InvalidArgumentException('Invalid webhook data');
            }

            $this->logWarning('handlePosConfigUpdated: Processing webhook data', ['data_keys' => array_keys($data)]);

            // Get current configuration
            $scopeConfig = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
            
            $currentConfig = $scopeConfig->getValue('payment/holestpay/configuration', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $currentEnvironment = $scopeConfig->getValue('payment/holestpay/environment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $currentMerchantSiteUid = $scopeConfig->getValue('payment/holestpay/merchant_site_uid', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            
            // Get the secret key value
            $currentSecretKey = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
                ->getValue('payment/holestpay/secret_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            
            if (!$currentSecretKey) {
                $this->logError('handlePosConfigUpdated: Secret key not found in configuration');
                throw new \RuntimeException('Secret key not found in configuration');
            }
            
            $this->logWarning('handlePosConfigUpdated: Secret key retrieved successfully', [
                'secret_key_length' => strlen($currentSecretKey)
            ]);

            $this->logWarning('handlePosConfigUpdated: Current configuration loaded', [
                'environment' => $currentEnvironment,
                'merchant_site_uid' => $currentMerchantSiteUid,
                'has_config' => !empty($currentConfig)
            ]);

            // Validate environment and merchant site UID match
            if ($data['environment'] !== $currentEnvironment || $data['merchant_site_uid'] !== $currentMerchantSiteUid) {
                $this->logError('handlePosConfigUpdated: Environment or merchant site UID mismatch', [
                    'webhook_environment' => $data['environment'] ?? 'missing',
                    'webhook_merchant_site_uid' => $data['merchant_site_uid'] ?? 'missing',
                    'current_environment' => $currentEnvironment,
                    'current_merchant_site_uid' => $currentMerchantSiteUid
                ]);
                throw new \InvalidArgumentException('Environment or merchant site UID mismatch');
            }

            // Validate checkstr (MD5 hash of merchant_site_uid + secret_key)
            $expectedCheckstr = md5($currentMerchantSiteUid . $currentSecretKey);
            
            $this->logWarning('handlePosConfigUpdated: Checkstr validation', [
                'webhook_checkstr' => $data['checkstr'] ?? 'missing',
                'expected_checkstr' => $expectedCheckstr,
                'merchant_site_uid' => $currentMerchantSiteUid,
                'secret_key_length' => strlen($currentSecretKey),
                'hash_input' => $currentMerchantSiteUid . '[SECRET_KEY_' . strlen($currentSecretKey) . '_CHARS]'
            ]);
            
            if ($data['checkstr'] !== $expectedCheckstr) {
                $this->logError('handlePosConfigUpdated: Invalid checkstr', [
                    'webhook_checkstr' => $data['checkstr'] ?? 'missing',
                    'expected_checkstr' => $expectedCheckstr,
                    'merchant_site_uid' => $currentMerchantSiteUid,
                    'secret_key_length' => strlen($currentSecretKey)
                ]);
                throw new \InvalidArgumentException('Invalid checkstr');
            }

            // Parse current configuration
            $jsonSerializer = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Serialize\Serializer\Json::class);
            
            $configuration = [];
            if ($currentConfig) {
                try {
                    $configuration = $jsonSerializer->unserialize($currentConfig);
                    $this->logWarning('handlePosConfigUpdated: Current configuration parsed successfully', [
                        'config_keys' => array_keys($configuration)
                    ]);
                } catch (\Exception $e) {
                    $this->logError('handlePosConfigUpdated: Failed to parse current configuration', [
                        'error' => $e->getMessage(),
                        'current_config' => $currentConfig
                    ]);
                    $configuration = [];
                }
            }

            // Save configuration using ConfigurationManager
            $this->configurationManager->saveConfiguration($data['environment'], $data['POS']);

            // Sync shipping methods if available
            if (isset($data['POS']['shipping']) && is_array($data['POS']['shipping'])) {
                $this->syncShippingMethods($data['POS']['shipping']);
            }
            
            $this->logWarning('handlePosConfigUpdated: Configuration saved successfully to holestpay_configuration table', [
                'environment' => $data['environment'],
                'pos_keys' => array_keys($data['POS'] ?? [])
            ]);
            
        } catch (\Throwable $e) {
            $this->logError('handlePosConfigUpdated: Unexpected error occurred', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle payment result webhooks (payresult and orderupdate)
     *
     * @param string $topic
     */
    private function handlePaymentResult(string $topic)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                $this->logError("handlePaymentResult: Invalid webhook data for topic '{$topic}' - empty or malformed JSON");
                throw new \InvalidArgumentException('Invalid webhook data');
            }

            $this->logWarning("handlePaymentResult: Processing webhook data for topic '{$topic}'", [
                'data_keys' => array_keys($data),
                'topic' => $topic
            ]);

            // Extract order information from webhook data
            $orderUid = $data['order_uid'] ?? null;
            $status = $data['status'] ?? null;
            $transactionUid = $data['transaction_uid'] ?? null;

            if (!$orderUid || !$status) {
                $this->logError("handlePaymentResult: Missing required parameters for topic '{$topic}'", [
                    'order_uid' => $orderUid,
                    'status' => $status,
                    'topic' => $topic
                ]);
                throw new \InvalidArgumentException('Missing required parameters: order_uid and status');
            }

            // Find order by HolestPay order UID
            $order = $this->findOrderByHolestPayUid($orderUid);
            if (!$order) {
                $this->logError("handlePaymentResult: Order not found for HolestPay UID '{$orderUid}'", [
                    'order_uid' => $orderUid,
                    'topic' => $topic
                ]);
                throw new \RuntimeException("Order not found for HolestPay UID: {$orderUid}");
            }

            $this->logWarning("handlePaymentResult: Order found for topic '{$topic}'", [
                'order_id' => $order->getId(),
                'order_increment_id' => $order->getIncrementId(),
                'holestpay_uid' => $orderUid,
                'status' => $status,
                'transaction_uid' => $transactionUid,
                'topic' => $topic
            ]);

            // Set HPayStatus on order metadata
            $this->statusManager->setHPayStatus($order, (string)$status);

            // Parse HPay status and update Magento order status
            $this->updateMagentoOrderStatus($order, $status);

            // Set holestpay_uid if not already set
            if (!$order->getData('holestpay_uid')) {
                $order->setData('holestpay_uid', $orderUid);
                $this->logWarning("handlePaymentResult: Set holestpay_uid for order", [
                    'order_id' => $order->getId(),
                    'holestpay_uid' => $orderUid
                ]);
            }

            // Merge hpay_data with previous values up to depth of 5
            $this->mergeHPayData($order, $data, 5);

            // Set a flag to prevent order sync loops when processing webhooks
            $order->setData('_processing_webhook', true);
            
            // Save the order to persist all changes
            $this->orderRepository->save($order);

            // Trigger grid sync to update sales_order_grid table
            $this->triggerGridSync($order);

            // Trigger order sync to HolestPay if conditions are met
            // Skip order sync for 'orderupdate' topic to prevent infinite loops
            if ($topic !== 'orderupdate') {
                $this->triggerOrderSync($order);
            } else {
                $this->logWarning("handlePaymentResult: Skipping order sync for 'orderupdate' topic to prevent loops", [
                    'order_id' => $order->getId(),
                    'topic' => $topic
                ]);
            }

            // Clean up the webhook processing flag
            $order->unsetData('_processing_webhook');

            // Log successful processing
            $this->logWarning("handlePaymentResult: Successfully processed webhook for topic '{$topic}'", [
                'order_id' => $order->getId(),
                'order_increment_id' => $order->getIncrementId(),
                'holestpay_uid' => $orderUid,
                'status' => $status,
                'topic' => $topic
            ]);

        } catch (\Throwable $e) {
            $this->logError("handlePaymentResult: Error processing webhook for topic '{$topic}'", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'topic' => $topic
            ]);
            throw $e;
        }
    }

    /**
     * Trigger order sync to HolestPay if conditions are met
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    private function triggerOrderSync($order)
    {
        try {
            $orderSyncService = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\HEC\HolestPay\Model\OrderSyncService::class);
            
            if ($orderSyncService->shouldSyncOrder($order, false)) {
                $this->logWarning('triggerOrderSync: Order sync triggered', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'holestpay_uid' => $order->getData('holestpay_uid')
                ]);
                
                $result = $orderSyncService->syncOrder($order, null, false);
                
                if ($result) {
                    $this->logWarning('triggerOrderSync: Order sync completed successfully', [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId(),
                        'response' => $result
                    ]);
                } else {
                    $this->logWarning('triggerOrderSync: Order sync failed', [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId()
                    ]);
                }
            } else {
                $this->logWarning('triggerOrderSync: Order sync skipped - conditions not met', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'holestpay_uid' => $order->getData('holestpay_uid')
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('triggerOrderSync: Error triggering order sync', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $order->getId()
            ]);
        }
    }

    /**
     * Sync shipping methods from HolestPay
     *
     * @param array $shippingMethods
     * @return void
     */
    private function syncShippingMethods(array $shippingMethods): void
    {
        try {
            $shippingSyncService = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\HEC\HolestPay\Model\ShippingMethodSyncService::class);
            
            $result = $shippingSyncService->syncShippingMethods($shippingMethods);
            
            if ($result) {
                $this->logWarning('syncShippingMethods: Shipping methods synced successfully', [
                    'count' => count($shippingMethods)
                ]);
            } else {
                $this->logError('syncShippingMethods: Failed to sync shipping methods');
            }
            
        } catch (\Exception $e) {
            $this->logError('syncShippingMethods: Error syncing shipping methods', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Trigger grid sync to update sales_order_grid table
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    private function triggerGridSync($order)
    {
        try {
            $connection = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\ResourceConnection::class)
                ->getConnection();
            
            $orderTable = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\ResourceConnection::class)
                ->getTableName('sales_order');
            $gridTable = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\ResourceConnection::class)
                ->getTableName('sales_order_grid');

            // Get HolestPay data from the order
            $hpayStatus = $order->getData('hpay_status');
            $holestpayUid = $order->getData('holestpay_uid');

            // Update grid table with HolestPay data
            $updateData = [];
            
            if ($hpayStatus !== null) {
                $updateData['hpay_status'] = $hpayStatus;
            }
            
            if ($holestpayUid !== null) {
                $updateData['holestpay_uid'] = $holestpayUid;
            }

            if (!empty($updateData)) {
                $connection->update(
                    $gridTable,
                    $updateData,
                    ['entity_id = ?' => $order->getId()]
                );

                $this->logWarning("triggerGridSync: Successfully synced grid table", [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'synced_data' => $updateData
                ]);
            }

        } catch (\Exception $e) {
            $this->logError("triggerGridSync: Error syncing grid table", [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update Magento order status based on HPay status
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param string $hpayStatus
     * @return void
     */
    private function updateMagentoOrderStatus($order, $hpayStatus)
    {
        try {
            // Parse HPay status to extract payment status
            $paymentStatus = $this->extractPaymentStatus($hpayStatus);
            
            $this->logWarning("updateMagentoOrderStatus: Status parsing", [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'full_hpay_status' => $hpayStatus,
                'extracted_payment_status' => $paymentStatus
            ]);
            
            if (!$paymentStatus) {
                $this->logWarning("updateMagentoOrderStatus: Could not extract payment status from HPay status", [
                    'order_id' => $order->getId(),
                    'hpay_status' => $hpayStatus
                ]);
                return;
            }

            $magentoStatus = $this->mapPaymentStatusToMagentoStatus($paymentStatus);
            
            if ($magentoStatus) {
                $currentStatus = $order->getStatus();
                $currentState = $order->getState();
                
                if ($magentoStatus !== $currentStatus) {
                    // Status is different, update it
                    $order->setStatus($magentoStatus);
                    $order->setState($this->getStateFromStatus($magentoStatus));
                    
                    $this->logWarning("updateMagentoOrderStatus: Updated Magento order status", [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId(),
                        'old_status' => $currentStatus,
                        'new_status' => $magentoStatus,
                        'old_state' => $currentState,
                        'new_state' => $this->getStateFromStatus($magentoStatus),
                        'hpay_payment_status' => $paymentStatus,
                        'full_hpay_status' => $hpayStatus
                    ]);
                } else {
                    // Status is the same, log for debugging
                    $this->logWarning("updateMagentoOrderStatus: Status already up to date", [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId(),
                        'current_status' => $currentStatus,
                        'current_state' => $currentState,
                        'hpay_payment_status' => $paymentStatus,
                        'full_hpay_status' => $hpayStatus
                    ]);
                }
            } else {
                $this->logWarning("updateMagentoOrderStatus: No Magento status mapping found for HPay status", [
                    'order_id' => $order->getId(),
                    'hpay_payment_status' => $paymentStatus,
                    'full_hpay_status' => $hpayStatus
                ]);
            }

        } catch (\Exception $e) {
            $this->logError("updateMagentoOrderStatus: Error updating Magento order status", [
                'order_id' => $order->getId(),
                'hpay_status' => $hpayStatus,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract payment status from HPay status string
     *
     * @param string $hpayStatus
     * @return string|null
     */
    private function extractPaymentStatus($hpayStatus)
    {
        if (preg_match('/^PAYMENT:([^\s]+)/', $hpayStatus, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Map HPay payment status to Magento order status
     *
     * @param string $paymentStatus
     * @return string|null
     */
    private function mapPaymentStatusToMagentoStatus($paymentStatus)
    {
        $statusMap = [
            'SUCCESS' => 'processing',      // Order is paid and ready for fulfillment
            'PAID' => 'processing',         // Order is paid and ready for fulfillment
            'PAYING' => 'processing',       // Order is partially paid
            'AWAITING' => 'pending_payment', // Waiting for bank transfer
            'REFUNDED' => 'closed',         // Order is closed (refunded)
            'PARTIALLY-REFUNDED' => 'processing', // Partial refund, order still active
            'VOID' => 'canceled',           // Order is voided/canceled
            'OVERDUE' => 'pending_payment', // Payment is overdue
            'RESERVED' => 'pending_payment', // Amount reserved but not captured
            'EXPIRED' => 'canceled',        // Payment expired
            'OBLIGATED' => 'pending_payment', // Payment guaranteed but not yet received
            'REFUSED' => 'canceled',        // Payment refused
            'FAILED' => 'canceled',         // Payment failed
            'CANCELED' => 'canceled'        // Payment canceled
        ];

        return $statusMap[$paymentStatus] ?? null;
    }

    /**
     * Get Magento order state from status
     *
     * @param string $status
     * @return string
     */
    private function getStateFromStatus($status)
    {
        $stateMap = [
            'processing' => \Magento\Sales\Model\Order::STATE_PROCESSING,
            'pending_payment' => \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            'closed' => \Magento\Sales\Model\Order::STATE_CLOSED,
            'canceled' => \Magento\Sales\Model\Order::STATE_CANCELED
        ];

        return $stateMap[$status] ?? \Magento\Sales\Model\Order::STATE_PROCESSING;
    }

    /**
     * Find order by HolestPay order UID
     * Note: hpay order_uid equals Magento order increment ID
     *
     * @param string $holestpayUid
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function findOrderByHolestPayUid(string $holestpayUid)
    {
        try {
            // Since hpay order_uid equals Magento order increment ID, this is the most reliable method
            // Try to find by order increment ID first
            $orderModel = $this->orderFactory->create();
            $order = $orderModel->loadByIncrementId($holestpayUid);
            if ($order && $order->getId()) {
                $this->logWarning("findOrderByHolestPayUid: Order found by increment ID (hpay order_uid)", [
                    'holestpay_uid' => $holestpayUid,
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId()
                ]);
                return $order;
            }

            // Second priority: try to find order by HolestPay UID in the direct database column
            $orderCollection = $orderModel->getCollection();
            $orderCollection->addFieldToFilter('holestpay_uid', $holestpayUid);
            
            if ($orderCollection->getSize() > 0) {
                $order = $orderCollection->getFirstItem();
                $this->logWarning("findOrderByHolestPayUid: Order found by HolestPay UID column", [
                    'holestpay_uid' => $holestpayUid,
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId()
                ]);
                return $order;
            }

            // Third priority: try to find by quote ID (fallback for older orders)
            $orderCollection = $orderModel->getCollection();
            $orderCollection->addFieldToFilter('quote_id', $holestpayUid);
            
            if ($orderCollection->getSize() > 0) {
                $order = $orderCollection->getFirstItem();
                $this->logWarning("findOrderByHolestPayUid: Order found by quote ID", [
                    'holestpay_uid' => $holestpayUid,
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId(),
                    'quote_id' => $order->getQuoteId()
                ]);
                return $order;
            }

            $this->logWarning("findOrderByHolestPayUid: Order not found by any method", [
                'holestpay_uid' => $holestpayUid,
                'lookup_methods_tried' => [
                    'increment_id' => 'hpay order_uid (equals Magento increment ID)',
                    'holestpay_uid_column' => 'direct database column',
                    'quote_id' => 'fallback for older orders'
                ]
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logError("findOrderByHolestPayUid: Error finding order", [
                'holestpay_uid' => $holestpayUid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Merge hpay_data with new data up to specified depth
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $newData
     * @param int $maxDepth
     * @return void
     */
    private function mergeHPayData($order, array $newData, int $maxDepth)
    {
        try {
            // Remove 'order' property from new data to avoid storing redundant order information
            $filteredData = $newData;
            if (isset($filteredData['order'])) {
                unset($filteredData['order']);
                $this->logWarning("mergeHPayData: Removed 'order' property from webhook data", [
                    'order_id' => $order->getId(),
                    'removed_keys' => ['order']
                ]);
            }
            
            // Get existing hpay_data
            $existingData = $order->getData('hpay_data');
            $mergedData = [];
            
            if ($existingData) {
                try {
                    $mergedData = json_decode($existingData, true);
                    if (!is_array($mergedData)) {
                        $mergedData = [];
                    }
                } catch (\Exception $e) {
                    $this->logWarning("mergeHPayData: Could not parse existing hpay_data, starting fresh", [
                        'order_id' => $order->getId(),
                        'error' => $e->getMessage()
                    ]);
                    $mergedData = [];
                }
            }
            
            // Merge filtered data with existing data up to specified depth
            $mergedData = $this->deepMerge($mergedData, $filteredData, $maxDepth);
            
            // Store the merged data
            $order->setData('hpay_data', json_encode($mergedData));
            
            $this->logWarning("mergeHPayData: Successfully merged hpay_data", [
                'order_id' => $order->getId(),
                'existing_keys' => array_keys($existingData ? json_decode($existingData, true) : []),
                'new_keys' => array_keys($filteredData),
                'merged_keys' => array_keys($mergedData),
                'max_depth' => $maxDepth
            ]);
            
        } catch (\Exception $e) {
            $this->logError("mergeHPayData: Error merging hpay_data", [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Deep merge arrays up to specified depth
     *
     * @param array $array1
     * @param array $array2
     * @param int $maxDepth
     * @param int $currentDepth
     * @return array
     */
    private function deepMerge(array $array1, array $array2, int $maxDepth, int $currentDepth = 0)
    {
        if ($currentDepth >= $maxDepth) {
            // If we've reached max depth, return array2 (new data takes precedence)
            return $array2;
        }
        
        $merged = $array1;
        
        foreach ($array2 as $key => $value) {
            if (isset($merged[$key]) && is_array($merged[$key]) && is_array($value)) {
                // Recursively merge nested arrays
                $merged[$key] = $this->deepMerge($merged[$key], $value, $maxDepth, $currentDepth + 1);
            } else {
                // Overwrite or add new value
                $merged[$key] = $value;
            }
        }
        
        return $merged;
    }

    private function loadOrderIdByIncrementId(string $incrementId): int
    {
        /** @var \Magento\Sales\Model\Order $orderModel */
        $orderModel = $this->orderFactory->create();
        $order = $orderModel->loadByIncrementId($incrementId);
        if (!$order || !$order->getId()) {
            throw new \RuntimeException('Order not found');
        }
        return (int) $order->getId();
    }
}


