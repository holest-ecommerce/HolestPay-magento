<?php
namespace HEC\HolestPay\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Sales\Model\Order;
use Magento\Framework\Registry;

class Box extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @param Template\Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get current order
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->registry->registry('current_order');
    }

    /**
     * Get HPay Status from order
     *
     * @return string|null
     */
    public function getHPayStatus(): ?string
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        return $order->getData('hpay_status');
    }

    /**
     * Get HolestPay UID from order
     *
     * @return string|null
     */
    public function getHolestPayUid(): ?string
    {
        $order = $this->getOrder();
        if (!$order) {
            return null;
        }

        return $order->getData('holestpay_uid');
    }

    /**
     * Get order data as JSON for JavaScript
     *
     * @return string
     */
    public function getOrderDataJson(): string
    {
        $orderData = [
            'hpay_status' => $this->getHPayStatus(),
            'holestpay_uid' => $this->getHolestPayUid(),
            'order_id' => $this->getOrder() ? $this->getOrder()->getId() : null
        ];

        return json_encode($orderData);
    }
}
