<?php
namespace HEC\HolestPay\Model;

use HEC\HolestPay\Api\DataManagerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\App\ResourceConnection;

class DataManager implements DataManagerInterface
{
    /** @var CustomerRepositoryInterface */
    private $customerRepository;
    /** @var CustomerInterfaceFactory */
    private $customerFactory;
    /** @var OrderRepositoryInterface */
    private $orderRepository;
    /** @var JsonSerializer */
    private $json;
    /** @var ResourceConnection */
    private $resource;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        CustomerInterfaceFactory $customerFactory,
        OrderRepositoryInterface $orderRepository,
        JsonSerializer $json,
        ResourceConnection $resource
    ) {
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->orderRepository = $orderRepository;
        $this->json = $json;
        $this->resource = $resource;
    }

    public function getCustomerHPayData(string $email): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('customer_entity');
        $raw = $connection->fetchOne(
            $connection->select()->from($table, ['hpay_data'])->where('email = ?', $email)
        );
        if ($raw === null || $raw === '') {
            return null;
        }
        try { return (array)$this->json->unserialize($raw); } catch (\Throwable $e) { return null; }
    }

    public function setCustomerHPayData(string $email, array $data): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('customer_entity');
        $payload = $this->json->serialize($data);
        $connection->update($table, ['hpay_data' => $payload], ['email = ?' => $email]);
    }

    public function getOrderHPayData(int $orderId): ?array
    {
        $order = $this->orderRepository->get($orderId);
        $raw = $order->getData('hpay_data');
        if ($raw === null || $raw === '') {
            return null;
        }
        try { return (array)$this->json->unserialize($raw); } catch (\Throwable $e) { return null; }
    }

    public function setOrderHPayData(int $orderId, array $data): void
    {
        $order = $this->orderRepository->get($orderId);
        $order->setData('hpay_data', $this->json->serialize($data));
        $this->orderRepository->save($order);
    }

    public function getOrderHPayStatus(int $orderId): ?string
    {
        $order = $this->orderRepository->get($orderId);
        $value = $order->getData('hpay_status');
        return $value !== null && $value !== '' ? (string)$value : null;
    }

    public function setOrderHPayStatus(int $orderId, string $status): void
    {
        $order = $this->orderRepository->get($orderId);
        $order->setData('hpay_status', $status);
        $this->orderRepository->save($order);
    }

    /**
     * Get customer saved tokens
     */
    public function getCustomerTokens(string $email): array
    {
        $hpayData = $this->getCustomerHPayData($email);
        return $hpayData['tokens'] ?? [];
    }

    /**
     * Add or update customer token
     */
    public function addCustomerToken(string $email, array $tokenData): void
    {
        $hpayData = $this->getCustomerHPayData($email) ?: [];
        $tokens = $hpayData['tokens'] ?? [];
        
        // Check if token already exists
        $existingTokenIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['token_value'] === $tokenData['token_value']) {
                $existingTokenIndex = $index;
                break;
            }
        }
        
        if ($existingTokenIndex !== null) {
            // Update existing token
            $tokens[$existingTokenIndex] = array_merge($tokens[$existingTokenIndex], $tokenData);
        } else {
            // Add new token
            $tokens[] = $tokenData;
        }
        
        $hpayData['tokens'] = $tokens;
        $this->setCustomerHPayData($email, $hpayData);
    }

    /**
     * Remove customer token
     */
    public function removeCustomerToken(string $email, string $tokenValue): bool
    {
        $hpayData = $this->getCustomerHPayData($email);
        if (!$hpayData || !isset($hpayData['tokens'])) {
            return false;
        }
        
        $tokens = $hpayData['tokens'];
        $originalCount = count($tokens);
        
        $tokens = array_filter($tokens, function($token) use ($tokenValue) {
            return $token['token_value'] !== $tokenValue;
        });
        
        if (count($tokens) < $originalCount) {
            $hpayData['tokens'] = array_values($tokens);
            $this->setCustomerHPayData($email, $hpayData);
            return true;
        }
        
        return false;
    }

    /**
     * Set token as default
     */
    public function setDefaultToken(string $email, string $tokenValue): bool
    {
        $hpayData = $this->getCustomerHPayData($email);
        if (!$hpayData || !isset($hpayData['tokens'])) {
            return false;
        }
        
        $tokens = $hpayData['tokens'];
        $tokenFound = false;
        
        foreach ($tokens as &$token) {
            if ($token['token_value'] === $tokenValue) {
                $token['is_default'] = true;
                $tokenFound = true;
            } else {
                $token['is_default'] = false;
            }
        }
        
        if ($tokenFound) {
            $hpayData['tokens'] = $tokens;
            $this->setCustomerHPayData($email, $hpayData);
            return true;
        }
        
        return false;
    }
}


