<?php
namespace HEC\HolestPay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use HEC\HolestPay\Model\SignatureHelper;

class OrderSyncService
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        Json $json,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Check if order should be synced to HolestPay
     *
     * @param OrderInterface $order
     * @param bool $isStatusChange
     * @return bool
     */
    public function shouldSyncOrder(OrderInterface $order, bool $isStatusChange = false): bool
    {
        // Skip syncing if this order is being processed by a webhook to prevent loops
        if ($order->getData('_processing_webhook')) {
            return false;
        }

        // Skip syncing if this order is being processed by a forwarded response to prevent loops
        if ($order->getData('_processing_forwarded_response')) {
            return false;
        }

        // If order has HolestPay UID, always sync
        if ($order->getData('holestpay_uid')) {
            return true;
        }

        // If it's a status change, check if we should manage all orders
        if ($isStatusChange) {
            $manageAllOrders = $this->scopeConfig->getValue('payment/holestpay/manage_all_orders');
            if ($manageAllOrders) {
                return true;
            }
        }

        // Check if there are enabled fiscal methods
        $fiscalMethods = $this->getFiscalMethods();
        if (!empty($fiscalMethods)) {
            foreach ($fiscalMethods as $method) {
                if (isset($method['Enabled']) && $method['Enabled']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sync order to HolestPay
     *
     * @param OrderInterface $order
     * @param string|null $withStatus
     * @param bool $noResultAwait
     * @return array|false
     */
    public function syncOrder(OrderInterface $order, ?string $withStatus = null, bool $noResultAwait = false)
    {
        try {
            if (!$this->shouldSyncOrder($order)) {
                $this->logger->warning('HolestPay: Order sync skipped - conditions not met', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'reason' => 'Processing flag set or other conditions not met'
                ]);
                return false;
            }

            $requestData = $this->generateOrderRequest($order, $withStatus);
            if (!$requestData) {
                throw new \Exception('Failed to generate order request data');
            }

            // Sign the request data using existing Payment controller method
            $this->signRequestData($requestData);

            $response = $this->callHolestPayApi('store', ['request_data' => $requestData], !$noResultAwait);

            if ($noResultAwait) {
                return $response;
            }

            if (isset($response['error'])) {
                $this->logger->error('HolestPay: Order sync error', $response);
                return false;
            }

            if (!$response || !isset($response['status']) || !isset($response['request_time'])) {
                $this->logger->error('HolestPay: Invalid response from order sync', [
                    'response' => $response
                ]);
                return false;
            }

            $this->logger->warning('HolestPay: Order synced successfully', [
                'order_id' => $order->getId(),
                'increment_id' => $order->getIncrementId(),
                'response' => $response
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error syncing order', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $order->getId()
            ]);
            return false;
        }
    }

    /**
     * Generate order request data for HolestPay
     *
     * @param OrderInterface $order
     * @param string|null $withStatus
     * @return array|false
     */
    private function generateOrderRequest(OrderInterface $order, ?string $withStatus = null)
    {
        try {
            $requestData = [
                'order_uid' => $order->getData('holestpay_uid') ?: $order->getIncrementId(),
                'order_name' => $order->getIncrementId(),
                'order_amount' => (float)$order->getGrandTotal(),
                'order_currency' => $order->getOrderCurrencyCode(),
                'request_time' => time(),
                'transaction_uid' => '',
                'vault_token_uid' => '',
                'subscription_uid' => '',
                'rand' => uniqid('rnd'),
                'merchant_site_uid' => $this->scopeConfig->getValue('payment/holestpay/merchant_site_uid')
            ];

            // Only add status field if explicitly provided
            if ($withStatus !== null) {
                $requestData['status'] = $withStatus;
            }

            // Validate merchant site UID
            if (empty($requestData['merchant_site_uid'])) {
                throw new \Exception('Merchant site UID not configured');
            }

            // Add order items
            $items = [];
            
            // Add product items
            foreach ($order->getAllItems() as $item) {
                if ($item->getParentItem()) {
                    continue;
                }
                
                $items[] = [
                    'posuid' => $item->getProductId(),
                    'type' => 'product',
                    'name' => $item->getName(),
                    'sku' => $item->getSku(),
                    'qty' => (int)$item->getQtyOrdered(),
                    'price' => (float)$item->getPrice(),
                    'subtotal' => (float)$item->getRowTotal(),
                    'tax_amount' => (float)$item->getTaxAmount(),
                    'virtual' => $item->getProduct() ? $item->getProduct()->getIsVirtual() : false
                ];
            }
            
            // Add shipping items
            if ($order->getShippingAmount() > 0) {
                $items[] = [
                    'posuid' => $order->getShippingMethod() ?: 'shipping',
                    'type' => 'shipping',
                    'name' => $order->getShippingDescription() ?: 'Shipping',
                    'sku' => $order->getShippingMethod() ?: 'shipping',
                    'qty' => 1,
                    'price' => (float)$order->getShippingAmount(),
                    'subtotal' => (float)$order->getShippingAmount(),
                    'tax_amount' => (float)$order->getShippingTaxAmount(),
                    'virtual' => true
                ];
            }
            
            // Add fee items
            foreach ($order->getItems() as $item) {
                if ($item->getProductType() === 'fee') {
                    $items[] = [
                        'posuid' => $item->getItemId(),
                        'type' => 'fee',
                        'name' => $item->getName(),
                        'sku' => $item->getName(),
                        'qty' => 1,
                        'price' => (float)$item->getPrice(),
                        'subtotal' => (float)$item->getRowTotal(),
                        'tax_amount' => (float)$item->getTaxAmount(),
                        'virtual' => true
                    ];
                }
            }
            
            $requestData['order_items'] = $items;

            // Add billing address
            if ($order->getBillingAddress()) {
                $billing = $order->getBillingAddress();
                $requestData['order_billing'] = [
                    'email' => $billing->getEmail(),
                    'first_name' => $billing->getFirstname(),
                    'last_name' => $billing->getLastname(),
                    'phone' => $billing->getTelephone(),
                    'is_company' => !empty($billing->getCompany()) ? 1 : 0,
                    'company' => $billing->getCompany(),
                    'company_tax_id' => '',
                    'company_reg_id' => '',
                    'address' => $billing->getStreet() ? (is_array($billing->getStreet()) ? implode(' ', $billing->getStreet()) : $billing->getStreet()) : '',
                    'address2' => '',
                    'city' => $billing->getCity(),
                    'country' => $billing->getCountryId(),
                    'state' => $billing->getRegion(),
                    'postcode' => $billing->getPostcode(),
                    'lang' => $this->scopeConfig->getValue('general/locale/code') ?: 'en_US'
                ];
            }

            // Add shipping address
            if ($order->getShippingAddress()) {
                $shipping = $order->getShippingAddress();
                $requestData['order_shipping'] = [
                    'shippable' => true,
                    'is_cod' => $order->getPayment() && $order->getPayment()->getMethod() === 'cashondelivery',
                    'first_name' => $shipping->getFirstname(),
                    'last_name' => $shipping->getLastname(),
                    'phone' => $shipping->getTelephone(),
                    'company' => $shipping->getCompany(),
                    'address' => $shipping->getStreet() ? (is_array($shipping->getStreet()) ? implode(' ', $shipping->getStreet()) : $shipping->getStreet()) : '',
                    'address2' => '',
                    'city' => $shipping->getCity(),
                    'country' => $shipping->getCountryId(),
                    'state' => $shipping->getRegion(),
                    'postcode' => $shipping->getPostcode()
                ];
            }



            // Add order site data
            $requestData['order_sitedata'] = [
                'id' => $order->getId(),
                'customer_id' => $order->getCustomerId(),
                'payment_method_id' => $order->getPayment() ? $order->getPayment()->getMethod() : '',
                'shipping_method_id' => $order->getShippingMethod()
            ];

            // Add shipping method if it's a HolestPay shipping method
            $shippingMethod = $order->getShippingMethod();
            if ($shippingMethod && strpos($shippingMethod, 'holestpay_') === 0) {
                // Extract the method ID from the shipping method code
                // Format: holestpay_<HPaySiteMethodId>
                $methodId = str_replace('holestpay_', '', $shippingMethod);
                if (is_numeric($methodId)) {
                    $requestData['shipping_method'] = (int)$methodId;
                    $this->logger->warning('HolestPay: Added shipping_method to order sync request', [
                        'order_id' => $order->getId(),
                        'shipping_method' => $shippingMethod,
                        'hpay_method_id' => $methodId
                    ]);
                }
            }



            return $requestData;

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error generating order request', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId()
            ]);
            return false;
        }
    }

    /**
     * Map Magento order status to HPay status
     *
     * @param OrderInterface $order
     * @return string|null
     */
    private function mapOrderStatusToHPayStatus(OrderInterface $order): ?string
    {
        $status = $order->getStatus();
        
        switch ($status) {
            case 'canceled':
                return 'PAYMENT:CANCELED';
            case 'closed':
                return 'PAYMENT:REFUNDED';
            case 'processing':
                return 'PAYMENT:PAID';
            case 'pending_payment':
                return 'PAYMENT:AWAITING';
            default:
                return null;
        }
    }

    /**
     * Format address for HolestPay
     *
     * @param \Magento\Sales\Model\Order\Address $address
     * @return string
     */
    private function formatAddress($address): string
    {
        $parts = [];
        
        if ($address->getStreet()) {
            $street = is_array($address->getStreet()) ? $address->getStreet() : [$address->getStreet()];
            $parts[] = implode(' ', $street);
        }
        
        if ($address->getCity()) {
            $parts[] = $address->getCity();
        }
        
        if ($address->getRegion()) {
            $parts[] = $address->getRegion();
        }
        
        if ($address->getPostcode()) {
            $parts[] = $address->getPostcode();
        }
        
        return implode(', ', array_filter($parts));
    }



    /**
     * Get fiscal methods from configuration
     *
     * @return array
     */
    private function getFiscalMethods(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $configTable = $this->resourceConnection->getTableName('core_config_data');
            
            $select = $connection->select()
                ->from($configTable, ['value'])
                ->where('path LIKE ?', 'holestpay/fiscal/%')
                ->where('scope = ?', 'default');
            
            $result = $connection->fetchCol($select);
            
            if (!empty($result)) {
                foreach ($result as $value) {
                    try {
                        $decoded = $this->json->unserialize($value);
                        if (is_array($decoded) && isset($decoded['fiscal'])) {
                            return $decoded['fiscal'];
                        }
                    } catch (\Exception $e) {
                        // Skip invalid JSON
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error getting fiscal methods', [
                'error' => $e->getMessage()
            ]);
        }
        
        return [];
    }

    /**
     * Sign request data using SignatureHelper
     *
     * @param array $data
     * @return void
     */
    private function signRequestData(array &$data): void
    {
        try {
            // Use the static SignatureHelper
            $signedData = SignatureHelper::signRequestData($data, $this->scopeConfig);
            
            // Copy the signed data back to our array
            $data['verificationhash'] = $signedData['verificationhash'];
            
            $this->logger->warning('HolestPay: Request data signed successfully using SignatureHelper', [
                'order_id' => $data['order_sitedata']['id'],
                'signature_length' => strlen($signedData['verificationhash'])
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error signing request data', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Call HolestPay API
     *
     * @param string $endpoint
     * @param array $data
     * @param bool $waitForResult
     * @return array|false
     */
    private function callHolestPayApi(string $endpoint, array $data, bool $waitForResult = true)
    {
        try {
            // Get environment to determine base URL
            $environment = $this->scopeConfig->getValue('payment/holestpay/environment') ?: 'sandbox';
            
            // Set base URL based on environment
            if ($environment === 'production') {
                $baseUrl = 'https://pay.holest.com';
            } else {
                $baseUrl = 'https://sandbox.pay.holest.com';
            }
            
            $url = $baseUrl . '/clientpay/store';
            
            $this->curl->setOption(CURLOPT_TIMEOUT, $waitForResult ? 30 : 5);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_POST, true);
            $this->curl->setOption(CURLOPT_POSTFIELDS, $this->json->serialize($data));
            $this->curl->setOption(CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            
            $this->curl->post($url, $data);
            
            $response = $this->curl->getBody();
            $httpCode = $this->curl->getStatus();
            
            if ($httpCode !== 200) {
                $this->logger->error('HolestPay: API call failed', [
                    'endpoint' => $endpoint,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                return false;
            }
            
            return $this->json->unserialize($response);
            
        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error calling API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
