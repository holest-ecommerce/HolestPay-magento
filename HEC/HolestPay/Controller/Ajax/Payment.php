<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use HEC\HolestPay\Model\SignatureHelper;

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
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param Json $json
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        Json $json,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->json = $json;
        $this->scopeConfig = $scopeConfig;
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
            error_log('HolestPay AJAX Payment - Received data: ' . json_encode($requestData));
            
            // Log shipping method if present
            if (isset($requestData['shipping_method'])) {
                error_log('HolestPay AJAX Payment - Shipping method included: ' . $requestData['shipping_method']);
            }
            
            // Check if request_data wrapper exists (common in AJAX requests)
            if (isset($requestData['request_data']) && is_array($requestData['request_data'])) {
                $requestData = $requestData['request_data'];
                error_log('HolestPay AJAX Payment - Extracted from request_data: ' . json_encode($requestData));
            }
            
            // Validate that required fields are present
            if (empty($requestData['order_uid'])) {
                throw new LocalizedException(__('order_uid is required in request data'));
            }
            if (empty($requestData['order_amount'])) {
                throw new LocalizedException(__('order_amount is required in request data'));
            }
            if (empty($requestData['order_currency'])) {
                throw new LocalizedException(__('order_currency is required in request data'));
            }

            // Sign the payment request
            $signedRequest = $this->signPaymentRequest($requestData);
            
            error_log('HolestPay AJAX Payment - Successfully signed request: ' . json_encode($signedRequest));
            
            return $result->setData([
                'success' => true,
                'signed_request' => $signedRequest
            ]);
            
        } catch (\Exception $e) {
            error_log('HolestPay AJAX Payment - Error: ' . $e->getMessage());
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



}
