<?php
namespace HolestPay\HolestPay\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'sandbox', 'label' => __('Sandbox')],
            ['value' => 'production', 'label' => __('Production')],
        ];
    }
}


