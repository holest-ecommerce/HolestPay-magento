<?php
namespace HEC\HolestPay\Model\ResourceModel\Configuration;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \HEC\HolestPay\Model\Configuration::class,
            \HEC\HolestPay\Model\ResourceModel\Configuration::class
        );
    }
}
