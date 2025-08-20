<?php
namespace HolestPay\HolestPay\Api;

interface ConfigManagerInterface
{
    /**
     * Returns full HolestPay config as an associative array.
     * Complex properties (like the JSON 'configuration') are returned decoded.
     */
    public function getHPayConfig(): array;

    /**
     * Saves full HolestPay config from an associative array.
     * Complex properties (arrays/objects) are automatically JSON-encoded.
     *
     * @param array $configData
     */
    public function setHPayConfig(array $configData): void;
}


