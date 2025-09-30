<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use HEC\HolestPay\Model\SignatureHelper;
use HEC\HolestPay\Model\OrderSyncService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class Payment implements HttpPostActionInterface
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderSyncService
     */
    protected $orderSyncService;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param Json $json
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param OrderSyncService $orderSyncService
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        Json $json,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        OrderSyncService $orderSyncService,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->json = $json;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->orderSyncService = $orderSyncService;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
    }

    /**
     * Handle payment signature request
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        
        try {
            $content = $this->request->getContent();
            $requestData = $this->json->unserialize($content);
            
            if (!$requestData) {
                throw new LocalizedException(__('Invalid request data'));
            }

            // Debug: Log the received data
            $this->logger->info('HolestPay AJAX Payment - Received data: ' . json_encode($requestData));
            
            // Log shipping method if present
            if (isset($requestData['shipping_method'])) {
                $this->logger->info('HolestPay AJAX Payment - Shipping method included: ' . $requestData['shipping_method']);
            }
            
            // Check if request_data wrapper exists (common in AJAX requests)
            if (isset($requestData['request_data']) && is_array($requestData['request_data'])) {
                $requestData = $requestData['request_data'];
                $this->logger->info('HolestPay AJAX Payment - Extracted from request_data: ' . json_encode($requestData));
            }
            
            // Validate that required fields are present
            if (empty($requestData['order_uid'])) {
                throw new LocalizedException(__('order_uid is required in request data'));
            }
            
            // Refill request data from order data using deep merge
            $requestData = $this->refillRequestDataFromOrder($requestData);

            if (empty($requestData['order_amount'])) {
                throw new LocalizedException(__('order_amount is required in request data'));
            }
            
            if (empty($requestData['order_currency'])) {
                throw new LocalizedException(__('order_currency is required in request data'));
            }

            // Sign the payment request
            $signedRequest = $this->signPaymentRequest($requestData);
            
            $this->logger->info('HolestPay AJAX Payment - Successfully signed request: ' . json_encode($signedRequest));
            
            return $result->setData([
                'success' => true,
                'signed_request' => $signedRequest
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('HolestPay AJAX Payment - Error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sign payment request using HolestPay signature algorithm
     *
     * @param array $requestData
     * @return array
     */
    public function signPaymentRequest($requestData)
    {
        return SignatureHelper::signRequestData($requestData, $this->scopeConfig);
    }

    /**
     * Refill request data from order data using deep merge
     *
     * @param array $requestData
     * @return array
     */
    private function refillRequestDataFromOrder($requestData)
    {
        try {
            // Get order by increment ID (order_uid)
            $orderIncrementId = $requestData['order_uid'];
            $orderModel = $this->orderFactory->create();
			$order = $orderModel->loadByIncrementId($orderIncrementId);
            
            if (!$order || !$order->getId()) {
                $this->logger->warning('HolestPay AJAX Payment - Order not found for increment ID: ' . $orderIncrementId);
                return $requestData;
            }

            // Generate order request data from OrderSyncService
            $orderData = $this->orderSyncService->generateOrderRequest($order);
            
            if (!$orderData) {
                $this->logger->warning('HolestPay AJAX Payment - Failed to generate order data for order: ' . $orderIncrementId);
                return $requestData;
            }

            // Deep merge: orderData takes precedence over requestData
            $mergedData = $this->deepMerge($orderData, $requestData);
            
            $this->logger->info('HolestPay AJAX Payment - Refilled request data from order', [
                'order_id' => $order->getId(),
                'order_increment_id' => $orderIncrementId,
                'merged_fields' => array_keys($mergedData)
            ]);

            return $mergedData;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay AJAX Payment - Error refilling request data: ' . $e->getMessage());
            return $requestData;
        }
    }

    /**
     * Deep merge two arrays, with second array taking precedence
     *
     * @param array $array1 Base array
     * @param array $array2 Override array
     * @return array
     */
    private function deepMerge($array1, $array2)
    {
        $result = $array1;

        foreach ($array2 as $key => $value) {
            if (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                $result[$key] = $this->deepMerge($result[$key], $value);
            } else {
                // Only override if the value in array2 is not empty
                if (!empty($value) || $value === 0 || $value === '0') {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

}
