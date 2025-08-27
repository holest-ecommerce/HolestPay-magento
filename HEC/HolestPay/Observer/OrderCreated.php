<?php
/**
 * HolestPay Order Created Observer
 *
 * Stores the HolestPay UID (quote ID) in order metadata when orders are created
 */
namespace HEC\HolestPay\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class OrderCreated implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
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
            /** @var OrderInterface $order */
            $order = $observer->getEvent()->getOrder();
            
            if (!$order || !$order->getId()) {
                return;
            }

            // Check if this is a HolestPay order
            if ($order->getPayment() && $order->getPayment()->getMethod() === 'holestpay') {
                $quoteId = $order->getQuoteId();
                
                if ($quoteId) {
                    // Store the quote ID as the HolestPay UID in the direct database column
                    $order->setData('holestpay_uid', (string)$quoteId);
                    
                    $this->logger->warning('HolestPay: Stored HolestPay UID in order metadata', [
                        'order_id' => $order->getId(),
                        'holestpay_uid' => (string)$quoteId
                    ]);
                } else {
                    $this->logger->warning('HolestPay: Could not store HolestPay UID - quote ID not found', [
                        'order_id' => $order->getId(),
                        'order_increment_id' => $order->getIncrementId()
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('HolestPay: Error in OrderCreated observer', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
