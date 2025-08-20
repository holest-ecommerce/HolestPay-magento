<?php
namespace HolestPay\HolestPay\Api;

interface DataManagerInterface
{
    // Customer-level JSON metadata by email
    public function getCustomerHPayData(string $email): ?array;
    public function setCustomerHPayData(string $email, array $data): void;

    // Order-level JSON metadata by order id
    public function getOrderHPayData(int $orderId): ?array;
    public function setOrderHPayData(int $orderId, array $data): void;

    // Order HPayStatus helpers
    public function getOrderHPayStatus(int $orderId): ?string;
    public function setOrderHPayStatus(int $orderId, string $status): void;
}


