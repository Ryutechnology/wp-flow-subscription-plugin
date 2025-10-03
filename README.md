# Flow Suscripciones - WordPress Plugin

A WordPress plugin that integrates Flow payment gateway with WooCommerce for recurring subscription payments, specifically designed for Pace Coffee Roasters.

## Features

- **Flow Payment Integration**: Direct integration with Flow API for Chilean payments
- **WooCommerce Integration**: Creates customers and orders in WooCommerce
- **Product Variations Support**: Full support for WooCommerce product variations with custom attributes
- **Subscription Management**: Complete subscription lifecycle management
- **Automated Recurring Payments**: Cron-based automated monthly charges
- **Custom Shortcodes**: Easy-to-use shortcodes for subscription forms
- **Meta Data Support**: Custom attributes like "formato" and "molienda" for coffee products
- **Failure Handling**: Comprehensive error handling and customer notifications

## Installation

1. Upload the plugin files to `/wp-content/plugins/flow-suscripciones/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure Flow API credentials in the plugin settings
4. Ensure WooCommerce is installed and activated

## Database Schema

The plugin creates a `flow_subscriptions` table with the following structure:

```sql
CREATE TABLE wp_flow_subscriptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(190) NOT NULL,
    plan_id VARCHAR(100) NOT NULL,
    mandato_id VARCHAR(100) DEFAULT NULL,
    amount INT NOT NULL,
    status VARCHAR(20) DEFAULT 'pendiente',
    flow_customer_id VARCHAR(100) DEFAULT NULL,
    product_id INT DEFAULT NULL,
    variation_id INT DEFAULT NULL,
    formato VARCHAR(100) DEFAULT NULL,
    molienda VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Shortcodes

### `[flow_suscripcion]`
Main subscription form shortcode.

**Basic Usage:**
```php
[flow_suscripcion]
```

**With Parameters:**
```php
[flow_suscripcion
    plan="Premium Coffee Plan"
    amount="8500"
    product_id="123"]
```

**With Variation Support:**
```php
[flow_suscripcion
    variation_id="456"
    formato="Grano"
    molienda="Fino"]
```

**Parameters:**
- `plan` - Plan name (default: "Plan Básico")
- `amount` - Monthly amount in CLP (default: 5000)
- `product_id` - WooCommerce product ID
- `variation_id` - WooCommerce variation ID
- `formato` - Coffee format attribute
- `molienda` - Coffee grind attribute

### `[flow_success]`
Success page shortcode for completed subscriptions.

```php
[flow_success]
```

### `[flow_failure]`
Failure page shortcode for failed subscriptions.

```php
[flow_failure]
```

## Product Integration

### Automatic Detection
The plugin automatically detects WooCommerce products when:
- Shortcode is used on a WooCommerce product page
- URL contains `product_id` parameter
- URL contains `variation_id` parameter
- URL contains `add-to-cart` parameter

### Price Handling
Price priority (highest to lowest):
1. **Variation Price** - When `variation_id` is provided
2. **Custom Subscription Meta** - `_flow_subscription_amount`
3. **Product Price** - Base WooCommerce product price
4. **Shortcode Amount** - Fallback parameter

### Variation Attributes
Supported variation attributes:
- `pa_formato` / `formato` / `attribute_pa_formato`
- `pa_molienda` / `molienda` / `attribute_pa_molienda`

## API Integration

### Flow API Endpoints
- Customer Creation
- Plan Management
- Credit Card Registration
- Recurring Payments
- Webhook Handling

### WooCommerce Integration
- Customer Creation/Lookup
- Order Generation
- Product Association
- Variation Handling
- Meta Data Storage

## Webhook Support

The plugin handles Flow webhooks at:
```
POST /wp-json/flow/v1/webhook
```

**Supported Events:**
- `subscription_created`
- `payment_completed`
- `payment_failed`
- `subscription_cancelled`

## Cron Jobs

### Daily Subscription Processing
Automatically processes recurring payments daily.

**Schedule:** Daily at configured time
**Action:** `flow_daily_charges`

### Failed Payment Handling
- Tracks failed payment attempts
- Sends customer notifications
- Suspends subscriptions after 3 failed attempts

## Configuration

### Required Settings
- Flow API Key
- Flow Secret Key
- Flow Environment (sandbox/production)

### Optional Settings
- Custom success/failure page URLs
- Email notification settings
- Cron schedule configuration

## Status Management

### Subscription Statuses
- `pendiente` - Pending activation
- `activo` - Active subscription
- `cancelado` - Cancelled subscription
- `suspendido` - Suspended due to payment failures

## Error Handling

### Customer Notifications
The plugin sends email notifications for:
- Payment failures (with retry links)
- Subscription suspensions
- Successful activations

### Admin Notifications
Administrators receive notifications for:
- Subscription suspensions
- Failed payment attempts
- System errors

## Development

### File Structure
```
flow-suscripciones/
├── flow-suscripciones.php          # Main plugin file
├── includes/
│   ├── class-flow-activator.php    # Plugin activation/deactivation
│   ├── class-flow-api.php          # Flow API integration
│   ├── class-flow-cron.php         # Cron job handling
│   ├── class-flow-database.php     # Database operations
│   ├── class-flow-loader.php       # Plugin loader
│   ├── class-flow-shortcode.php    # Shortcode implementation
│   ├── class-flow-subscription.php # Subscription management
│   └── class-flow-woocommerce.php  # WooCommerce integration
└── README.md                       # This file
```

### Debug Logging
Enable WordPress debug logging to see Flow plugin logs:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Look for logs with prefix: `Flow Debug:`

### Custom Hooks
The plugin provides WordPress actions for customization:

```php
// Subscription events
do_action('flow_subscription_activated', $subscription_id, $subscription);
do_action('flow_subscription_cancelled', $subscription_id, $subscription);
do_action('flow_subscription_updated', $subscription_id, $data);
do_action('flow_subscription_deleted', $subscription_id, $subscription);

// Payment events
do_action('flow_recurring_payment_processed', $subscription_id, $subscription, $result);
do_action('flow_payment_completed_webhook', $data);
do_action('flow_payment_failed_webhook', $data, $subscription);
```

## Usage Examples

### Basic Coffee Subscription
```php
[flow_suscripcion plan="Monthly Coffee" amount="12000"]
```

### Product-Specific Subscription
```php
[flow_suscripcion product_id="123"]
```

### Variation-Specific Subscription
```php
[flow_suscripcion variation_id="456"]
```

### URL-Based Auto-Detection
```
https://example.com/coffee-subscription/?variation_id=456&formato=grano&molienda=fino
```

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- MySQL 5.6+
- Flow API credentials (Chilean payment gateway)

## Support

For technical support or feature requests, please contact the development team.

## Changelog

### Version 1.0.0
- Initial release
- Basic Flow integration
- WooCommerce customer/order creation
- Subscription management
- Cron-based recurring payments

### Version 1.1.0
- Added product variation support
- Enhanced meta data handling (formato, molienda)
- Improved price detection from variations
- Better error handling and logging

## License

This plugin is proprietary software developed for Pace Coffee Roasters.