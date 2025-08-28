# Debug Logging System for HolestPay Module

## Overview
This document describes the unified debug logging system implemented in the HolestPay Magento module. The system provides conditional logging based on a debug parameter, ensuring that only errors are always logged while other log levels respect the debug setting.

## Implementation

### 1. Configuration
A new "Debug Mode" configuration field has been added to `Admin > Stores > Configuration > Sales > Payment Methods > HolestPay > Debug Mode`.

### 2. DebugLogTrait
The core functionality is implemented in `Model/Trait/DebugLogTrait.php` which provides:
- `log($severity, $message, $context, $prefix)` - Main unified logging function
- `isDebugEnabled()` - Checks if debug mode is enabled
- Convenience methods: `debugWarning()`, `debugInfo()`, `debugError()`, `debugDebug()`

### 3. Usage Pattern
All classes using the trait must have these properties:
```php
use HEC\HolestPay\Model\Trait\DebugLogTrait;

class YourClass
{
    use DebugLogTrait;
    
    protected $scopeConfig;  // Required for debug parameter check
    protected $logger;        // Required for actual logging
    
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        // ... other dependencies
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        // ... other assignments
    }
}
```

## Logging Levels

### Always Logged (Regardless of Debug Setting)
- **Error** (`'error'`) - Critical errors that must be logged

### Conditionally Logged (Only When Debug Enabled)
- **Warning** (`'warning'`) - Warnings and important information
- **Info** (`'info'`) - General information
- **Debug** (`'debug'`) - Detailed debugging information
- **Log** (`'log'`) - General logging (defaults to info level)

## Examples

### Basic Usage
```php
// Always logs (error level)
$this->log('error', 'Critical error occurred', ['error' => $e->getMessage()]);

// Only logs if debug enabled
$this->log('warning', 'Processing request', ['request_id' => $id]);
$this->log('info', 'Request completed successfully');
$this->log('debug', 'Detailed debug info', $context);
```

### Convenience Methods
```php
$this->debugError('Error message', $context);
$this->debugWarning('Warning message', $context);
$this->debugInfo('Info message', $context);
$this->debugDebug('Debug message', $context);
```

## Files Updated

### ✅ Completed
- `Model/Payment/HolestPay.php` - All `$this->_logger->debug` calls updated
- `Model/Checkout/ConfigProvider.php` - All `error_log` calls updated
- `Observer/OrderCreated.php` - All logger calls updated
- `Observer/OrderUpdateSync.php` - All logger calls updated
- `Model/OrderLockManager.php` - All logger calls updated
- `Model/ShippingMethodSyncService.php` - All logger calls updated
- `Model/Carrier/HolestPayCarrier.php` - All logger calls updated

### 🔄 Remaining
- `Observer/AddCspHeaders.php` - Replace `error_log` with unified logging
- `Ui/Component/Listing/Column/HPayStatus.php` - Replace `error_log` with unified logging
- `Ui/Component/Listing/Column/HolestPayUid.php` - Replace `error_log` with unified logging
- `Controller/Ajax/Payment.php` - Replace `$this->logger->info` calls with unified logging

## Troubleshooting

### Common Issues

#### 1. Undefined Property: $scopeConfig
**Error:** `Warning: Undefined property: YourClass::$scopeConfig`

**Solution:** Ensure the class has the `$scopeConfig` property and it's injected in the constructor:
```php
protected $scopeConfig;

public function __construct(
    ScopeConfigInterface $scopeConfig,
    // ... other parameters
) {
    $this->scopeConfig = $scopeConfig;
    // ... other assignments
}
```

#### 2. Undefined Property: $logger
**Error:** `Warning: Undefined property: YourClass::$logger`

**Solution:** Ensure the class has the `$logger` property and it's injected in the constructor:
```php
protected $logger;

public function __construct(
    LoggerInterface $logger,
    // ... other parameters
) {
    $this->logger = $logger;
    // ... other assignments
}
```

#### 3. Trait Cannot Access Properties
**Error:** `Fatal error: Cannot access private property`

**Solution:** Make sure the properties are `protected` (not `private`) so the trait can access them:
```php
// ✅ Correct - trait can access
protected $scopeConfig;
protected $logger;

// ❌ Wrong - trait cannot access
private $scopeConfig;
private $logger;
```

## Benefits

1. **Centralized Control** - Single configuration point for all logging
2. **Consistent Behavior** - All logging follows the same rules
3. **Performance** - Non-error logs are skipped when debug is disabled
4. **Maintainability** - Easy to modify logging behavior across the entire module
5. **Backward Compatibility** - Existing convenience methods still work

## Testing

### Enable Debug Mode
1. Go to `Admin > Stores > Configuration > Sales > Payment Methods > HolestPay`
2. Set "Debug Mode" to "Yes"
3. Clear cache: `php bin/magento cache:clean`

### Disable Debug Mode
1. Set "Debug Mode" to "No"
2. Clear cache: `php bin/magento cache:clean`
3. Verify only error messages appear in logs

### Verify Logging
Check the Magento logs at `var/log/system.log` or `var/log/exception.log` to verify:
- Errors are always logged
- Other log levels only appear when debug is enabled
