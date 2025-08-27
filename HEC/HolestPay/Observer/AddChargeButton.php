<?php
namespace HEC\HolestPay\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Item;
use Magento\Sales\Api\OrderRepositoryInterface;
use HEC\HolestPay\Controller\Adminhtml\Order\Charge as ChargeController;

class AddChargeButton implements ObserverInterface
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Backend\Block\Widget\Container $container */
        $container = $observer->getEvent()->getData('container');
        if (!$container || $container->getId() !== 'sales_order_view') {
            return;
        }
        
        $request = $container->getRequest();
        $orderId = (int)$request->getParam('order_id');
        if (!$orderId) {
            return;
        }
        
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return;
        }
        
        if (!ChargeController::isAvailable($order)) {
            return;
        }
        
        $container->addButton(
            'holestpay_order_charge',
            [
                'label' => __('Charge'),
                'class' => 'primary',
                'onclick' => sprintf("setLocation('%s')", $container->getUrl('holestpayadmin/order/charge', ['order_id' => $orderId]))
            ]
        );
    }
}


