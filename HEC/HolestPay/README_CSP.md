# HolestPay Content Security Policy (CSP) Configuration

## Overview

This module includes Content Security Policy (CSP) configuration to allow HolestPay domains for script loading, iframe embedding, and other necessary functionality. **Note: `unsafe-eval` is required for HolestPay's JavaScript functionality to work properly.**

## CSP Policies Configured

### 1. Script Source (`script-src`)
Allows loading of JavaScript files from HolestPay domains and enables dynamic code execution:
- `*.holest.com` - **Wildcard coverage** for all HolestPay domains (sandbox, production, subdomains)
- `d3hqo5epsodxzz.cloudfront.net` - CloudFront CDN for HolestPay assets
- `'unsafe-eval'` - **Required** for HolestPay's dynamic JavaScript execution (added via observer)

### 2. Frame Source (`frame-src`) ⚠️ **CRITICAL**
**Essential for payment forms** - allows embedding of iframes from HolestPay domains:
- `*.holest.com` - **Wildcard coverage** for all HolestPay domains (sandbox, production, subdomains)
- `d3hqo5epsodxzz.cloudfront.net` - CloudFront CDN for HolestPay iframes

**Note:** Iframe loading is **mandatory** for HolestPay payment forms to function. Without this, payment forms cannot be displayed.

### 3. Connect Source (`connect-src`)
Allows API connections to HolestPay domains:
- `*.holest.com` - **Wildcard coverage** for all HolestPay domains (sandbox, production, subdomains)
- `d3hqo5epsodxzz.cloudfront.net` - CloudFront CDN for HolestPay APIs

### 4. Image Source (`img-src`)
Allows loading of images from HolestPay domains (for logotypes):
- `*.holest.com` - **Wildcard coverage** for all HolestPay domains (sandbox, production, subdomains)
- `d3hqo5epsodxzz.cloudfront.net` - CloudFront CDN for HolestPay images

### 5. Form Action (`form-action`)
Allows form submissions to HolestPay domains:
- `*.holest.com` - **Wildcard coverage** for all HolestPay domains (sandbox, production, subdomains)
- `d3hqo5epsodxzz.cloudfront.net` - CloudFront CDN for HolestPay forms

## Domain Coverage Efficiency

### **Wildcard Strategy:**
- **`*.holest.com`** covers:
  - ✅ `sandbox.pay.holest.com` (sandbox environment)
  - ✅ `pay.holest.com` (production environment)
  - ✅ `*.pay.holest.com` (any subdomain of pay.holest.com)
  - ✅ Any future HolestPay subdomains
  - ✅ **Reduces rules from 4+ entries to just 1 wildcard per directive**

### **Benefits:**
- **Simplified Configuration**: Fewer rules to maintain
- **Future-Proof**: Automatically covers new HolestPay subdomains
- **Cleaner CSP Headers**: Shorter, more readable CSP strings
- **Easier Maintenance**: Single wildcard rule vs. multiple specific domains

## Important Security Considerations

### Why `unsafe-eval` is Required
HolestPay's payment system uses dynamic JavaScript execution for:
- Payment form generation
- Dynamic validation rules
- Real-time payment processing
- Secure token handling

**This is a legitimate requirement** for HolestPay's functionality and is not a security risk when used with trusted domains.

### Why CloudFront Domain is Required
HolestPay uses Amazon CloudFront CDN (`d3hqo5epsodxzz.cloudfront.net`) for:
- Faster asset delivery
- Global content distribution
- Load balancing
- Security enhancements

**This is a legitimate CDN domain** used by HolestPay for optimal performance.

### Iframe Loading is Critical
- **Payment forms are embedded as iframes** from HolestPay domains
- **Without frame-src permissions, payment forms cannot load**
- **This will break the entire payment flow**

## Files

### 1. `etc/csp_whitelist.xml`
Magento's standard CSP whitelist configuration file that defines allowed domains for various CSP directives. This handles the domain whitelisting using efficient wildcard patterns.

### 2. `etc/frontend/events.xml`
Registers an observer to add CSP headers dynamically, including the `unsafe-eval` directive.

### 3. `Observer/AddCspHeaders.php`
Observer that adds CSP headers to HTTP responses, ensuring HolestPay domains are allowed and `unsafe-eval` is included for script-src.

## How It Works

1. **Static CSP Configuration**: The `csp_whitelist.xml` file provides static CSP configuration for domain whitelisting using efficient wildcard patterns.

