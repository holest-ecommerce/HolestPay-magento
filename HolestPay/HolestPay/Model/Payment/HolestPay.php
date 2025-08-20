<?php
namespace HolestPay\HolestPay\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

class HolestPay extends AbstractMethod
{
    protected $_code = 'holestpay';

    protected $_isOffline = false;

    protected $_isInitializeNeeded = false;

    // Availability toggles – you can adjust based on HPayStatus
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canVoid = true;

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // TODO: replace with your capture API call; set transaction info
        // Example: $payment->setTransactionId('capture-xxx')->setIsTransactionClosed(false);
        return $this;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        // TODO: replace with your refund API call
        // Example: $payment->setTransactionId('refund-xxx')->setIsTransactionClosed(false);
        return $this;
    }

    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        // TODO: replace with your void API call
        // Example: $payment->setTransactionId('void-xxx')->setIsTransactionClosed(true);
        return $this;
    }

    public function canCapture()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        // Enable capture when status allows it
        return in_array($status, ['authorized', 'pending_capture', 'chargeable'], true);
    }

    public function canRefund()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        return in_array($status, ['captured', 'settled', 'partially_refunded'], true);
    }

    public function canVoid()
    {
        $order = $this->getInfoInstance()->getOrder();
        $status = $order ? $order->getData('hpay_status') : null;
        return in_array($status, ['authorized', 'pending_capture'], true);
    }
}


