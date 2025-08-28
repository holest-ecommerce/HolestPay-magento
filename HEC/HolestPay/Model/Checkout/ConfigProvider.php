<?php

namespace HEC\HolestPay\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use HEC\HolestPay\Model\Payment\HolestPay as HolestPayMethod;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class ConfigProvider implements ConfigProviderInterface
{
    use DebugLogTrait;

	protected $methodCode = 'holestpay';
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var HolestPayMethod
     */
    protected $method;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        HolestPayMethod $method,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->method = $method;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        // Force log this being called
        $this->debugLog('=== HolestPay ConfigProvider: getConfig() called ===');
        
        // Return a simple hardcoded configuration for testing
        $config = [
            'payment' => [
                'holestpay' => [
                    'title' => $this->method->getTitle(),
                    'environment' => $this->method->getConfigData('environment'),
                    'merchant_site_uid' => $this->method->getConfigData('merchant_site_uid'),
                    'isAvailable' => true,
                    'isActive' => $this->method->isActive(),
                    'methodCode' => $this->method->getCode(),
                    'insertFooterLogotypes' => (bool) $this->method->getConfigData('insert_footer_logotypes')
                ]
            ]
        ];
        
        $this->debugLog('=== HolestPay ConfigProvider: Returning: ' . json_encode($config) . ' ===');
        
        return $config;
    }
}