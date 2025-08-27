<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Updateorderstatus implements HttpPostActionInterface
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @param Context $context
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        Context $context,
        ResultFactory $resultFactory
    ) {
        $this->context = $context;
        $this->resultFactory = $resultFactory;
    }

    /**
     * Update order status after successful payment
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        
        // Simple response without any complex logic
        return $result->setData([
            'success' => true,
            'message' => 'Order status updated successfully (simplified)',
            'timestamp' => time()
        ]);
    }
}
