<?php
namespace HEC\HolestPay\Controller\Ajax;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Customer\Model\Session as CustomerSession;
use HEC\HolestPay\Model\DataManager;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class User extends Action implements CsrfAwareActionInterface
{
    /** @var DataManager */
    private $dataManager;
    
    /** @var CustomerSession */
    private $customerSession;
    
    /** @var JsonSerializer */
    private $jsonSerializer;

    public function __construct(
        Context $context,
        DataManager $dataManager,
        CustomerSession $customerSession,
        JsonSerializer $jsonSerializer
    ) {
        parent::__construct($context);
        $this->dataManager = $dataManager;
        $this->customerSession = $customerSession;
        $this->jsonSerializer = $jsonSerializer;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        
        try {
            if (!$this->customerSession->isLoggedIn()) {
                $result->setData([
                    'success' => false,
                    'message' => __('Customer not logged in')
                ]);
                return $result;
            }
            
            $action = $this->getRequest()->getParam('action');
            $customerEmail = $this->customerSession->getCustomer()->getEmail();
            
            switch ($action) {
                case 'remove_token':
                    $tokenValue = $this->getRequest()->getParam('token_value');
                    if (!$tokenValue) {
                        throw new \InvalidArgumentException('Token value is required');
                    }
                    
                    $success = $this->dataManager->removeCustomerToken($customerEmail, $tokenValue);
                    $result->setData([
                        'success' => $success,
                        'message' => $success ? __('Token removed successfully') : __('Token not found')
                    ]);
                    break;
                    
                case 'set_default_token':
                    $tokenValue = $this->getRequest()->getParam('token_value');
                    if (!$tokenValue) {
                        throw new \InvalidArgumentException('Token value is required');
                    }
                    
                    $success = $this->dataManager->setDefaultToken($customerEmail, $tokenValue);
                    $result->setData([
                        'success' => $success,
                        'message' => $success ? __('Token set as default') : __('Token not found')
                    ]);
                    break;
                    
                case 'get_tokens':
                    $tokens = $this->dataManager->getCustomerTokens($customerEmail);
                    $result->setData([
                        'success' => true,
                        'tokens' => $tokens
                    ]);
                    break;
                    
                default:
                    $result->setData([
                        'success' => false,
                        'message' => __('Invalid action')
                    ]);
                    break;
            }
            
        } catch (\Exception $e) {
            $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        
        return $result;
    }
}


