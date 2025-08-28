<?php
namespace HEC\HolestPay\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use HEC\HolestPay\Model\OrderSyncService;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class OrderGridSync implements ObserverInterface
{
    use DebugLogTrait;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getOrder();
            
            if (!$order || !$order->getId()) {
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $orderTable = $this->resourceConnection->getTableName('sales_order');
            $gridTable = $this->resourceConnection->getTableName('sales_order_grid');

            // Get HolestPay data from main order table
            $select = $connection->select()
                ->from($orderTable, ['hpay_status', 'holestpay_uid'])
                ->where('entity_id = ?', $order->getId());

            $orderData = $connection->fetchRow($select);

            if ($orderData) {
                // Update grid table with HolestPay data
                $updateData = [];
                
                if (isset($orderData['hpay_status'])) {
                    $updateData['hpay_status'] = $orderData['hpay_status'];
                }
                
                if (isset($orderData['holestpay_uid'])) {
                    $updateData['holestpay_uid'] = $orderData['holestpay_uid'];
                }

                if (!empty($updateData)) {
                    $connection->update(
                        $gridTable,
                        $updateData,
                        ['entity_id = ?' => $order->getId()]
                    );

                    $this->debugWarning('Synced order grid data', [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId()
                    ]);
                }
            }

        } catch (\Exception $e) {
            $this->debugError('Error syncing order grid data', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
