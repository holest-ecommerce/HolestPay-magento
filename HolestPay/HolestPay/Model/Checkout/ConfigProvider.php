<?php
namespace HolestPay\HolestPay\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ConfigProvider implements ConfigProviderInterface
{
    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function getConfig()
    {
        $config = [
            'payment' => [
                'holestpay' => [
                    'title' => $this->getConfigValue('payment/holestpay/title'),
                    'environment' => $this->getConfigValue('payment/holestpay/environment'),
                ]
            ]
        ];
        return $config;
    }

    private function getConfigValue($path)
    {
        return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}


