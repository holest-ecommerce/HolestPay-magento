<?php
namespace HEC\HolestPay\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Configuration extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('holestpay_configuration', 'config_id');
    }
}
