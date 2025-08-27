# HolestPay Payment Gateway for OpenCart 3 and 4

A complete payment gateway integration for OpenCart that connects your store with HolestPay payment processing services.

## Features

- **Dual Compatibility**: Works with both OpenCart 3 and OpenCart 4
- **Minimal File Count**: Single comprehensive model file with all functionality
- **Standard PHP**: Uses standard PHP functions and direct database calls for maximum compatibility
- **Complete Integration**: Full payment flow from checkout to order completion
- **Webhook Support**: Real-time payment status updates
- **Admin Management**: Complete admin panel configuration
- **Security**: Signature verification for all webhook communications
- **Flexible Configuration**: Country restrictions, order total limits, and more

## Installation

### Method 1: Manual Installation

1. Upload all files to your OpenCart installation
2. Go to **Extensions > Extensions** in admin
3. Find **Payments** in the dropdown
4. Locate **HolestPay** and click **Install**
5. Click **Edit** to configure the extension

### Method 2: OCMOD Installation

1. Upload the `holestpay.ocmod.xml` file
2. Go to **Extensions > Modifications** in admin
3. Click **Refresh** button
4. Install the extension as described in Method 1

## Configuration

### Required Settings

- **Status**: Enable/disable the payment method
- **Title**: Display name for customers
- **Environment**: Choose between Sandbox (testing) and Live (production)
- **Merchant Site UID**: Your HolestPay merchant identifier
- **Secret Key**: Your HolestPay API secret key for security

### Optional Settings

- **Order Status**: Status to set when payment is successful
- **Sort Order**: Position in payment method list
- **Country Restrictions**: Limit to specific countries
- **Order Total Limits**: Minimum and maximum order amounts

## File Structure

```
holestpay-opencart_3_and_4/
├── admin/
│   ├── controller/extension/payment/holestpay.php
│   ├── language/
│   │   ├── en-gb/extension/payment/holestpay.php
│   │   ├── sr-RS/extension/payment/holestpay.php
│   │   ├── sr-CS/extension/payment/holestpay.php
│   │   └── mk-MK/extension/payment/holestpay.php
│   └── view/template/extension/payment/holestpay.twig
├── catalog/
│   ├── controller/extension/payment/holestpay.php
│   ├── language/
│   │   ├── en-gb/extension/payment/holestpay.php
│   │   ├── sr-RS/extension/payment/holestpay.php
│   │   ├── sr-CS/extension/payment/holestpay.php
│   │   └── mk-MK/extension/payment/holestpay.php
│   ├── model/extension/payment/holestpay.php
│   └── view/theme/default/template/extension/payment/
│       ├── holestpay_success.twig
│       └── holestpay_failure.twig
├── holestpay.ocmod.xml
├── install.xml
└── README.md
```

## How It Works

### 1. Checkout Process
- Customer selects HolestPay as payment method
- Order is created in OpenCart
- HolestPay order is created via API
- Customer is redirected to HolestPay payment page

### 2. Payment Processing
- Customer completes payment on HolestPay
- HolestPay sends webhook notification
- Order status is updated automatically
- Customer is redirected back to store

### 3. Webhook Handling
- Receives real-time payment updates
- Verifies webhook signatures for security
- Updates order status and history
- Logs all activities for debugging

## API Integration

The extension integrates with HolestPay's API endpoints:

- **Sandbox**: `https://sandbox-api.holestpay.com/api/v1/orders`
- **Live**: `https://api.holestpay.com/api/v1/orders`

### API Features

- Order creation with customer details
- Payment URL generation
- Webhook signature verification
- Comprehensive error handling

## Database Changes

The extension adds two fields to the `order` table:

- `holestpay_uid`: Stores the HolestPay order identifier
- `holestpay_status`: Stores the current HolestPay payment status

These fields are automatically managed and provide full tracking of payment status.

## Security Features

- **Signature Verification**: All webhook communications are verified using MD5 signatures
- **Input Validation**: Comprehensive validation of all incoming data
- **Error Logging**: Detailed logging for debugging and security monitoring
- **SQL Injection Protection**: Uses OpenCart's built-in database escaping

## Troubleshooting

### Common Issues

1. **Payment Method Not Showing**
   - Check if extension is enabled
   - Verify country restrictions
   - Check order total limits

2. **Webhook Not Working**
   - Ensure webhook URL is accessible
   - Check server firewall settings
   - Verify webhook signature verification

3. **Order Status Not Updating**
   - Check webhook endpoint accessibility
   - Verify secret key configuration
   - Check error logs for details

### Debug Mode

Enable debug logging in OpenCart to see detailed information about:
- API requests and responses
- Webhook processing
- Database operations
- Error conditions

## Support

For technical support or questions about this extension:

- **Documentation**: Check this README and inline code comments
- **Logs**: Review OpenCart system logs for error details
- **HolestPay Support**: Contact HolestPay for API-related issues

## Version History

- **1.0.0**: Initial release with full OpenCart 3/4 compatibility

## License

This extension is provided as-is for use with HolestPay payment services.

## Language Support

The extension includes the following language support:
- **English (en-gb)** - Default language
- **Serbian (sr-RS)** - Serbian language support (Latin script)
- **Serbian Cyrillic (sr-CS)** - Serbian language support (Cyrillic script)
- **Macedonian (mk-MK)** - Macedonian language support

Additional languages can be added by creating new language files in the appropriate `language/` directories following the same format.

## Requirements

- OpenCart 3.0.0 or higher
- PHP 7.0 or higher
- cURL extension enabled
- SSL certificate (recommended for production)

## Changelog

### Version 1.0.0
- Initial release
- Full OpenCart 3 and 4 compatibility
- Complete payment gateway integration
- Webhook support
- Admin configuration panel
- Security features
- Comprehensive error handling
