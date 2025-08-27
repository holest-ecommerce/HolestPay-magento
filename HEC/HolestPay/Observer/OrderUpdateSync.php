<?php
namespace HEC\HolestPay\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use HEC\HolestPay\Model\OrderSyncService;
use Psr\Log\LoggerInterface;

class OrderUpdateSync implements ObserverInterface
{
    /**
     * @var OrderSyncService
     */
    private $orderSyncService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderSyncService $orderSyncService
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderSyncService $orderSyncService,
        LoggerInterface $logger
    ) {
        $this->orderSyncService = $orderSyncService;
        $this->logger = $logger;
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

            // Check if this is a status change
            $isStatusChange = false;
            if ($observer->getEvent()->getName() === 'sales_order_status_change') {
                $isStatusChange = true;
            }

            // Check if order is being processed by webhook
            if ($order->getData('_processing_webhook')) {
                return;
            }

            // Check if order is being processed by forwarded response
            if ($order->getData('_processing_forwarded_response')) {
                return;
            }

            // Check if order should be synced
            if ($this->orderSyncService->shouldSyncOrder($order, $isStatusChange)) {
                $this->logger->warning('HolestPay: Order update sync triggered', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'status_change' => $isStatusChange
                ]);

                $result = $this->orderSyncService->syncOrder($order, null, $isStatusChange);
                
                if ($result) {
                    $this->logger->warning('HolestPay: Order update sync completed successfully', [
                        'order_id' => $order->getId(),
                        'increment_id' => $order->getIncrementId()
                    ]);
                }
            } else {
                // Log why sync was skipped
                $this->logger->warning('HolestPay: Order update sync skipped - conditions not met', [
                    'order_id' => $order->getId(),
                    'increment_id' => $order->getIncrementId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error in order update sync observer', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $order ? $order->getId() : 'unknown'
            ]);
        }
    }
}
