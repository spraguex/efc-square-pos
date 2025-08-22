# EFC Square POS Plugin

## Overview

This WordPress plugin provides Point of Sale functionality integrating Easy Farm Cart with Square payment processing system.

## Features

- Square payment processing integration
- Transaction management
- Admin configuration panel
- Support for both sandbox and production environments

## Development

### File Structure

- `efc-square-pos.php` - Main plugin file
- `uninstall.php` - Plugin uninstall script
- `README.md` - Plugin documentation

### Settings

The plugin adds a settings page under WordPress Settings > EFC Square POS where you can configure:

- Square Application ID
- Square Access Token
- Environment (Sandbox/Production)

### Database

The plugin creates a custom table `wp_efc_square_pos_transactions` to track payment transactions.

## Support

For support, please create an issue in the GitHub repository.