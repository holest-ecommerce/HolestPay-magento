<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Framework\DataObject;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Framework\App\RequestInterface;
use HEC\HolestPay\Api\ConfigManagerInterface;

class Createorder implements HttpPostActionInterface
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var ConfigManagerInterface
     */
    protected $configManager;

    /**
     * @param Context $context
     * @param ResultFactory $resultFactory
     * @param Json $json
     * @param CheckoutSession $checkoutSession
     * @param OrderRepositoryInterface $orderRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param CartManagementInterface $cartManagement
     * @param OrderManagementInterface $orderManagement
     * @param RequestInterface $request
     * @param ConfigManagerInterface $configManager
     */
    public function __construct(
        Context $context,
        ResultFactory $resultFactory,
        Json $json,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        CartManagementInterface $cartManagement,
        OrderManagementInterface $orderManagement,
        RequestInterface $request,
        ConfigManagerInterface $configManager
    ) {
        $this->context = $context;
        $this->resultFactory = $resultFactory;
        $this->json = $json;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->cartManagement = $cartManagement;
        $this->orderManagement = $orderManagement;
        $this->request = $request;
        $this->configManager = $configManager;
    }

    /**
     * Create a pending order for HolestPay payment
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $debug = []; // Global debug array

        try {
            // Get request data
            $rawContent = $this->request->getContent();
            $debug['raw_request_content'] = $rawContent;

            $passed_checkout_email = null;
            
            $requestData = [];
            if ($rawContent) {
                try {
                    $requestData = $this->json->unserialize($rawContent);

                    if($requestData && isset($requestData['email']) && strpos($requestData['email'], '@') !== false){
                        $passed_checkout_email = $requestData['email'];
                    }

                    $debug['request_data_parsed'] = $requestData;
                } catch (\Exception $jsonError) {
                    $debug['json_parsing_error'] = $jsonError->getMessage();
                    // Continue with empty request data
                }
            }
            
            // No need to validate quote_data anymore since we get everything from the session
            if (!$requestData) {
                $requestData = [];
            }

            try{
                //if checkoutSession email is empty or null and thate is value for $passed_checkout_email
                if(!$this->checkoutSession->getQuote()->getBillingAddress()->getEmail() && $passed_checkout_email){
                    $this->checkoutSession->getQuote()->getBillingAddress()->setEmail($passed_checkout_email);
                }

            }catch(\Throwable $e){
                $debug['error_message'] = $e->getMessage();
                $debug['error_file'] = $e->getFile();
                $debug['error_line'] = $e->getLine();
                $debug['error_trace'] = $e->getTraceAsString();
            }

            // Get the current quote from checkout session
            $quote = $this->checkoutSession->getQuote();
            $debug['quote_retrieved'] = $quote ? 'ID: ' . $quote->getId() : 'NULL';
            
            if (!$quote || !$quote->getId()) {
                throw new LocalizedException(__('No active quote found'));
            }

            // Get the new order status from configuration
            $newOrderStatus = $this->scopeConfig->getValue(
                'payment/holestpay/order_status',
                ScopeInterface::SCOPE_STORE
            );
            $debug['new_order_status_config'] = $newOrderStatus ?: 'NULL (using default)';
            
            // If no status configured, use default 'pending_payment'
            if (!$newOrderStatus) {
                $newOrderStatus = 'pending_payment';
            }
            $debug['final_order_status'] = $newOrderStatus;

            // Create the order from the quote
            $debug['order_creation_start'] = 'Attempting to create order from quote...';
            
            $order = $this->createOrderFromQuote($quote, $newOrderStatus);

            $debug['order_creation_result'] = $order ? 'ID: ' . $order->getId() : 'NULL';

            if (!$order || !$order->getId()) {
                throw new LocalizedException(__('Failed to create order'));
            }

            // Return success response with real order ID
            return $result->setData([
                'success' => true,
                'order_id' => $order->getIncrementId(),
                'order_entity_id' => $order->getId(),
                'status' => $order->getStatus(),
                'message' => 'Order created successfully with status: ' . $newOrderStatus,
                'debug' => $debug
            ]);

        } catch (\Throwable $e) {
            $debug['error_message'] = $e->getMessage();
            $debug['error_file'] = $e->getFile();
            $debug['error_line'] = $e->getLine();
            $debug['error_trace'] = $e->getTraceAsString();
            
            return $result->setData([
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => $debug
            ]);
        }
    }

    /**
     * Create order from quote
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $status
     * @return \Magento\Sales\Model\Order|null
     */
    protected function createOrderFromQuote($quote, $status)
    {
        try {
            // Try to get existing order from session first
            $order = $this->checkoutSession->getLastRealOrder();
            if ($order && $order->getId()) {
                return $order;
            }
            // No existing order, use Magento's standard order placement (same as Check/Money Order)
            $order = $this->placeOrderUsingStandardMagentoProcess($quote, $status);
            
            return $order;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Place order using Magento's standard process (same as Check/Money Order)
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $status
     * @return \Magento\Sales\Model\Order|null
     */
    protected function placeOrderUsingStandardMagentoProcess($quote, $status)
    {
        try {
            // This is the EXACT same process that Check/Money Order uses when you click "Place Order"
            // It will generate a real Magento order ID, increment ID, etc.
            
            // Validate quote before proceeding
            if (!$quote->getId()) {
                error_log('HolestPay CreateOrder Error: Quote has no ID');
                return null;
            }
            
            if (!$quote->hasItems()) {
                error_log('HolestPay CreateOrder Error: Quote has no items');
                return null;
            }
            
            // Set the payment method to HolestPay
            $quote->getPayment()->setMethod('holestpay');
            
            
            // Use Magento's standard order placement service
            $orderId = $this->cartManagement->placeOrder($quote->getId());
            
            
            if (!$orderId) {
                error_log('HolestPay CreateOrder Error: placeOrder returned no order ID');
                return null;
            }

            // Get the created order
            $order = $this->orderRepository->get($orderId);
            // Check if default order mail should be disabled
            if ($this->configManager->isDefaultOrderMailDisabled()) {
                $order->setCanSendNewEmailFlag(false);
            }
            // Update the order status to our configured status
            $order->setStatus($status);
            $order->setState($this->getStateFromStatus($status));
            
            $this->orderRepository->save($order);
            
            return $order;

        } catch (\Throwable $e) {
           http_response_code(500); 
           header('Content-Type: application/json');
           echo json_encode(array(
            "error" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => $e->getTraceAsString()
           ));
           die;  
        }
    }
    
    /**
     * Get state from status
     *
     * @param string $status
     * @return string
     */
    protected function getStateFromStatus($status)
    {
        $statusToStateMap = [
            'pending_payment' => \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
            'processing' => \Magento\Sales\Model\Order::STATE_PROCESSING,
            'complete' => \Magento\Sales\Model\Order::STATE_COMPLETE,
            'canceled' => \Magento\Sales\Model\Order::STATE_CANCELED,
            'holded' => \Magento\Sales\Model\Order::STATE_HOLDED,
            'closed' => \Magento\Sales\Model\Order::STATE_CLOSED
        ];

        return $statusToStateMap[$status] ?? \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
    }
}