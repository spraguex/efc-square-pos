# Ecwid to Stripe Subscriptions WordPress Plugin

Transform your Ecwid products into recurring Stripe subscriptions with this powerful WordPress plugin.

## Features

- **Ecwid Integration**: Fetch products directly from your Ecwid store
- **Stripe Subscriptions**: Convert one-time products into recurring subscriptions
- **Flexible Billing**: Support for daily, weekly, monthly, and yearly billing cycles
- **Admin Interface**: Easy-to-use WordPress admin interface for managing subscriptions
- **Frontend Integration**: Subscription options appear automatically on your Ecwid products
- **Webhook Support**: Handle Stripe subscription events automatically
- **Secure**: Built with WordPress security best practices

## Installation

1. Upload the plugin files to `/wp-content/plugins/ecwid-stripe-subscriptions/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure your API credentials in the settings page

## Configuration

### Ecwid Setup

1. Go to your Ecwid admin panel
2. Navigate to Settings → General to find your Store ID
3. Go to Apps → Custom Apps → API to create an API token
4. Grant read access to products

### Stripe Setup

1. Log into your Stripe dashboard
2. Get your API keys from the Developers section
3. Set up a webhook endpoint: `https://yoursite.com/wp-json/ets/v1/webhook/stripe`
4. Configure webhook to listen for subscription events

### Plugin Configuration

1. Navigate to ETS Subscriptions → Settings in your WordPress admin
2. Enter your Ecwid Store ID and API Token
3. Enter your Stripe Secret Key and Publishable Key
4. Enter your Stripe Webhook Secret
5. Set default subscription intervals
6. Test your API connections

## Usage

### Creating Subscriptions

1. Go to ETS Subscriptions in your WordPress admin
2. Click "Fetch Products from Ecwid" to load your products
3. Click "Create Subscription" on any product
4. Configure billing interval (daily, weekly, monthly, yearly)
5. Set interval count (e.g., every 2 months)
6. Click "Create Subscription"

### Frontend Integration

Once a product is converted to a subscription, customers will see subscription options alongside the regular purchase buttons on your Ecwid store.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Ecwid store with API access
- Stripe account with API access
- SSL certificate (required for Stripe)

## File Structure

```
ecwid-stripe-subscriptions/
├── ecwid-square-sync.php          # Main plugin file
├── includes/
│   ├── admin/
│   │   ├── admin-page.php         # Main admin interface
│   │   └── settings-page.php      # Settings configuration
│   └── stripe-php/
│       └── init.php               # Stripe library placeholder
├── assets/
│   ├── js/
│   │   ├── admin.js               # Admin JavaScript
│   │   └── frontend.js            # Frontend JavaScript
│   └── css/
│       └── admin.css              # Admin styles
└── README.md
```

## API Integration

### Ecwid API

The plugin uses the Ecwid REST API v3 to:
- Fetch store products
- Retrieve product details
- Access product metadata

### Stripe API

The plugin integrates with Stripe to:
- Create products and prices
- Set up recurring billing
- Create checkout sessions
- Handle webhook events

## Database Tables

The plugin creates one custom table:

### `wp_ets_product_subscriptions`

Stores the mapping between Ecwid products and Stripe subscriptions:

- `id` - Unique identifier
- `ecwid_product_id` - Ecwid product ID
- `stripe_product_id` - Stripe product ID
- `stripe_price_id` - Stripe price ID for subscription
- `subscription_interval` - Billing interval (day, week, month, year)
- `subscription_interval_count` - Number of intervals between charges
- `is_active` - Whether subscription is active
- `created_at` - Creation timestamp
- `updated_at` - Last update timestamp

## Security

- All API communications use HTTPS
- Webhook signatures are verified
- Admin functions require `manage_options` capability
- User inputs are sanitized and validated
- Nonces protect against CSRF attacks

## Development

### Adding Custom Features

The plugin is designed to be extensible. You can add custom functionality by:

1. Hooking into existing actions and filters
2. Extending the main plugin class
3. Adding custom API endpoints
4. Modifying the admin interface

### Testing

Before going live:

1. Use Stripe test mode for development
2. Test API connections in the settings page
3. Create test subscriptions
4. Verify webhook functionality

## Support

For support and feature requests, please visit the plugin repository or contact the developer.

## License

This plugin is licensed under the GPLv2 or later.

## Changelog

### 2.0.0
- Complete rewrite from Square sync to Stripe subscriptions
- Added Ecwid API integration for product fetching
- Added Stripe API integration for subscription creation
- Created admin interface for product selection and subscription management
- Added webhook handlers for Stripe subscription events
- Added configuration settings for API keys and subscription options
