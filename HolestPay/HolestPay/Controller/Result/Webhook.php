<?php
namespace HolestPay\HolestPay\Controller\Result;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use HolestPay\HolestPay\Model\Order\StatusManager;

class Webhook extends Action implements CsrfAwareActionInterface
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var StatusManager */
    private $statusManager;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        StatusManager $statusManager
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->statusManager = $statusManager;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $orderIncrementId = $this->getRequest()->getParam('order_increment_id');
            $hpayStatus = $this->getRequest()->getParam('hpay_status');

            if (!$orderIncrementId || !$hpayStatus) {
                throw new \InvalidArgumentException('Missing parameters');
            }

            $order = $this->orderRepository->get($this->loadOrderIdByIncrementId($orderIncrementId));
            $this->statusManager->setHPayStatus($order, (string)$hpayStatus);

            $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $result->setHttpResponseCode(400);
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
        return $result;
    }

    private function loadOrderIdByIncrementId(string $incrementId): int
    {
        /** @var \Magento\Sales\Model\Order $orderModel */
        $orderModel = $this->_objectManager->create(\Magento\Sales\Model\Order::class);
        $order = $orderModel->loadByIncrementId($incrementId);
        if (!$order || !$order->getId()) {
            throw new \RuntimeException('Order not found');
        }
        return (int) $order->getId();
    }
}


