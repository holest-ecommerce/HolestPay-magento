<?php

namespace HEC\HolestPay\Block\Frontend;

use Magento\Framework\View\Element\Template;
use Magento\Csp\Helper\CspNonceProvider;

class NonceBlock extends Template
{
    /**
     * @var CspNonceProvider
     */
    protected $cspNonceProvider;

    public function __construct(
        Template\Context $context,
        CspNonceProvider $cspNonceProvider,
        array $data = []
    ) {
        $this->cspNonceProvider = $cspNonceProvider;
        parent::__construct($context, $data);
    }

    /**
     * Get the CSP nonce value.
     *
     * @return string
     */
    public function getNonce(): string
    {
        return $this->cspNonceProvider->generateNonce();
    }
}