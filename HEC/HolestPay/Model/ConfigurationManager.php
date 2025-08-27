<?php
namespace HEC\HolestPay\Model;

use HEC\HolestPay\Model\ConfigurationFactory;
use HEC\HolestPay\Model\ResourceModel\Configuration\CollectionFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Exception\LocalizedException;

class ConfigurationManager
{
    /** @var ConfigurationFactory */
    private $configurationFactory;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var JsonSerializer */
    private $jsonSerializer;

    public function __construct(
        ConfigurationFactory $configurationFactory,
        CollectionFactory $collectionFactory,
        JsonSerializer $jsonSerializer
    ) {
        $this->configurationFactory = $configurationFactory;
        $this->collectionFactory = $collectionFactory;
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * Save configuration for specific environment
     */
    public function saveConfiguration(string $environment, array $data): bool
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('environment', $environment);
            
            if ($collection->getSize() > 0) {
                // Update existing configuration
                $configuration = $collection->getFirstItem();
            } else {
                // Create new configuration
                $configuration = $this->configurationFactory->create();
                $configuration->setEnvironment($environment);
                $configuration->setCreatedAt(date('Y-m-d H:i:s'));
            }
            
            $configuration->setConfigurationData($this->jsonSerializer->serialize($data));
            $configuration->setUpdatedAt(date('Y-m-d H:i:s'));
            $configuration->save();
            
            return true;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to save configuration: %1', $e->getMessage()));
        }
    }

    /**
     * Get configuration for specific environment
     */
    public function getConfiguration(string $environment): ?array
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('environment', $environment);
            
            if ($collection->getSize() === 0) {
                return null;
            }
            
            $configuration = $collection->getFirstItem();
            $data = $configuration->getConfigurationData();
            
            if (!$data) {
                return null;
            }
            
            return $this->jsonSerializer->unserialize($data);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all configurations
     */
    public function getAllConfigurations(): array
    {
        try {
            $collection = $this->collectionFactory->create();
            $configurations = [];
            
            foreach ($collection as $item) {
                $environment = $item->getEnvironment();
                $data = $item->getConfigurationData();
                
                if ($data) {
                    $configurations[$environment] = $this->jsonSerializer->unserialize($data);
                }
            }
            
            return $configurations;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Delete configuration for specific environment
     */
    public function deleteConfiguration(string $environment): bool
    {
        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('environment', $environment);
            
            if ($collection->getSize() === 0) {
                return false;
            }
            
            $configuration = $collection->getFirstItem();
            $configuration->delete();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
