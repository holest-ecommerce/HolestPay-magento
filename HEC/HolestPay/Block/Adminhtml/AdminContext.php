<?php
namespace HEC\HolestPay\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use HEC\HolestPay\Model\ConfigurationManager;

class AdminContext extends Template
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var JsonSerializer
     */
    private $jsonSerializer;

    /**
     * @var ConfigurationManager
     */
    private $configurationManager;

    public function __construct(
        Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        JsonSerializer $jsonSerializer,
        ConfigurationManager $configurationManager,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $context->getUrlBuilder();
        $this->jsonSerializer = $jsonSerializer;
        $this->configurationManager = $configurationManager;
        parent::__construct($context, $data);
    }

    /**
     * Get admin base URL
     *
     * @return string
     */
    public function getAdminBaseUrl(): string
    {
        return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]);
    }

    /**
     * Get frontend base URL
     *
     * @return string
     */
    public function getFrontendBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
    }

    /**
     * Get configuration parameter value
     *
     * @return array|null
     */
    public function getConfiguration(): ?array
    {
        return $this->configurationManager->getAllConfigurations();
    }

    /**
     * Get merchant site UID
     *
     * @return string|null
     */
    public function getMerchantSiteUid(): ?string
    {
        return $this->scopeConfig->getValue('payment/holestpay/merchant_site_uid', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get environment
     *
     * @return string|null
     */
    public function getEnvironment(): ?string
    {
        return $this->scopeConfig->getValue('payment/holestpay/environment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Get secret key
     *
     * @return string|null
     */
    public function getSecretKey(): ?string
    {
        return $this->scopeConfig->getValue('payment/holestpay/secret_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}
