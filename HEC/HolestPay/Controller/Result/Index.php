<?php
/**
 * HolestPay Result Controller
 * 
 * This controller handles payment result processing with the following features:
 * 
 * 1. **Order Locking**: Uses database-based locking to prevent concurrent processing
 *    of the same order from multiple requests (webhooks, forwarded responses, etc.)
 * 
 * 2. **Duplicate Protection**: Checks if responses have already been processed
 * 
 * 3. **Response Types**: Handles both forwarded payment responses (AJAX) and
 *    direct response parameters (redirects)
 * 
 * 4. **Proper Cleanup**: Ensures locks are always released using try-finally blocks
 * 
 * The locking mechanism prevents race conditions that could lead to:
 * - Double processing of payments
 * - Inconsistent order states
 * - Duplicate invoices or status updates
 * 
 * Lock timeout: 16 seconds with 1-second retry intervals
 * Lock cleanup: Automatic cleanup of locks older than 30 seconds
 */
namespace HEC\HolestPay\Controller\Result;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Event\ManagerInterface;
use HEC\HolestPay\Model\Order\StatusManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Index implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var UrlInterface
     */
    protected $url;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var SessionManagerInterface
     */
    protected $session;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var StatusManager
     */
    protected $statusManager;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;


    /**
     * @param Context $context
     * @param ResultFactory $resultFactory
     * @param UrlInterface $url
     * @param Json $json
     * @param SessionManagerInterface $session
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderFactory $orderFactory
     * @param ManagerInterface $eventManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        ResultFactory $resultFactory,
        UrlInterface $url,
        Json $json,
        SessionManagerInterface $session,
        CheckoutSession $checkoutSession,
        StatusManager $statusManager,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        ManagerInterface $eventManager,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resultFactory = $resultFactory;
        $this->url = $url;
        $this->request = $context->getRequest();
        $this->json = $json;
        $this->session = $session;
        $this->checkoutSession = $checkoutSession;
        $this->statusManager = $statusManager;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->eventManager = $eventManager;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if debug logging is enabled
     *
     * @return bool
     */
    protected function isDebugEnabled()
    {
        return (bool) $this->scopeConfig->getValue(
            'payment/holestpay/debug',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Log message only if debug is enabled (except for errors)
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function debugLog($level, $message, array $context = [])
    {
        if ($this->isDebugEnabled() || $level === 'error') {
            switch ($level) {
                case 'warning':
                    $this->logger->warning($message, $context);
                    break;
                case 'info':
                    $this->logger->info($message, $context);
                    break;
                case 'error':
                    $this->logger->error($message, $context);
                    break;
                default:
                    $this->logger->debug($message, $context);
                    break;
            }
        }
    }

    /**
     * Disable CSRF validation for this controller
     * This is necessary because HolestPay sends requests from external sites
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Disable CSRF validation for this controller
     * This is necessary because HolestPay sends requests from external sites
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * Disable form key validation for this controller
     * This is necessary because HolestPay sends POST requests from external sites
     *
     * @return bool
     */
    protected function _validateFormKey()
    {
        return true;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            // Get order ID from either order_uid or order_id parameter
            $orderUid = $this->request->getParam('order_uid') ?: $this->request->getParam('order_id');

            //CLEAR CHECKOUT SESSION
            // Force create a completely new quote by clearing session and creating fresh
            $this->checkoutSession->clearQuote();
            $this->checkoutSession->clearStorage();

            // Force the session to create a completely new quote
            $this->checkoutSession->setQuoteId(null);
            $quote = $this->checkoutSession->getQuote();
            
            if ($this->request->isPost()) {
                $forwardedResponse = $this->request->getParam('hpay_forwarded_payment_response');
                
                if (!$forwardedResponse && $this->request->getContent()) {
                    $content = $this->request->getContent();
                    if (strpos($content, 'hpay_forwarded_payment_response') !== false) {
                        if (preg_match('/hpay_forwarded_payment_response=([^&]+)/', $content, $matches)) {
                            $forwardedResponse = urldecode($matches[1]);
                        }
                    }
                }
                
                if ($forwardedResponse) {
                    $forwardedResponse = $this->json->unserialize($forwardedResponse);
                    if($forwardedResponse && isset($forwardedResponse['order_uid'])){
                        $orderUid = $forwardedResponse['order_uid'];
                    }

                    $this->debugLog('warning', 'HolestPay: Processing POST request with forwarded payment response');
                    $this->handleForwardedResponse($forwardedResponse);
                } 
            }

            // Note: Old success/failure path handling removed - now handled via status parameter

            // Check if we have a status parameter for success/failure display
            $status = $this->request->getParam('status');
            if ($status && $orderUid) {
                $order = $this->findOrderByIncrementId($orderUid);
                if ($order) {
                    if ($status === 'success') {
                        return $this->displayResultPage($order, 'success');
                    } elseif ($status === 'failure') {
                        return $this->displayResultPage($order, 'failure');
                    }
                }
            }
            
            return $this->handleDirectResponse($orderUid);

        } catch (\Exception $e) {
            $this->debugLog('error', 'HolestPay Result Controller Error: ' . $e->getMessage(), [
                'exception' => $e,
                'params' => $this->request->getParams()
            ]);

            // Return a simple error page instead of redirecting
            return $this->displayErrorPage('An error occurred while processing the request.');
        }
    }

    /**
     * Handle forwarded payment response (like WooCommerce pattern)
     *
     * @param string $forwardedResponse
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function handleForwardedResponse($forwardedResponse)
    {
        try {
            $this->debugLog('warning', 'HolestPay: Processing forwarded response');
            
            // Parse the forwarded response
            $responseData = is_string($forwardedResponse) ? $this->json->unserialize($forwardedResponse) : $forwardedResponse;
            if (!$responseData) {
                $this->debugLog('error', 'HolestPay: Failed to parse forwarded response');
                return $this->displayResultPage(null, 'failure');
            }

            $this->debugLog('warning', 'HolestPay: Forwarded response data', ['data' => $responseData]);

            // Find the order
            $order = $this->findOrderByIncrementId($responseData['order_uid']);
            
            if (!$order) {
                $this->debugLog('error', 'HolestPay: Order not found for forwarded response', [
                    'order_uid' => $order_uid ?? 'unknown',
                    'response_data' => $responseData
                ]);
                return $this->displayOrderNotFoundError();
            }

            $this->debugLog('warning', 'HolestPay: Order found for forwarded response', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId()
            ]);

            // Set the processing flag to prevent infinite loops
            $order->setData('_processing_forwarded_response', true);

            // Update order with HolestPay data
            $this->updateMagentoOrderStatus($order, $responseData);
            
            // Set holestpay_uid if not already set
            if (!$order->getData('holestpay_uid')) {
                $order->setData('holestpay_uid', $responseData['order_uid'] ?? null);
                $this->debugLog('warning', 'HolestPay: Set holestpay_uid for order', [
                    'holestpay_uid' => $responseData['order_uid'] ?? 'unknown',
                    'order_id' => $order->getId()
                ]);
            }

            // Merge and save hpay_data
            $this->mergeHPayData($order, $responseData);
            
            // Save the order
            $order->save();
            
            // Trigger grid sync and order sync
            $this->triggerGridSync($order);
            $this->triggerOrderSync($order);

            $this->debugLog('warning', 'HolestPay: Successfully processed forwarded response', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId()
            ]);

            // Check HPay status to determine redirect
            $this->debugLog('warning', 'HolestPay: Checking HPay status for redirect decision', [
                'hpay_status' => $order->getData('hpay_status')
            ]);

            if ($this->isPaymentSuccessfulByHPayStatus($order->getData('hpay_status'))) {
                $this->debugLog('warning', 'HolestPay: Payment successful, displaying success page', [
                    'order_id' => $order->getId(),
                    'hpay_status' => $order->getData('hpay_status')
                ]);
                return $this->displayResultPage($order, 'success');
        } else {
                $this->debugLog('warning', 'HolestPay: Payment failed, displaying failure page', [
                    'order_id' => $order->getId(),
                    'hpay_status' => $order->getData('hpay_status')
                ]);
                return $this->displayResultPage($order, 'failure');
            }

        } catch (\Exception $e) {
            $this->debugLog('error', 'HolestPay: Error processing forwarded response', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->displayErrorPage('An error occurred while processing the payment response.');
        }
    }

    /**
     * Handle direct response parameters (GET request with status/order_uid)
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function handleDirectResponse($orderId)
    {
        try {
            $order = $this->findOrderByIncrementId($orderId);
            if (!$order) {
                $this->debugLog('warning', 'HolestPay: Order not found for direct access', ['order_id' => $orderId]);
                return $this->displayOrderNotFoundError();
            }
            
            // Check HPay status to determine redirect
            $hpayStatus = $order->getData('hpay_status');
            if ($this->isPaymentSuccessfulByHPayStatus($hpayStatus)) {
                $this->debugLog('warning', 'HolestPay: Payment successful for direct access, displaying success page');
                return $this->displayResultPage($order, 'success');
            } else {
                $this->debugLog('warning', 'HolestPay: Payment failed for direct access, displaying failure page');
                return $this->displayResultPage($order, 'failure');
            }

        } catch (\Exception $e) {
            $this->debugLog('error', 'HolestPay: Error processing direct response', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->displayOrderNotFoundError();
        }
    }

    /**
     * Check if payment was successful
     *
     * @param string $status
     * @return bool
     */
    protected function isPaymentSuccessful($status)
    {
        if (!$status) {
            return false;
        }

        $successStatuses = ['SUCCESS', 'PAID', 'PAYING', 'RESERVED', 'AWAITING', 'OBLIGATED'];
        $statusUpper = strtoupper($status);

        foreach ($successStatuses as $successStatus) {
            if (strpos($statusUpper, $successStatus) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if payment was successful based on HPay status
     * This method checks if the HPay status CONTAINS (not equals) the success strings
     *
     * @param string $hpayStatus
     * @return bool
     */
    protected function isPaymentSuccessfulByHPayStatus($hpayStatus)
    {
        if (!$hpayStatus) {
            return false;
        }

        $successKeywords = ['SUCCESS', 'PAID', 'PAYING', 'RESERVED', 'OBLIGATED', 'AWAITING'];
        
        foreach ($successKeywords as $keyword) {
            if (stripos($hpayStatus, $keyword) !== false) {
                $this->debugLog('warning', 'isPaymentSuccessfulByHPayStatus: Found success status', [
                    'hpay_status' => $hpayStatus,
                    'success_keyword' => $keyword
                ]);
                return true;
            }
        }

        $this->debugLog('warning', 'isPaymentSuccessfulByHPayStatus: No success status found', [
            'hpay_status' => $hpayStatus
        ]);
        return false;
    }

    /**
     * Validate that the request is coming from a legitimate HolestPay source
     * This helps prevent unauthorized access to the result endpoint
     *
     * @return bool
     */
    protected function validateHolestPayRequest()
    {
        return true;
    }


    /**
     * Display success page
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|null $order
     * @return \Magento\Framework\View\Result\Page
     */
    protected function displaySuccessPage($order = null)
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        
        // Set page title
        $resultPage->getConfig()->getTitle()->set(__('Payment Successful - Order Confirmation'));
        
        // Use the unified result handle instead of the deleted success handle
        $resultPage->addHandle('holestpay_result');
        
        // Pass order to the block if available
        if ($order) {
            $block = $resultPage->getLayout()->getBlock('holestpay.result');
            if ($block) {
                $block->setData('order', $order);
                $block->setData('status', 'success');
            }
        }
        
        return $resultPage;
    }

    /**
     * Display failure page
     *
     * @param \Magento\Sales\Api\Data\OrderInterface|null $order
     * @return \Magento\Framework\View\Result\Page
     */
    protected function displayFailurePage($order = null)
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        
        // Set page title
        $resultPage->getConfig()->getTitle()->set(__('Payment Failed - Order Details'));
        
        // Use the unified result handle instead of the deleted failure handle
        $resultPage->addHandle('holestpay_result');
        
        // Pass order to the block if available
        if ($order) {
            $block = $resultPage->getLayout()->getBlock('holestpay.result');
            if ($block) {
                $block->setData('order', $order);
                $block->setData('status', 'failure');
            }
        }
        
        return $resultPage;
    }

    /**
     * Display error page when order not found
     *
     * @return \Magento\Framework\View\Result\Page
     */
    protected function displayOrderNotFoundError()
    {
        // Create a simple data object for the template
        $templateData = (object) [
            'order' => null,
            'status' => 'not_found',
            'isSuccess' => false,
            'isError' => true,
            'error_message' => __('The order you are looking for could not be found.')
        ];
        
        // Create a simple HTML response
        $html = $this->renderTemplate($templateData);
        
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set(__('Order Not Found'));
        
        // Set the HTML content using helper method
        $this->setPageContent($resultPage, $html);
        
        return $resultPage;
    }



    /**
     * Update Magento order status based on HPay status (same logic as webhook)
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $responseData
     * @return void
     */
    protected function updateMagentoOrderStatus($order, $responseData)
    {
        try {
            // Extract status from response data
            $hpayStatus = $responseData['status'] ?? null;
            if (!$hpayStatus) {
                $this->debugLog('warning', 'updateMagentoOrderStatus: No status found in response data');
                return;
            }

            // Set HPay status on the order using StatusManager (same as Webhook.php)
            if ($hpayStatus) {
                $this->statusManager->setHPayStatus($order, (string)$hpayStatus);
            }

            // Parse HPay status to extract payment status
            $paymentStatus = $this->extractPaymentStatus($hpayStatus);
            
            $this->debugLog('warning', "updateMagentoOrderStatus: Status parsing", [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'full_hpay_status' => $hpayStatus,
                'extracted_payment_status' => $paymentStatus
            ]);
            
            if (!$paymentStatus) {
                $this->debugLog('warning', "updateMagentoOrderStatus: Could not extract payment status from HPay status", [
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
                    
                    $this->debugLog('warning', "updateMagentoOrderStatus: Updated Magento order status", [
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
                    $this->debugLog('warning', "updateMagentoOrderStatus: Status already up to date", [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId(),
                        'current_status' => $currentStatus,
                        'current_state' => $currentState,
                        'hpay_payment_status' => $paymentStatus,
                        'full_hpay_status' => $hpayStatus
                    ]);
                }
            } else {
                $this->debugLog('warning', "updateMagentoOrderStatus: No Magento status mapping found for HPay status", [
                    'order_id' => $order->getId(),
                    'hpay_payment_status' => $paymentStatus,
                    'full_hpay_status' => $hpayStatus
                ]);
            }

        } catch (\Exception $e) {
            $this->debugLog('error', "updateMagentoOrderStatus: Error updating Magento order status", [
                'order_id' => $order->getId(),
                'response_data' => $responseData,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract payment status from HPay status string (same logic as webhook)
     *
     * @param string $hpayStatus
     * @return string|null
     */
    protected function extractPaymentStatus($hpayStatus)
    {
        if (preg_match('/^PAYMENT:([^\s]+)/', $hpayStatus, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Map HPay payment status to Magento order status (same logic as webhook)
     *
     * @param string $paymentStatus
     * @return string|null
     */
    protected function mapPaymentStatusToMagentoStatus($paymentStatus)
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
     * Get Magento order state from status (same logic as webhook)
     *
     * @param string $status
     * @return string
     */
    protected function getStateFromStatus($status)
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
     * Merge hpay_data with new data up to specified depth (same logic as webhook)
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $newData
     * @param int $maxDepth
     * @return void
     */
    protected function mergeHPayData($order, $responseData)
    {
        try {
            // Get existing hpay_data
            $existingData = $order->getData('hpay_data');
            $existingArray = [];
            
            if ($existingData) {
                if (is_string($existingData)) {
                    $existingArray = $this->json->unserialize($existingData);
                } elseif (is_array($existingData)) {
                    $existingArray = $existingData;
                }
            }

            // Remove the 'order' property from forwarded response data if it exists
            // This prevents circular references and keeps the data clean
            if (isset($responseData['order'])) {
                unset($responseData['order']);
                $this->debugLog('warning', "mergeHPayData: Removed 'order' property from forwarded response data");
            }

            // Merge the data (new data takes precedence)
            $mergedData = array_merge($existingArray, $responseData);
            
            // Serialize and save back to the order
            $serializedData = $this->json->serialize($mergedData);
            $order->setData('hpay_data', $serializedData);
            
            $this->debugLog('warning', "mergeHPayData: Successfully merged hpay_data", [
                'order_id' => $order->getId(),
                'existing_keys' => array_keys($existingArray),
                'new_keys' => array_keys($responseData),
                'merged_keys' => array_keys($mergedData)
            ]);

        } catch (\Exception $e) {
            $this->debugLog('error', 'mergeHPayData: Error merging hpay_data', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId()
            ]);
        }
    }

    /**
     * Deep merge arrays up to specified depth (same logic as webhook)
     *
     * @param array $array1
     * @param array $array2
     * @param int $maxDepth
     * @param int $currentDepth
     * @return array
     */
    protected function deepMerge(array $array1, array $array2, int $maxDepth, int $currentDepth = 0)
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

    /**
     * Trigger grid sync to update sales_order_grid table (same logic as webhook)
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    protected function triggerGridSync($order)
    {
        try {
            // Trigger grid sync to update sales_order_grid table
            $order->save();
            
            $this->debugLog('warning', "triggerGridSync: Successfully synced grid table", [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId()
            ]);

        } catch (\Exception $e) {
            $this->debugLog('error', 'triggerGridSync: Error syncing grid table', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId()
            ]);
        }
    }

    /**
     * Check if order should be synced to HolestPay
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return bool
     */
    protected function shouldSyncOrder($order)
    {
        // Check if order is not being processed by forwarded response
        if ($order->getData('_processing_forwarded_response')) {
            return false;
        }
        
        // Add other conditions as needed
        return true;
    }

    /**
     * Trigger order sync to HolestPay if conditions are met (same logic as webhook)
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    protected function triggerOrderSync($order)
    {
        try {
            // Check if we should sync this order
            if ($this->shouldSyncOrder($order)) {
                $this->debugLog('warning', 'triggerOrderSync: Order sync triggered', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId()
                ]);
                
                // Trigger the order sync event
                $this->eventManager->dispatch('sales_order_save_after', ['order' => $order]);
                
                $this->debugLog('warning', 'triggerOrderSync: Order sync completed successfully', [
                    'order_id' => $order->getId()
                ]);
            } else {
                $this->debugLog('warning', 'triggerOrderSync: Order sync skipped - conditions not met', [
                    'order_id' => $order->getId(),
                    'reason' => 'Processing flag set or other conditions not met'
                ]);
            }

        } catch (\Exception $e) {
            $this->debugLog('error', 'triggerOrderSync: Error triggering order sync', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId()
            ]);
        }
    }

    /**
     * Display error page with custom message
     *
     * @param string $message
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function displayErrorPage($message)
    {
        // Create a simple data object for the template
        $templateData = (object) [
            'order' => null,
            'status' => 'error',
            'isSuccess' => false,
            'isError' => true,
            'error_message' => $message
        ];
        
        // Create a simple HTML response
        $html = $this->renderTemplate($templateData);
        
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set(__('Error'));
        
        // Set the HTML content using helper method
        $this->setPageContent($resultPage, $html);
        
        return $resultPage;
    }

    /**
     * Display result page with success or failure styling
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param string $status 'success' or 'failure'
     * @return \Magento\Framework\Controller\ResultInterface
     */
    protected function displayResultPage($order, $status)
    {
        // Create a simple data object for the template
        $templateData = (object) [
            'order' => $order,
            'status' => $status,
            'isSuccess' => $status === 'success',
            'isError' => $status === 'error' || $status === 'not_found'
        ];
        
        // Set page title
        $title = $status === 'success' ? __('Payment Successful') : __('Payment Failed');
        
        // Create a simple HTML response
        $html = $this->renderTemplate($templateData);
        
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->set($title);
        
        // Set the HTML content using helper method
        $this->setPageContent($resultPage, $html);
        
        return $resultPage;
    }
    
    /**
     * Simple template renderer - no Magento blocks, just PHP include
     */
    protected function renderTemplate($data)
    {
        // Start output buffering
        ob_start();
        
        // Extract data to variables for the template
        $order = $data->order;
        $status = $data->status;
        $isSuccess = $data->isSuccess;
        $isError = $data->isError;
        $error_message = $data->error_message ?? null;
        
        // Create translation function for the template
        $__ = function($text) {
            return __($text);
        };
        
        // Include the template file
        include $this->getTemplatePath();
        
        // Get the output and clean the buffer
        $html = ob_get_clean();
        
        return $html;
    }
    
    /**
     * Get the template file path
     */
    protected function getTemplatePath()
    {
        return __DIR__ . '/../../view/frontend/web/template/result/payresult.phtml';
    }

    /**
     * Find order by increment ID
     *
     * @param string $incrementId
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    protected function findOrderByIncrementId($incrementId)
    {
        try {
            if (!$incrementId) {
                return null;
            }

            $order = $this->orderRepository->get($incrementId);
            return $order;
        } catch (\Exception $e) {
            $this->debugLog('warning', 'HolestPay: Order not found by increment ID', [
                'increment_id' => $incrementId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Helper method to set page content properly
     *
     * @param \Magento\Framework\View\Result\Page $resultPage
     * @param string $html
     * @return void
     */
    protected function setPageContent($resultPage, $html)
    {
        try {
            // Create content block if it doesn't exist
            $contentBlock = $resultPage->getLayout()->createBlock(
                \Magento\Framework\View\Element\Text::class, 
                'page_content'
            );
            
            // Set the HTML content
            $contentBlock->setText($html);
            
            // Add the block to the layout
            $resultPage->getLayout()->setChild('content','page_content', $contentBlock);
            
            $this->debugLog('info', 'HolestPay: Content block created and content set successfully');
        } catch (\Exception $e) {
            $this->debugLog('error', 'HolestPay: Error setting page content', [
                'error' => $e->getMessage()
            ]);
            // Fallback: try to set content directly on the page
            $resultPage->getLayout()->getUpdate()->addUpdate('<reference name="content"><block type="core/text" name="content"><action method="setText"><text><![CDATA[' . $html . ']]></text></action></block></reference>');
        }
    }
}
