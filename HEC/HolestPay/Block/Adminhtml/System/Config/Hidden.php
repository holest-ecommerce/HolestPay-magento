<?php
namespace HEC\HolestPay\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Hidden extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $element->setData('value', $element->getEscapedValue() ?: '{}');
        $element->setType('hidden');
        $element->setCanUseWebsiteValue(false);
        $element->setCanUseDefaultValue(false);
        return $element->getElementHtml();
    }
}


