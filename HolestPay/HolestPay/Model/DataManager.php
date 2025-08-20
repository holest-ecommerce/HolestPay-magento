<?php
namespace HolestPay\HolestPay\Model;

use HolestPay\HolestPay\Api\DataManagerInterface;
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
}


