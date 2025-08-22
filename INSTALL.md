# Installation Guide

## Quick Setup

### 1. Download the Real Stripe Library

This plugin requires the official Stripe PHP library. Replace the placeholder with the real library:

**Option A: Using Composer (Recommended)**
```bash
cd /path/to/your/plugin/directory
composer require stripe/stripe-php
```

Then update `ecwid-square-sync.php` line ~58:
```php
// Replace this line:
require_once(ETS_PLUGIN_DIR . 'includes/stripe-php/init.php');

// With this:
require_once(ETS_PLUGIN_DIR . 'vendor/autoload.php');
```

**Option B: Manual Download**
1. Download from: https://github.com/stripe/stripe-php/releases
2. Extract to `includes/stripe-php/`
3. Update the require statement to point to the real `init.php`

### 2. API Credentials

**Ecwid:**
1. Log into your Ecwid admin
2. Go to Settings → General → Store ID
3. Go to Apps → Custom Apps → API
4. Create a new API token with read access to products

**Stripe:**
1. Log into your Stripe Dashboard
2. Go to Developers → API Keys
3. Copy your Publishable and Secret keys
4. For webhooks: Developers → Webhooks → Add endpoint
5. Endpoint URL: `https://yoursite.com/wp-json/ets/v1/webhook/stripe`
6. Events to send: `customer.subscription.*`

### 3. WordPress Plugin Installation

1. Upload plugin files to `/wp-content/plugins/ecwid-stripe-subscriptions/`
2. Activate through WordPress admin
3. Go to ETS Subscriptions → Settings
4. Enter your API credentials
5. Test API connections
6. Start creating subscriptions!

### 4. Security Requirements

- SSL certificate (required for Stripe)
- WordPress 5.0+
- PHP 7.4+
- cURL enabled

### 5. Testing

Always test with Stripe's test keys first:
- Use test API keys (sk_test_... and pk_test_...)
- Create test subscriptions
- Verify webhook functionality
- Only switch to live keys when ready for production

## Troubleshooting

**Common Issues:**

1. **"Class not found" errors**: Install the real Stripe library
2. **API connection fails**: Check credentials and SSL
3. **Webhooks not working**: Verify endpoint URL and secret
4. **Products not loading**: Check Ecwid API token permissions

**Debug Mode:**

Enable WordPress debug mode to see detailed error messages:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for error messages.

## Next Steps

1. Customize the subscription intervals for your products
2. Test the customer experience with Stripe checkout
3. Monitor subscriptions in your Stripe dashboard
4. Set up email notifications for subscription events