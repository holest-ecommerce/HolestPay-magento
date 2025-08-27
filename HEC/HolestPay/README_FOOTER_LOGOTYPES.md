# HolestPay Footer Logotypes Configuration

## Overview
The "Insert Footer Logotypes" feature allows you to display HolestPay logotypes (cards, banks, 3DS) in the footer of your Magento store. When enabled, these logotypes will appear on every frontend page.

## Configuration

### 1. Enable the Feature
In your Magento admin panel:
1. Go to **Stores** > **Configuration** > **Sales** > **Payment Methods**
2. Find the **HolestPay** section
3. Set **Insert Footer Logotypes** to **Yes**
4. Save the configuration

### 2. Configure Logotypes Data
The footer logotypes are configured through the `configuration[environment].pos_parameters` structure. You need to set up the following structure in your configuration:

```json
{
  "pos_parameters": {
    "Logotypes Card Images": "https://example.com/images/visa.png,https://example.com/images/mastercard.png,https://example.com/images/amex.png",
    "Logotypes Banks": "https://example.com/images/bank1.png:https://www.bank1.com,https://example.com/images/bank2.png:https://www.bank2.com",
    "Logotypes 3DS": "https://example.com/images/visa-secure.png:https://www.visa.com/secure,https://example.com/images/mc-idcheck.png:https://www.mastercard.com/idcheck"
  }
}
```

### 3. Configuration Structure Details

#### Cards Section
- **Key**: `Logotypes Card Images`
- **Format**: Comma-separated image URLs only (no links)
- **Example**: `"https://example.com/visa.png,https://example.com/mastercard.png"`
- **Note**: Cards are displayed as static images without clickable links

#### Banks Section
- **Key**: `Logotypes Banks`
- **Format**: Comma-separated pairs of `image_url:link_url`
- **Example**: `"https://example.com/bank1.png:https://www.bank1.com,https://example.com/bank2.png:https://www.bank2.com"`
- **Note**: Each bank logo is clickable and links to the specified URL

#### 3DS Section
- **Key**: `Logotypes 3DS`
- **Format**: Comma-separated pairs of `image_url:link_url`
- **Example**: `"https://example.com/visa-secure.png:https://www.visa.com/secure,https://example.com/mc-idcheck.png:https://www.mastercard.com/idcheck"`
- **Note**: Each 3DS logo is clickable and links to the specified URL

## Features

### Responsive Design
The footer logotypes are fully responsive and will adapt to different screen sizes:
- Desktop: Horizontal layout with proper spacing
- Tablet: Adjusted sizing and spacing
- Mobile: Vertical layout with centered alignment

### Hover Effects
- Logotype images have subtle opacity changes on hover
- Smooth transitions for better user experience

### Accessibility
- All images include proper alt text
- Links open in new tabs for better user experience
- Semantic HTML structure

## Styling

The footer logotypes use the CSS class `hpay_footer_branding` and can be customized by overriding the styles in your theme:

```css
.hpay_footer_branding {
    /* Custom background, borders, spacing */
}

.hpay-footer-branding-cards img,
.hpay-footer-branding-bank img,
.hpay-footer-branding-3ds img {
    /* Custom image sizing and effects */
}
```

## Technical Implementation

### Files Created
- `Block/Frontend/FooterLogotypes.php` - Main block class
- `view/frontend/templates/footer-logotypes.phtml` - Template file
- `view/frontend/web/css/footer-logotypes.css` - Styling
- `view/frontend/layout/default.xml` - Layout updates

### Integration Points
- Added to the main footer container via `default.xml`
- Automatically loads on all frontend pages
- Respects the configuration setting
- Integrates with existing HolestPay configuration system

## Troubleshooting

### Logotypes Not Displaying
1. Check if "Insert Footer Logotypes" is set to "Yes" in admin
2. Verify that `configuration[environment].pos_parameters` contains valid data
3. Check browser console for any JavaScript errors
4. Ensure the configuration data is properly saved and accessible

### Styling Issues
1. Check if `footer-logotypes.css` is properly loaded
2. Verify that your theme doesn't override the styles
3. Check for CSS conflicts with other modules

### Performance
- Images are loaded directly from the configured URLs
- No additional database queries when disabled
- Minimal impact on page load time
