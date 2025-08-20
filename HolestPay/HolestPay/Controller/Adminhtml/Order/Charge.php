<?php
namespace HolestPay\HolestPay\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

class Charge extends Action
{
    const ADMIN_RESOURCE = 'Magento_Sales::actions';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        Action\Context $context,
        OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
    }

    public function execute()
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);

        try {
            $order = $this->orderRepository->get($orderId);
            // TODO: add your charge API call using order data
            $this->messageManager->addSuccessMessage(__('Charge action executed.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect;
    }

    public static function isAvailable(\Magento\Sales\Model\Order $order): bool
    {
        // Define availability based on order state/status and HPayStatus
        $hpay = (string)$order->getData('hpay_status');
        $state = $order->getState();
        // Example logic: allow charge only when order is new/processing and hpay is 'authorized'
        return in_array($state, [\Magento\Sales\Model\Order::STATE_NEW, \Magento\Sales\Model\Order::STATE_PROCESSING], true)
            && in_array($hpay, ['authorized', 'chargeable', 'pending_capture'], true);
    }
}


