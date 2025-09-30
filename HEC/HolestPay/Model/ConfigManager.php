<?php
namespace HEC\HolestPay\Model;

use HEC\HolestPay\Api\ConfigManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

class ConfigManager implements ConfigManagerInterface
{
    private const PATH_ACTIVE = 'payment/holestpay/active';
    private const PATH_TITLE = 'payment/holestpay/title';
    private const PATH_ENVIRONMENT = 'payment/holestpay/environment';
    private const PATH_MERCHANT_SITE_UID = 'payment/holestpay/merchant_site_uid';
    private const PATH_SECRET_KEY = 'payment/holestpay/secret_key';
    private const PATH_CONFIGURATION = 'payment/holestpay/configuration';
    private const PATH_DONT_SEND_DEFAULT_ORDER_MAIL = 'payment/holestpay/dont_send_default_order_mail';

    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var ResourceConfig */
    private $resourceConfig;
    /** @var JsonSerializer */
    private $json;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConfig $resourceConfig,
        JsonSerializer $json
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->resourceConfig = $resourceConfig;
        $this->json = $json;
    }

    public function getHPayConfig(): array
    {
        $conf = [
            'active' => (int)$this->getValue(self::PATH_ACTIVE),
            'title' => (string)$this->getValue(self::PATH_TITLE),
            'environment' => (string)$this->getValue(self::PATH_ENVIRONMENT),
            'merchant_site_uid' => (string)$this->getValue(self::PATH_MERCHANT_SITE_UID),
            'secret_key' => (string)$this->getValue(self::PATH_SECRET_KEY),
            'configuration' => $this->decodeJson((string)$this->getValue(self::PATH_CONFIGURATION)) ?? []
        ];
        return $conf;
    }

    public function setHPayConfig(array $configData): void
    {
        $map = [
            'active' => self::PATH_ACTIVE,
            'title' => self::PATH_TITLE,
            'environment' => self::PATH_ENVIRONMENT,
            'merchant_site_uid' => self::PATH_MERCHANT_SITE_UID,
            'secret_key' => self::PATH_SECRET_KEY,
            'configuration' => self::PATH_CONFIGURATION,
        ];

        foreach ($map as $key => $path) {
            if (!array_key_exists($key, $configData)) {
                continue;
            }
            $value = $configData[$key];
            if (is_array($value) || is_object($value)) {
                $value = $this->json->serialize($value);
            }
            $this->resourceConfig->saveConfig($path, (string)$value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }
    }

    private function getValue(string $path)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }

    private function decodeJson(string $raw)
    {
        if ($raw === '') {
            return null;
        }
        try { return $this->json->unserialize($raw); } catch (\Throwable $e) { return null; }
    }

    public function isDefaultOrderMailDisabled(): bool
    {
        return (bool)$this->getValue(self::PATH_DONT_SEND_DEFAULT_ORDER_MAIL);
    }
}


