<?php
namespace HEC\HolestPay\Model\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class StatusManager
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(OrderRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }

    public function setHPayStatus(Order $order, string $status): void
    {
        $order->setData('hpay_status', $status);
        $this->orderRepository->save($order);
    }

    public function getHPayStatus(Order $order): ?string
    {
        return $order->getData('hpay_status');
    }
}


