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
use Magento\Framework\App\Config\ScopeConfigInterface;
use HEC\HolestPay\Model\Trait\DebugLogTrait;
use HEC\HolestPay\Api\ConfigManagerInterface;

class OrderCreated implements ObserverInterface
{
    use DebugLogTrait;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ConfigManagerInterface
     */
    private $configManager;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigManagerInterface $configManager
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        ConfigManagerInterface $configManager
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->configManager = $configManager;
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
                    
                    $this->debugWarning('Stored HolestPay UID in order metadata', [
                        'order_id' => $order->getId(),
                        'holestpay_uid' => (string)$quoteId
                    ]);
                } else {
                    $this->debugWarning('Could not store HolestPay UID - quote ID not found', [
                        'order_id' => $order->getId(),
                        'order_increment_id' => $order->getIncrementId()
                    ]);
                }

                // Check if default order mail should be disabled
                if ($this->configManager->isDefaultOrderMailDisabled()) {
                    $order->setCanSendNewEmailFlag(false);
                    
                    $this->debugWarning('Disabled default order email for HolestPay order', [
                        'order_id' => $order->getId(),
                        'order_increment_id' => $order->getIncrementId()
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->debugError('Error in OrderCreated observer', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
