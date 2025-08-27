<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Test implements HttpGetActionInterface
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
     * Test endpoint
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        
        return $result->setData([
            'success' => true,
            'message' => 'AJAX routing is working!',
            'timestamp' => time()
        ]);
    }
}
