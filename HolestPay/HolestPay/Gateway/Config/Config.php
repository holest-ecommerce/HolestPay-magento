<?php
namespace HolestPay\HolestPay\Gateway\Config;

use Magento\Payment\Gateway\Config\Config as BaseConfig;

class Config extends BaseConfig
{
    const METHOD_CODE = 'holestpay';

    public function __construct(\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig)
    {
        parent::__construct($scopeConfig, self::METHOD_CODE);
    }
}


