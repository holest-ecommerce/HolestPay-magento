<?php
namespace HEC\HolestPay\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\DataObject\IdentityInterface;

class Configuration extends AbstractModel implements IdentityInterface
{
    const CACHE_TAG = 'holestpay_configuration';

    protected $_cacheTag = 'holestpay_configuration';
    protected $_eventPrefix = 'holestpay_configuration';

    protected function _construct()
    {
        $this->_init(\HEC\HolestPay\Model\ResourceModel\Configuration::class);
    }

    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    public function getEnvironment()
    {
        return $this->getData('environment');
    }

    public function setEnvironment($environment)
    {
        return $this->setData('environment', $environment);
    }

    public function getConfigurationData()
    {
        return $this->getData('data');
    }

    public function setConfigurationData($data)
    {
        return $this->setData('data', $data);
    }

    public function getUpdatedAt()
    {
        return $this->getData('updated_at');
    }

    public function setUpdatedAt($updatedAt)
    {
        return $this->setData('updated_at', $updatedAt);
    }

    public function getCreatedAt()
    {
        return $this->getData('created_at');
    }

    public function setCreatedAt($createdAt)
    {
        return $this->setData('created_at', $createdAt);
    }
}
