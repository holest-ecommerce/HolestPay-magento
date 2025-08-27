<?php
namespace HEC\HolestPay\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

class SignatureHelper
{
    /**
     * Sign request data using HolestPay signature algorithm
     *
     * @param array $requestData
     * @param ScopeConfigInterface $scopeConfig
     * @return array
     */
    public static function signRequestData(array $requestData, ScopeConfigInterface $scopeConfig): array
    {
        // Ensure all required fields are present with defaults
        $signedData = array_merge([
            'transaction_uid' => '',
            'status' => '',
            'order_uid' => '',
            'order_amount' => '',
            'order_currency' => '',
            'vault_token_uid' => '',
            'subscription_uid' => '',
            'rand' => uniqid('rnd'),
            'verificationhash' => ''
        ], $requestData);

        // Validate required fields
        if (empty($signedData['order_uid'])) {
            throw new LocalizedException(__('order_uid is required'));
        }
        if (empty($signedData['order_amount'])) {
            throw new LocalizedException(__('order_amount is required'));
        }
        if (empty($signedData['order_currency'])) {
            throw new LocalizedException(__('order_currency is required'));
        }

        // Generate signature hash
        $signedData['verificationhash'] = self::generateSignatureHash(
            $signedData['transaction_uid'],
            $signedData['status'],
            $signedData['order_uid'],
            $signedData['order_amount'],
            $signedData['order_currency'],
            $signedData['vault_token_uid'],
            $signedData['subscription_uid'],
            $signedData['rand'],
            $scopeConfig
        );

        return $signedData;
    }

    /**
     * Generate signature hash using HolestPay algorithm
     *
     * @param string $transaction_uid
     * @param string $status
     * @param string $order_uid
     * @param string $amount
     * @param string $currency
     * @param string $vault_token_uid
     * @param string $subscription_uid
     * @param string $rand
     * @param ScopeConfigInterface $scopeConfig
     * @return string
     */
    public static function generateSignatureHash(
        string $transaction_uid,
        string $status,
        string $order_uid,
        string $amount,
        string $currency,
        string $vault_token_uid = '',
        string $subscription_uid = '',
        string $rand = '',
        ScopeConfigInterface $scopeConfig = null
    ): string {
        // Clean and validate input parameters
        $transaction_uid = trim($transaction_uid ?: '');
        $status = trim($status ?: '');
        $order_uid = trim($order_uid ?: '');
        $amount = $amount === null || trim($amount) === '' ? 0 : $amount;
        $currency = trim($currency ?: '');
        $vault_token_uid = trim($vault_token_uid ?: '');
        $subscription_uid = trim($subscription_uid ?: '');
        $rand = trim($rand ?: '');

        // Format amount to 8 decimal places
        $amount = number_format((float)$amount, 8, '.', '');

        // Validate currency (must be 3 characters)
        if ($currency && strlen($currency) !== 3) {
            throw new LocalizedException(__('Invalid currency code'));
        }

        // Get configuration values
        $merchant_site_uid = $scopeConfig ? $scopeConfig->getValue(
            'payment/holestpay/merchant_site_uid',
            ScopeInterface::SCOPE_STORE
        ) : '';

        $secret_token = $scopeConfig ? $scopeConfig->getValue(
            'payment/holestpay/secret_key',
            ScopeInterface::SCOPE_STORE
        ) : '';

        if (!$merchant_site_uid || !$secret_token) {
            throw new LocalizedException(__('HolestPay configuration is incomplete'));
        }

        // Build signature string (exactly matching Node.js implementation)
        $srcstr = "{$transaction_uid}|{$status}|{$order_uid}|{$amount}|{$currency}|{$vault_token_uid}|{$subscription_uid}{$rand}";
        
        // Generate MD5 hash with merchant site UID
        $srcstrmd5 = md5($srcstr . $merchant_site_uid);
        
        // Generate final SHA512 hash with secret token
        return strtolower(hash('sha512', $srcstrmd5 . $secret_token));
    }
}
