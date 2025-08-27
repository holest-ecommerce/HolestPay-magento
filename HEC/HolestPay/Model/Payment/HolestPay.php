<?php
namespace HEC\HolestPay\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;
use Psr\Log\LoggerInterface;
class HolestPay extends AbstractMethod
{
    const PAYMENT_METHOD_CODE = 'holestpay';
    
    protected $_code = 'holestpay';

    protected $_isOffline = false;

    protected $_isInitializeNeeded = false;

    // Payment action - authorize first, capture later
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundPartial = true;
    protected $_canVoid = true;
    protected $_canCancelInvoice = true;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_logger = $logger;
        
        // Always log that the payment method is being constructed
        error_log('HolestPay payment method constructed with code: ' . $this->_code);
        
        $this->_logger->debug([
            'message' => 'HolestPay payment method constructed',
            'code' => $this->_code,
            'isActive' => $this->isActive()
        ]);
    }

    /**
     * Authorize payment
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // TODO: replace with your authorize API call
        // Example: $payment->setTransactionId('auth-xxx')->setIsTransactionClosed(false);
        $this->_logger->debug([
            'message' => 'HolestPay: Authorizing payment',
            'amount' => $amount
        ]);
        return $this;
    }

    /**
     * Capture payment
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // TODO: replace with your capture API call; set transaction info
        // Example: $payment->setTransactionId('capture-xxx')->setIsTransactionClosed(false);
        $this->_logger->debug([
            'message' => 'HolestPay: Capturing payment',
            'amount' => $amount
        ]);
        return $this;
    }

    /**
     * Refund payment
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // TODO: replace with your refund API call
        // Example: $payment->setTransactionId('refund-xxx')->setIsTransactionClosed(false);
        $this->_logger->debug([
            'message' => 'HolestPay: Refunding payment',
            'amount' => $amount
        ]);
        return $this;
    }

    /**
     * Void payment
     */
    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        // TODO: replace with your void API call
        // Example: $payment->setTransactionId('void-xxx')->setIsTransactionClosed(true);
        $this->_logger->debug([
            'message' => 'HolestPay: Voiding payment'
        ]);
        return $this;
    }

    /**
     * Cancel invoice
     */
    public function cancelInvoice(\Magento\Payment\Model\InfoInterface $payment)
    {
        // TODO: replace with your cancel API call
        $this->_logger->debug([
            'message' => 'HolestPay: Canceling invoice'
        ]);
        return $this;
    }

    public function canCapture()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        // Enable capture when status allows it
        return in_array($status, ['authorized', 'pending_capture', 'chargeable'], true);
    }

    public function canRefund()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        return in_array($status, ['captured', 'settled', 'partially_refunded'], true);
    }

    public function canVoid()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        return in_array($status, ['authorized', 'pending_capture'], true);
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->debug([
            'message' => 'HolestPay isAvailable called',
            'quote_id' => $quote ? $quote->getId() : 'no_quote',
            'store_id' => $quote ? $quote->getStoreId() : 'no_store'
        ]);
        
        // Check if the method is active
        $isActive = $this->isActive($quote ? $quote->getStoreId() : null);
        $this->_logger->debug([
            'message' => 'HolestPay isActive check',
            'isActive' => $isActive,
            'method_code' => $this->_code,
            'config_path' => 'payment/' . $this->_code . '/active'
        ]);
        
        if (!$isActive) {
            $this->_logger->debug([
                'message' => 'HolestPay is not available: Method is not active.'
            ]);
            return false;
        }

        // Check minimum order total
        $minTotal = $this->getConfigData('min_order_total');
        if ($minTotal && $quote && $quote->getGrandTotal() < $minTotal) {
            $this->_logger->debug([
                'message' => 'HolestPay is not available: Grand total is less than minimum.',
                'grand_total' => $quote->getGrandTotal(),
                'min_total' => $minTotal
            ]);
            return false;
        }

        // Check maximum order total
        $maxTotal = $this->getConfigData('max_order_total');
        if ($maxTotal && $quote && $quote->getGrandTotal() > $maxTotal) {
            $this->_logger->debug([
                'message' => 'HolestPay is not available: Grand total is more than maximum.',
                'grand_total' => $quote->getGrandTotal(),
                'max_total' => $maxTotal
            ]);
            return false;
        }

        // Check allowed countries
        $allowedCountries = $this->getConfigData('specificcountry');
        if ($allowedCountries && $quote && $quote->getBillingAddress()) {
            $countryId = $quote->getBillingAddress()->getCountryId();
            if (!in_array($countryId, explode(',', $allowedCountries))) {
                $this->_logger->debug([
                    'message' => 'HolestPay is not available: Country is not allowed.',
                    'country_id' => $countryId,
                    'allowed_countries' => $allowedCountries
                ]);
                return false;
            }
        }

        // All checks passed
        $this->_logger->debug([
            'message' => 'HolestPay is available!'
        ]);
        return true;
    }

    /**
     * Get payment method title
     */
    public function getTitle()
    {
        $title = $this->getConfigData('title');
        $this->_logger->debug([
            'message' => 'HolestPay getTitle called, returning: ' . $title
        ]);
        return $title;
    }
}


