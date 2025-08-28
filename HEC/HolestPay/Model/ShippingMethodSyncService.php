<?php
namespace HEC\HolestPay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class ShippingMethodSyncService
{
    use DebugLogTrait;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Sync HolestPay shipping methods
     * This updates the main shipping carrier configuration and stores methods in custom table
     *
     * @param array $shippingMethods
     * @return bool
     */
    public function syncShippingMethods(array $shippingMethods): bool
    {
        try {
            $this->debugWarning('Starting shipping methods sync', [
                'count' => count($shippingMethods)
            ]);

            // Check if there are any enabled shipping methods
            $hasEnabledMethods = false;
            foreach ($shippingMethods as $method) {
                if (isset($method['Enabled']) && $method['Enabled']) {
                    $hasEnabledMethods = true;
                    break;
                }
            }

            // Update main shipping carrier configuration
            $this->updateShippingCarrierConfig($hasEnabledMethods);

            // Store shipping methods in custom table (not core_config_data)
            $this->storeShippingMethods($shippingMethods);

            $this->debugWarning('Shipping methods sync completed successfully');
            return true;

        } catch (\Exception $e) {
            $this->debugError('Error syncing shipping methods', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    /**
     * Update the main shipping carrier configuration
     *
     * @param bool $hasEnabledMethods
     * @return void
     */
    private function updateShippingCarrierConfig(bool $hasEnabledMethods): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $configTable = $this->resourceConnection->getTableName('core_config_data');

            // Update shipping carrier active status
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/active', $hasEnabledMethods ? '1' : '0');
            
            // Set title
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/title', 'HolestPay Shipping');
            
            // Set sort order to 30
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/sort_order', '30');
            
            // Set other required fields
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/sallowspecific', '0');
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/showmethod', '1');
            $this->updateConfigValue($connection, $configTable, 'carriers/holestpay/specificerrmsg', 'This shipping method is not available. To use this shipping method, please contact us.');

            $this->debugWarning('Updated shipping carrier configuration', [
                'active' => $hasEnabledMethods ? '1' : '0',
                'has_enabled_methods' => $hasEnabledMethods
            ]);

        } catch (\Exception $e) {
            $this->debugError('Error updating shipping carrier config', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Store shipping methods in custom table
     *
     * @param array $shippingMethods
     * @return void
     */
    private function storeShippingMethods(array $shippingMethods): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('holestpay_shipping_methods');

            // Create table if it doesn't exist
            $this->createShippingMethodsTable($connection, $tableName);

            // Clear existing methods
            $connection->delete($tableName);

            // Insert new methods
            foreach ($shippingMethods as $method) {
                if (isset($method['HPaySiteMethodId']) && isset($method['Uid'])) {
                    $methodData = [
                        'hpay_id' => $method['HPaySiteMethodId'],
                        'uid' => $method['Uid'],
                        'name' => $method['Name'] ?? $method['Uid'],
                        'description' => $method['Description'] ?? '',
                        'enabled' => isset($method['Enabled']) && $method['Enabled'] ? 1 : 0,
                        'price_table' => $this->json->serialize($method['Price Table'] ?? []),
                        'after_max_weight_price_per_kg' => $method['After Max Weight Price Per Kg'] ?? '0.00',
                        'free_above_order_amount' => $method['Free Above Order Amount'] ?? null,
                        'additional_cost' => $method['Additional cost'] ?? '0.00',
                        'cod_cost' => $method['COD cost'] ?? '0.00',
                        'shipping_currency' => $method['ShippingCurrency'] ?? 'USD',
                        'system_title' => $method['SystemTitle'] ?? $method['Uid'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    $connection->insert($tableName, $methodData);
                }
            }

            $this->debugWarning('Stored shipping methods in custom table', [
                'count' => count($shippingMethods)
            ]);

        } catch (\Exception $e) {
            $this->debugError('Error storing shipping methods', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create shipping methods table if it doesn't exist
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $tableName
     * @return void
     */
    private function createShippingMethodsTable($connection, string $tableName): void
    {
        $tableExists = $connection->isTableExists($tableName);
        
        if (!$tableExists) {
            $table = $connection->newTable($tableName)
                ->addColumn(
                    'id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'ID'
                )
                ->addColumn(
                    'hpay_id',
                    \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false],
                    'HolestPay Method ID'
                )
                ->addColumn(
                    'uid',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Method UID'
                )
                ->addColumn(
                    'name',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Method Name'
                )
                ->addColumn(
                    'description',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    null,
                    ['nullable' => true],
                    'Description'
                )
                ->addColumn(
                    'enabled',
                    \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                    null,
                    ['unsigned' => true, 'nullable' => false, 'default' => '0'],
                    'Enabled'
                )
                ->addColumn(
                    'price_table',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    null,
                    ['nullable' => true],
                    'Price Table (JSON)'
                )
                ->addColumn(
                    'after_max_weight_price_per_kg',
                    \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL,
                    '10,2',
                    ['nullable' => false, 'default' => '0.00'],
                    'After Max Weight Price Per Kg'
                )
                ->addColumn(
                    'free_above_order_amount',
                    \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL,
                    '10,2',
                    ['nullable' => true],
                    'Free Above Order Amount'
                )
                ->addColumn(
                    'additional_cost',
                    \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL,
                    '10,2',
                    ['nullable' => false, 'default' => '0.00'],
                    'Additional Cost'
                )
                ->addColumn(
                    'cod_cost',
                    \Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL,
                    '10,2',
                    ['nullable' => false, 'default' => '0.00'],
                    'COD Cost'
                )
                ->addColumn(
                    'shipping_currency',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    10,
                    ['nullable' => false, 'default' => 'USD'],
                    'Shipping Currency'
                )
                ->addColumn(
                    'system_title',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    255,
                    ['nullable' => true],
                    'System Title'
                )
                ->addColumn(
                    'created_at',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addColumn(
                    'updated_at',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT_UPDATE],
                    'Updated At'
                )
                ->addIndex(
                    $connection->getIndexName($tableName, ['hpay_id']),
                    ['hpay_id'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->addIndex(
                    $connection->getIndexName($tableName, ['uid']),
                    ['uid'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->setComment('HolestPay Shipping Methods');

            $connection->createTable($table);
        }
    }

    /**
     * Update configuration value
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $tableName
     * @param string $path
     * @param string $value
     * @return void
     */
    private function updateConfigValue($connection, string $tableName, string $path, string $value): void
    {
        // Check if config already exists
        $select = $connection->select()
            ->from($tableName, ['config_id'])
            ->where('path = ?', $path)
            ->where('scope = ?', 'default');

        $existing = $connection->fetchOne($select);

        if ($existing) {
            // Update existing
            $connection->update(
                $tableName,
                ['value' => $value],
                ['config_id = ?' => $existing]
            );
        } else {
            // Insert new
            $connection->insert($tableName, [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => $path,
                'value' => $value
            ]);
        }
    }

    /**
     * Calculate shipping cost based on HolestPay method data
     *
     * @param array $methodData
     * @param float $weight
     * @param float $cartAmount
     * @param string $cartCurrency
     * @param bool $isCod
     * @return float
     */
    public function calculateShippingCost(
        array $methodData,
        float $weight,
        float $cartAmount,
        string $cartCurrency,
        bool $isCod = false
    ): float {
        try {
            $cost = 0.00;

            // Check if shipping is free above certain order amount
            if (isset($methodData['free_above_order_amount']) && $methodData['free_above_order_amount']) {
                $freeThreshold = (float)$methodData['free_above_order_amount'];
                if ($cartAmount >= $freeThreshold) {
                    return 0.00;
                }
            }

            // Calculate cost based on weight and price table
            if (isset($methodData['price_table']) && !empty($methodData['price_table'])) {
                $priceTable = $methodData['price_table'];
                
                // Sort by max weight
                usort($priceTable, function($a, $b) {
                    return (int)$a['MaxWeight'] - (int)$b['MaxWeight'];
                });

                $costFound = false;
                $maxCost = 0;
                $maxWeight = 0;

                foreach ($priceTable as $weightRate) {
                    if ($weight <= (int)$weightRate['MaxWeight']) {
                        $cost = (float)$weightRate['Price'];
                        $costFound = true;
                        break;
                    }
                    $maxCost = (float)$weightRate['Price'];
                    $maxWeight = (int)$weightRate['MaxWeight'];
                }

                // If weight exceeds max in table, calculate additional cost
                if (!$costFound && isset($methodData['after_max_weight_price_per_kg'])) {
                    $additionalWeight = $weight - $maxWeight;
                    $additionalCost = ($additionalWeight / 1000) * (float)$methodData['after_max_weight_price_per_kg'];
                    $cost = $maxCost + $additionalCost;
                }
            }

            // Add COD cost if applicable
            if ($isCod && isset($methodData['cod_cost']) && $methodData['cod_cost']) {
                $codCost = $methodData['cod_cost'];
                if (strpos($codCost, '%') !== false) {
                    $percentage = (float)str_replace(['%', ' '], '', $codCost);
                    $cost *= (1.00 + $percentage / 100);
                } else {
                    $cost += (float)$codCost;
                }
            }

            // Add additional cost
            if (isset($methodData['additional_cost']) && $methodData['additional_cost']) {
                $additionalCost = $methodData['additional_cost'];
                if (strpos($additionalCost, '%') !== false) {
                    $percentage = (float)str_replace(['%', ' '], '', $additionalCost);
                    $cost *= (1.00 + $percentage / 100);
                } else {
                    $cost += (float)$additionalCost;
                }
            }

            // Apply price multiplication based on cart amount
            if (isset($methodData['price_multiplication']) && !empty($methodData['price_multiplication'])) {
                $priceMultiplication = $methodData['price_multiplication'];
                
                // Sort by minimum cart total
                usort($priceMultiplication, function($a, $b) {
                    return (float)($a['MinCartTotal'] ?? 0) - (float)($b['MinCartTotal'] ?? 0);
                });
                
                $multiplication = 1.00;
                foreach ($priceMultiplication as $cartAmtLevel) {
                    if (empty($cartAmtLevel['MinCartTotal']) || !is_numeric($cartAmtLevel['MinCartTotal'])) {
                        continue;
                    }
                    
                    $minCartTotal = (float)$cartAmtLevel['MinCartTotal'];
                    if ($cartAmount >= $minCartTotal) {
                        if (!empty($cartAmtLevel['Multiplication']) && is_numeric($cartAmtLevel['Multiplication'])) {
                            $multiplication = (float)$cartAmtLevel['Multiplication'];
                        } else {
                            $multiplication = 1.00;
                        }
                    }
                }
                
                if ($multiplication != 1.00) {
                    $cost = $cost * $multiplication;
                }
            }

            return round($cost, 2);

        } catch (\Exception $e) {
            $this->debugError('Error calculating shipping cost', [
                'error' => $e->getMessage(),
                'method_data' => $methodData
            ]);
            return 0.00;
        }
    }

    /**
     * Get all available HolestPay shipping methods from custom table
     *
     * @return array
     */
    public function getAvailableShippingMethods(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('holestpay_shipping_methods');

            if (!$connection->isTableExists($tableName)) {
                return [];
            }

            $select = $connection->select()
                ->from($tableName, ['*'])
                ->where('enabled = ?', 1)
                ->order('hpay_id ASC');

            $result = $connection->fetchAll($select);

            // Unserialize price table
            foreach ($result as &$method) {
                if (isset($method['price_table'])) {
                    try {
                        $method['price_table'] = $this->json->unserialize($method['price_table']);
                    } catch (\Exception $e) {
                        $method['price_table'] = [];
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            $this->debugError('Error getting available shipping methods', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
