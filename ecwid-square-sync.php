<?php
/*
Plugin Name: Easy Farm Cart to Square Sync
Description: Easy Farm Cart (Ecwid white-label) is the source of truth for title, description, and price. Automatically updates Square on product.updated. Inventory sync is bi-directional: Easy Farm Cart ↔ Square (latest authoritative event wins).
Version: 1.12
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

if (!defined('EC2SQ_VERSION')) define('EC2SQ_VERSION', '1.12');
if (!defined('EC2SQ_PLUGIN_TITLE')) define('EC2SQ_PLUGIN_TITLE', 'Easy Farm Cart ↔ Square Sync');

/*
CHANGELOG
1.12 (Force zero sync & enhanced diagnostics)
- Force-zero logic: do not short-circuit (noop) when desired inventory is 0; always execute robust zeroing path.
- Added inventory_zero_requested log event prior to zeroing attempts.
- Added explicit version alignment (header + EC2SQ_VERSION constant).
- Extended per-SKU diagnostic with Square tracking flags (track_inventory, stockable, sellable), presence info (present_at_all_locations, present_location_ids, present_at_target_location), parent item presence, and recent write metadata.
- Added REST endpoint /ecwid-square-sync/v1/diag/sku?sku=... (GET) returning diagnostic JSON (admin only, manage_options) for easier external inspection.
- Added last set metadata exposure (last Ecwid → Square set & last Square → Ecwid set) in diagnostic output.
*/

/* The remainder of the file is unchanged except where annotated with // v1.12 change */

... REPLACED CONTENT PLACEHOLDER ...
