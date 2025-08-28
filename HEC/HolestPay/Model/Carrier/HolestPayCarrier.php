<?php
namespace HEC\HolestPay\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class HolestPayCarrier extends AbstractCarrier implements CarrierInterface
{
    use DebugLogTrait;

    /**
     * Code of the carrier
     *
     * @var string
     */
    public const CODE = 'holestpay';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var MethodFactory
     */
    private $rateMethodFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        
        // Get other dependencies via ObjectManager since they're not required by parent
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->rateResultFactory = $objectManager->get(ResultFactory::class);
        $this->rateMethodFactory = $objectManager->get(MethodFactory::class);
        $this->json = $objectManager->get(Json::class);
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        
        // Don't log during construction to avoid dependency injection issues
        // $this->debugWarning('Carrier constructed successfully', [
        //     'active' => $this->getConfigFlag('active'),
        //     'title' => $this->getConfigData('title')
        // ]);
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        $this->debugWarning('getAllowedMethods called');
        
        try {
            $shippingMethods = $this->getHolestPayShippingMethods();
            $this->debugWarning('Raw shipping methods from database', [
                'count' => count($shippingMethods),
                'methods' => $shippingMethods
            ]);
            
            $methods = [];
            
            foreach ($shippingMethods as $methodId => $methodData) {
                $this->debugWarning('Processing method', [
                    'method_id' => $methodId,
                    'method_data' => $methodData,
                    'enabled' => isset($methodData['enabled']) ? $methodData['enabled'] : 'NOT_SET'
                ]);
                
                if (isset($methodData['enabled']) && $methodData['enabled']) {
                    $methodName = $methodData['name'] ?? $methodData['uid'];
                    $methods[$methodId] = $methodName;
                    
                    $this->debugWarning('Method added to allowed methods', [
                        'method_id' => $methodId,
                        'method_name' => $methodName
                    ]);
                } else {
                    $this->debugWarning('Method skipped (not enabled)', [
                        'method_id' => $methodId,
                        'enabled' => $methodData['enabled'] ?? 'NOT_SET'
                    ]);
                }
            }
            
            $this->log('warning', 'getAllowedMethods final result', [
                'final_count' => count($methods),
                'final_methods' => $methods
            ]);
            
            return $methods;
            
        } catch (\Exception $e) {
            $this->log('error', 'Error in getAllowedMethods', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Check if carrier can collect rates
     *
     * @return bool
     */
    public function canCollectRates()
    {
        $active = $this->getConfigFlag('active');
        $this->log('warning', 'canCollectRates check', [
            'active' => $active,
            'config_path' => 'carriers/holestpay/active',
            'config_value' => $this->getConfigData('active')
        ]);
        
        if (!$active) {
            $this->log('warning', 'Carrier is not active in configuration');
            return false;
        }
        
        // Check if we have any enabled shipping methods
        $shippingMethods = $this->getHolestPayShippingMethods();
        $hasMethods = !empty($shippingMethods);
        
        $this->log('warning', 'Shipping methods check', [
            'has_methods' => $hasMethods,
            'method_count' => count($shippingMethods)
        ]);
        
        return $active && $hasMethods;
    }

    /**
     * Collect shipping rates
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->canCollectRates()) {
            $this->log('warning', 'Cannot collect rates - carrier not active');
            return false;
        }

        try {
            $this->log('warning', 'Starting rate collection', [
                'request_weight' => $request->getPackageWeight(),
                'request_value' => $request->getPackageValue(),
                'request_currency' => $request->getPackageCurrency(),
                'request_country_id' => $request->getDestCountryId(),
                'request_region_id' => $request->getDestRegionId(),
                'request_postcode' => $request->getDestPostcode()
            ]);

            $result = $this->rateResultFactory->create();
            $shippingMethods = $this->getHolestPayShippingMethods();

            foreach ($shippingMethods as $methodId => $methodData) {
                if (isset($methodData['enabled']) && $methodData['enabled']) {
                    $rate = $this->rateMethodFactory->create();
                    
                    $rate->setCarrier($this->_code);
                    $rate->setCarrierTitle($this->getConfigData('title'));
                    $rate->setMethod($methodId);
                    $rate->setMethodTitle($methodData['name'] ?? $methodData['uid']);
                    
                    // Calculate shipping cost
                    $cost = $this->calculateShippingCost($methodData, $request);
                    $rate->setPrice($cost);
                    $rate->setCost($cost);
                    
                    // Set additional shipping method data for proper assignment creation
                    $rate->setMethodDescription($methodData['description'] ?? '');
                    $rate->setErrorMessage('');
                    
                    // Set shipping method code that will be used in assignments
                    $methodCode = $this->_code . '_' . $methodId;
                    $rate->setMethodCode($methodCode);
                    
                    $result->append($rate);
                    
                                         $this->log('warning', 'Added shipping rate', [
                         'method_id' => $methodId,
                         'method_code' => $methodCode,
                         'name' => $methodData['name'] ?? $methodData['uid'],
                         'cost' => $cost,
                         'carrier' => $this->_code,
                         'carrier_title' => $this->getConfigData('title')
                     ]);
                }
            }

            $this->log('warning', 'Rate collection completed', [
                'total_rates' => count($result->getAllRates()),
                'available_methods' => $this->getAllowedMethods()
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->log('error', 'Error collecting rates', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }

    /**
     * Get HolestPay shipping methods from custom table
     *
     * @return array
     */
    private function getHolestPayShippingMethods(): array
    {
        try {
            $connection = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\ResourceConnection::class)
                ->getConnection();
            
            $tableName = $connection->getTableName('holestpay_shipping_methods');
            
            if (!$connection->isTableExists($tableName)) {
                $this->log('warning', 'Shipping methods table does not exist yet');
                return [];
            }
            
            $select = $connection->select()
                ->from($tableName, ['*'])
                ->where('enabled = ?', 1)
                ->order('hpay_id ASC');
            
            $result = $connection->fetchAll($select);
            
            $methods = [];
            foreach ($result as $row) {
                $methodId = $row['hpay_id'];
                $methods[$methodId] = [
                    'hpay_id' => $methodId,
                    'uid' => $row['uid'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'enabled' => (bool)$row['enabled'],
                    'price_table' => $this->json->unserialize($row['price_table'] ?? '[]'),
                    'after_max_weight_price_per_kg' => $row['after_max_weight_price_per_kg'],
                    'free_above_order_amount' => $row['free_above_order_amount'],
                    'additional_cost' => $row['additional_cost'],
                    'cod_cost' => $row['cod_cost'],
                    'shipping_currency' => $row['shipping_currency'],
                    'system_title' => $row['system_title']
                ];
            }
            
            $this->log('warning', 'Loaded shipping methods from custom table', [
                'count' => count($methods),
                'method_ids' => array_keys($methods)
            ]);
            
            return $methods;
            
        } catch (\Exception $e) {
            $this->log('error', 'Error loading shipping methods from custom table', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [];
        }
    }

    /**
     * Calculate shipping cost based on HolestPay method data
     *
     * @param array $methodData
     * @param RateRequest $request
     * @return float
     */
    private function calculateShippingCost(array $methodData, RateRequest $request): float
    {
        try {
            $cost = 0.00;
            $weight = $request->getPackageWeight();
            $cartAmount = $request->getPackageValue();
            $cartCurrency = $request->getPackageCurrency();

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
                    return (int)($a['MaxWeight'] ?? 0) - (int)($b['MaxWeight'] ?? 0);
                });

                $costFound = false;
                $maxCost = 0;
                $maxWeight = 0;

                foreach ($priceTable as $weightRate) {
                    if ($weight <= (int)($weightRate['MaxWeight'] ?? 0)) {
                        $cost = (float)($weightRate['Price'] ?? 0);
                        $costFound = true;
                        break;
                    }
                    $maxCost = (float)($weightRate['Price'] ?? 0);
                    $maxWeight = (int)($weightRate['MaxWeight'] ?? 0);
                }

                // If weight exceeds max in table, calculate additional cost
                if (!$costFound && isset($methodData['after_max_weight_price_per_kg'])) {
                    $additionalWeight = $weight - $maxWeight;
                    $additionalCost = ($additionalWeight / 1000) * (float)$methodData['after_max_weight_price_per_kg'];
                    $cost = $maxCost + $additionalCost;
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

            return round($cost, 2);

        } catch (\Exception $e) {
            $this->log('error', 'Error calculating shipping cost', [
                'error' => $e->getMessage(),
                'method_data' => $methodData
            ]);
            return 0.00;
        }
    }
}