2. **Dynamic CSP Headers**: The `AddCspHeaders` observer dynamically adds or modifies CSP headers in HTTP responses to ensure `unsafe-eval` is included for script-src.

3. **Wildcard Efficiency**: `*.holest.com` covers all current and future HolestPay domains with minimal configuration.

4. **Hybrid Approach**: Combines Magento's standard CSP whitelist with custom header modification for directives not supported by the whitelist (like `unsafe-eval`).

## Installation

After installing the module:

1. **Clear Magento Cache**: Run `php bin/magento cache:clean` and `php bin/magento cache:flush`

2. **Verify Installation**: Check that the module is properly installed and enabled

3. **Test Functionality**: Load a page with HolestPay payment methods to verify CSP is working

4. **Verify Iframe Loading**: Check that payment forms load properly in iframes

## Troubleshooting

### CSP Still Blocking Scripts
If you still see CSP violations after installing this module:

1. **Clear Magento Cache**: Run `php bin/magento cache:clean` and `php bin/magento cache:flush`

2. **Check Browser Console**: Look for specific CSP violation messages

3. **Verify Module Installation**: Ensure the module is properly installed and enabled

4. **Check Server Configuration**: Some servers may override CSP headers

5. **Check Magento CSP Settings**: Ensure CSP is enabled in Magento admin (Stores > Configuration > Security > Content Security Policy)

### Iframe Loading Issues
If payment forms are not loading:

1. **Check frame-src CSP**: Ensure `*.holest.com` and `d3hqo5epsodxzz.cloudfront.net` are allowed
2. **Verify Browser Console**: Look for frame-src CSP violations
3. **Test with Different Browsers**: Some browsers may have stricter CSP enforcement

### Adding Additional Domains
To add more domains to the CSP whitelist, modify the `csp_whitelist.xml` file and add new `<value>` elements under the appropriate policy.

## Security Notes

- The CSP configuration allows the minimum necessary access for HolestPay functionality
- **Wildcard `*.holest.com` covers all verified HolestPay domains** with a single rule
- **`unsafe-eval` is required** for HolestPay's JavaScript functionality
- **Iframe loading is mandatory** for payment forms to work
- **CloudFront domain is legitimate** and used by HolestPay for performance
- The configuration follows Magento's CSP best practices while accommodating HolestPay's requirements
- **Efficient wildcard approach** reduces configuration complexity while maintaining security

## Testing

After installation, test the CSP configuration by:

1. Loading a page with HolestPay payment methods
2. Checking browser console for CSP violations
3. Verifying that HolestPay scripts load without errors
4. **Testing iframe embedding** - this is critical for payment forms
5. Verifying that payment forms render properly
6. Checking that assets load from CloudFront CDN

## Magento CSP Configuration

If you need to enable CSP globally in Magento:

1. Go to **Stores > Configuration > Security > Content Security Policy**
2. Set **Enable Content Security Policy** to **Yes**
3. Configure additional CSP settings as needed
4. Save configuration and clear cache

## Common CSP Violations and Solutions

### Script-src Violations
- **Error**: "Refused to execute inline script" or "Refused to evaluate"
- **Solution**: Ensure `unsafe-eval` is included in script-src policy (handled by observer)

### Frame-src Violations  
- **Error**: "Refused to frame" or payment forms not loading
- **Solution**: Verify frame-src includes `*.holest.com` and CloudFront CDN

### Connect-src Violations
- **Error**: "Refused to connect" to HolestPay APIs
- **Solution**: Ensure connect-src includes `*.holest.com` and CloudFront CDN

### Asset Loading Issues
- **Error**: "Refused to load" from CloudFront
- **Solution**: Verify that `d3hqo5epsodxzz.cloudfront.net` is included in relevant policies

## Expected CSP Headers

With the wildcard approach, your CSP headers should look like:

```
script-src ... *.holest.com d3hqo5epsodxzz.cloudfront.net 'unsafe-eval'
frame-src ... *.holest.com d3hqo5epsodxzz.cloudfront.net
connect-src ... *.holest.com d3hqo5epsodxzz.cloudfront.net
img-src ... *.holest.com d3hqo5epsodxzz.cloudfront.net
form-action ... *.holest.com d3hqo5epsodxzz.cloudfront.net
```

**Note:** The `*.holest.com` wildcard automatically covers all current and future HolestPay subdomains, making the configuration both efficient and future-proof.
