<?php
namespace HEC\HolestPay\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'Magento_Backend::admin';
    //https://your-magento-domain.com/admin/holestpay/ajax/index
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData([
            'success' => true,
            'message' => __('HolestPay admin AJAX endpoint working'),
        ]);
        return $result;
    }
}


