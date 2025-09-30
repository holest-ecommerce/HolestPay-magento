<?php
namespace HEC\HolestPay\Block\Frontend;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Locale\Resolver as LocaleResolver;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use HEC\HolestPay\Model\ConfigurationManager;

class CheckoutContext extends Template
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var LocaleResolver
     */
    private $localeResolver;

    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    public function __construct(
        Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        LocaleResolver $localeResolver,
        JsonSerializer $jsonSerializer,
        ConfigurationManager $configurationManager,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->localeResolver = $localeResolver;
        $this->jsonSerializer = $jsonSerializer;
        $this->configurationManager = $configurationManager;
        parent::__construct($context, $data);
    }

    /**
     * Get POS configuration based on current environment
     *
     * @return array|null
     */
    public function getPosConfiguration(): ?array
    {
        $environment = $this->getCurrentEnvironment();
        if (!$environment) {
            return null;
        }

        return $this->configurationManager->getConfiguration($environment);
    }

    /**
     * Get current environment
     *
     * @return string|null
     */
    public function getCurrentEnvironment(): ?string
    {
        return $this->scopeConfig->getValue('payment/holestpay/environment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get site base URL
     *
     * @return string
     */
    public function getSiteBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
    }

    /**
     * Get current site language
     *
     * @return string
     */
    public function getCurrentLanguage(): string
    {
        return $this->localeResolver->getLocale();
    }

    /**
     * Get HolestPay URL based on environment
     *
     * @return string
     */
    public function getHolestPayUrl(): string
    {
        $environment = $this->getCurrentEnvironment();
        return $environment === 'sandbox' ? 'https://sandbox.pay.holest.com' : 'https://pay.holest.com';
    }

    /**
     * Get cart data
     *
     * @return array
     */
    public function getCartData(): array
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return $this->getEmptyCartData();
        }

        $cartAmount = $quote->getSubtotal();
        $orderAmount = $quote->getGrandTotal();
        $orderCurrency = $quote->getQuoteCurrencyCode();

        $orderItems = [];
        
        // Add products
        foreach ($quote->getAllVisibleItems() as $item) {
            $orderItems[] = [
                'posuid' => $item->getProductId(),
                'type' => 'product',
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'qty' => $item->getQty(),
                'price' => $item->getPrice(),
                'subtotal' => $item->getRowTotal(),
                'tax_label' => '',
                'length' => '',
                'width' => '',
                'height' => '',
                'weight' => $item->getWeight(),
                'split_pay_uid' => '',
                'virtual' => $item->getProduct()->getIsVirtual(),
                'warehouse' => ''
            ];
        }

        // Add fees
        if ($quote->getFeeAmount()) {
            $orderItems[] = [
                'posuid' => 'fee',
                'type' => 'fee',
                'name' => 'Fee',
                'sku' => 'Fee',
                'qty' => 1,
                'price' => $quote->getFeeAmount(),
                'subtotal' => $quote->getFeeAmount(),
                'tax_label' => '',
                'virtual' => true
            ];
        }

        // Add shipping
        if ($quote->getShippingAddress() && $quote->getShippingAddress()->getShippingAmount()) {
            $orderItems[] = [
                'posuid' => 'shipping',
                'type' => 'fee',
                'name' => 'Shipping',
                'sku' => 'Shipping',
                'qty' => 1,
                'price' => $quote->getShippingAddress()->getShippingAmount(),
                'subtotal' => $quote->getShippingAddress()->getShippingAmount(),
                'tax_label' => '',
                'virtual' => false
            ];
        }

        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();

        return [
            'cart_amount' => (string)$cartAmount,
            'order_amount' => (string)$orderAmount,
            'order_currency' => $orderCurrency,
            'order_items' => $orderItems,
            'order_billing' => [
                'email' => $billingAddress ? $billingAddress->getEmail() : '',
                'first_name' => $billingAddress ? $billingAddress->getFirstname() : '',
                'last_name' => $billingAddress ? $billingAddress->getLastname() : '',
                'phone' => $billingAddress ? $billingAddress->getTelephone() : '',
                'is_company' => 0,
                'company' => $billingAddress ? $billingAddress->getCompany() : '',
                'company_tax_id' => '',
                'company_reg_id' => '',
                'address' => $billingAddress ? $billingAddress->getStreetLine(1) : '',
                'address2' => $billingAddress ? $billingAddress->getStreetLine(2) : '',
                'city' => $billingAddress ? $billingAddress->getCity() : '',
                'country' => $billingAddress ? $billingAddress->getCountryId() : '',
                'state' => $billingAddress ? $billingAddress->getRegion() : '',
                'postcode' => $billingAddress ? $billingAddress->getPostcode() : '',
                'lang' => $this->getCurrentLanguage()
            ],
            'order_shipping' => [
                'shippable' => true,
                'is_cod' => false,
                'first_name' => $shippingAddress ? $shippingAddress->getFirstname() : '',
                'last_name' => $shippingAddress ? $shippingAddress->getLastname() : '',
                'phone' => $shippingAddress ? $shippingAddress->getTelephone() : '',
                'company' => $shippingAddress ? $shippingAddress->getCompany() : '',
                'address' => $shippingAddress ? $shippingAddress->getStreetLine(1) : '',
                'address2' => $shippingAddress ? $shippingAddress->getStreetLine(2) : '',
                'city' => $shippingAddress ? $shippingAddress->getCity() : '',
                'country' => $shippingAddress ? $shippingAddress->getCountryId() : '',
                'state' => $shippingAddress ? $shippingAddress->getRegion() : '',
                'postcode' => $shippingAddress ? $shippingAddress->getPostcode() : ''
            ]
        ];
    }

    /**
     * Get empty cart data structure
     *
     * @return array
     */
    private function getEmptyCartData(): array
    {
        return [
            'cart_amount' => '0',
            'order_amount' => '0',
            'order_currency' => $this->storeManager->getStore()->getCurrentCurrencyCode(),
            'order_items' => [],
            'order_billing' => [
                'email' => '',
                'first_name' => '',
                'last_name' => '',
                'phone' => '',
                'is_company' => 0,
                'company' => '',
                'company_tax_id' => '',
                'company_reg_id' => '',
                'address' => '',
                'address2' => '',
                'city' => '',
                'country' => '',
                'state' => '',
                'postcode' => '',
                'lang' => $this->getCurrentLanguage()
            ],
            'order_shipping' => [
                'shippable' => true,
                'is_cod' => false,
                'first_name' => '',
                'last_name' => '',
                'phone' => '',
                'company' => '',
                'address' => '',
                'address2' => '',
                'city' => '',
                'country' => '',
                'state' => '',
                'postcode' => ''
            ]
        ];
    }

    /**
     * Get translated labels
     *
     * @return array
     */
    public function getLabels(): array
    {
        return [
            'error_contact_us' => __('Error, please contact us for assistance.'),
            'remove_token_confirm' => __('Please confirm you want to remove payment token vault reference. If you have subscriptions with us they might get terminated if we fail to charge you for the next billing period.'),
            'error' => __('Error'),
            'result' => __('result'),
            'Order UID' => __('Order UID'),
            'Authorization Code' => __('Authorization Code'),
            'Payment Status' => __('Payment Status'),
            'Transaction Status Code' => __('Transaction Status Code'),
            'Transaction ID' => __('Transaction ID'),
            'Transaction Time' => __('Transaction Time'),
            'Status code for the 3D transaction' => __('Status code for the 3D transaction'),
            'Amount in order currency' => __('Amount in order currency'),
            'Amount in payment currency' => __('Amount in payment currency'),
            'Refunded amount' => __('Refunded amount'),
            'Captured amount' => __('Captured amount'),
            'Installments' => __('Installments'),
            'Installments grace months' => __('Installments grace months'),
            'Recurring interval' => __('Recurring interval'),
            'Recurring interval value' => __('Recurring interval value'),
            'Recurring total payments' => __('Recurring total payments'),
            'Try to pay again' => __('Try to pay again...'),
            'Payment refused, you can try again' => __('Payment refused, you can try again...'),
            'Payment failed, you can try again' => __('Payment failed, you can try again...'),
            'Error in payment request' => __('Error in payment request. Please check your email and contact us!'),
            'No payment response' => __('No valid payment response was received. You can try again!'),
            'Payment has failed' => __('Payment has failed. You can try again!'),
            'Payment refused' => __('Payment refused'),
            'Payment failed' => __('Payment failed'),
            'Payment error' => __('Payment error'),
            'Ordering as a company?' => __('Ordering as a company?'),
            'Company Tax ID' => __('Company Tax ID'),
            'Company Register ID' => __('Company Register ID'),
            'Company Name' => __('Company Name'),
            'Pay' => __('Pay')
        ];
    }

    public function getInsertFooterLogotypes(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'payment/holestpay/insert_footer_logotypes',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get custom frontend CSS
     *
     * @return string
     */
    public function getCustomFrontendCss(): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/holestpay/custom_frontend_css',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get custom frontend JavaScript
     *
     * @return string
     */
    public function getCustomFrontendJs(): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/holestpay/custom_frontend_js',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
