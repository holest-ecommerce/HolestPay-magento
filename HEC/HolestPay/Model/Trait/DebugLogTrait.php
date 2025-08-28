<?php
/**
 * Debug Logging Trait for HolestPay Module
 * 
 * This trait provides debug-aware logging functionality that can be used
 * across all classes in the HolestPay module. It ensures that:
 * - Warning, info, and debug messages only log when debug mode is enabled
 * - Error messages always log regardless of debug setting
 * - Configuration is accessed consistently across the module
 */
namespace HEC\HolestPay\Model\Trait;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Debug Logging Trait
 * 
 * Provides unified logging functionality that respects the debug parameter.
 * All logging should go through this single function.
 */
trait DebugLogTrait
{
    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    protected function isDebugEnabled(): bool
    {
        if (!property_exists($this, 'scopeConfig') || !$this->scopeConfig instanceof ScopeConfigInterface) {
            return false;
        }
        
        return (bool) $this->scopeConfig->getValue(
            'payment/holestpay/debug',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * UNIFIED LOGGING FUNCTION - Use this for ALL logging
     * 
     * @param string $severity - 'log', 'warning', 'error', 'info', 'debug'
     * @param string $message - The message to log
     * @param array $context - Optional context data
     * @param string $prefix - Optional prefix for the message
     * @return void
     */
    protected function log($severity, $message, array $context = [], $prefix = 'HolestPay')
    {
        // Always log errors regardless of debug setting
        if ($severity === 'error') {
            $this->writeLog($severity, $message, $context, $prefix);
            return;
        }
        
        // For non-error messages, only log if debug is enabled
        if ($this->isDebugEnabled()) {
            $this->writeLog($severity, $message, $context, $prefix);
        }
    }

    /**
     * Internal function to actually write the log
     *
     * @param string $severity
     * @param string $message
     * @param array $context
     * @param string $prefix
     * @return void
     */
    private function writeLog($severity, $message, array $context = [], $prefix = 'HolestPay')
    {
        if (!property_exists($this, 'logger') || !$this->logger instanceof LoggerInterface) {
            return;
        }
        
        $contextString = !empty($context) ? ' Context: ' . json_encode($context) : '';
        $fullMessage = '[' . $prefix . '] ' . $message . $contextString;
        
        switch ($severity) {
            case 'warning':
                $this->logger->warning($fullMessage);
                break;
            case 'info':
                $this->logger->info($fullMessage);
                break;
            case 'error':
                $this->logger->error($fullMessage);
                break;
            case 'debug':
                $this->logger->debug($fullMessage);
                break;
            case 'log':
            default:
                $this->logger->info($fullMessage);
                break;
        }
    }

    // Convenience methods for backward compatibility
    protected function debugLog($severity, $message, array $context = [], $prefix = 'HolestPay')
    {
        $this->log($severity, $message, $context, $prefix);
    }

    protected function debugWarning($message, array $context = [], $prefix = 'HolestPay')
    {
        $this->log('warning', $message, $context, $prefix);
    }

    protected function debugInfo($message, array $context = [], $prefix = 'HolestPay')
    {
        $this->log('info', $message, $context, $prefix);
    }

    protected function debugError($message, array $context = [], $prefix = 'HolestPay')
    {
        $this->log('error', $message, $context, $prefix);
    }

    protected function debugDebug($message, array $context = [], $prefix = 'HolestPay')
    {
        $this->log('debug', $message, $context, $prefix);
    }
}
