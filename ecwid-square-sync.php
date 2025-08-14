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
- Force-zero logic: no early noop when desired inventory == 0; always execute robust zeroing path (unless already 0 and verified).
- Added zeroing log events: inventory_zero_requested, inventory_zero_adjustment_attempt, inventory_zero_pc_fallback, inventory_zero_success, inventory_zero_already.
- Added recording of last non-zero quantity (transient ec2sq_last_nonzero_{variation_id}).
- Added last_zero_attempt transient (ec2sq_last_zero_attempt_{variation_id}) with method & before quantity.
- Expanded diagnostics: Square variation tracking flags (track_inventory, stockable, sellable), presence (present_at_all_locations, present_at_location_ids, present_at_target_location, parent_item_present_at_target_location), parent item details.
- Diagnostic returns metadata: last_sq_set, last_ec_set, last_nonzero_before_zero, last_zero_attempt, will_force_zero_if_set_now.
- Added REST endpoint /ecwid-square-sync/v1/diag/sku (admin only) for per-SKU diagnostic JSON.
- Added previous tracking/presence values in square_variation_presence_set logs.
*/

/*
|--------------------------------------------------------------------------
| Admin Settings Page
|--------------------------------------------------------------------------
*/
add_action('admin_menu', function() {
    add_menu_page(
        EC2SQ_PLUGIN_TITLE,
        EC2SQ_PLUGIN_TITLE,
        'manage_options',
        'ecwid-square-sync',
        'ecwid_square_sync_settings_page'
    );
});

function ecwid_square_sync_settings_page() {

    if (isset($_POST['ecwid_secret_token']) || isset($_POST['generate_webhook_secret']) || isset($_POST['square_webhook_signature_key']) || isset($_POST['ec2sq_save_debug_settings']) || isset($_POST['ec2sq_diag_sku']) || isset($_POST['ec2sq_clear_logs'])) {
        check_admin_referer('ecwid_square_sync_settings');

        if (isset($_POST['ecwid_secret_token'])) {
            update_option('ecwid_secret_token', sanitize_text_field($_POST['ecwid_secret_token']));
            update_option('square_access_token', sanitize_text_field($_POST['square_access_token']));
            update_option('square_location_id', sanitize_text_field($_POST['square_location_id']));
            ec2sq_log('info', 'config', 'settings_saved', ['section' => 'credentials']);
        }
        if (isset($_POST['generate_webhook_secret'])) {
            $new_secret = wp_generate_password(32, false, false);
            update_option('ecwid_webhook_secret', $new_secret);
            ec2sq_log('warn', 'config', 'webhook_secret_rotated');
        }
        if (isset($_POST['square_webhook_signature_key'])) {
            update_option('square_webhook_signature_key', sanitize_text_field($_POST['square_webhook_signature_key']));
            ec2sq_log('info', 'config', 'square_signature_key_saved');
        }
        if (isset($_POST['ec2sq_save_debug_settings'])) {
            update_option('ec2sq_logs_enabled', isset($_POST['ec2sq_logs_enabled']) ? 1 : 0);
            $limit = max(100, min(5000, intval($_POST['ec2sq_logs_limit'] ?? 1000)));
            update_option('ec2sq_logs_limit', $limit);
            ec2sq_log('info', 'config', 'debug_settings_saved', ['enabled' => !!get_option('ec2sq_logs_enabled', 0), 'limit' => $limit]);
        }
        if (isset($_POST['ec2sq_clear_logs'])) {
            ec2sq_logs_clear();
            ec2sq_log('warn', 'config', 'logs_cleared_by_admin');
        }
    }

    if (isset($_GET['ec2sq_export']) && $_GET['ec2sq_export'] === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'ec2sq_export_logs') && current_user_can('manage_options')) {
        $logs = ec2sq_logs_get_all();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ec2sq-logs-' . gmdate('Ymd-His') . '.json"');
        echo json_encode($logs, JSON_PRETTY_PRINT);
        exit;
    }

    $ecwid_secret_token = get_option('ecwid_secret_token', '');
    $ecwid_store_id = get_option('ecwid_store_id', 'Not found');
    $square_access_token = get_option('square_access_token', '');
    $square_location_id = get_option('square_location_id', '');
    $ecwid_webhook_secret = get_option('ecwid_webhook_secret', '');
    $square_webhook_signature_key = get_option('square_webhook_signature_key', '');

    $ec2sq_logs_enabled = (int)get_option('ec2sq_logs_enabled', 1);
    $ec2sq_logs_limit = (int)get_option('ec2sq_logs_limit', 1000);

    $ecwid_webhook_url = esc_url_raw( add_query_arg(
        ['secret' => $ecwid_webhook_secret ?: 'set-a-secret'],
        rest_url('ecwid-square-sync/v1/order-webhook')
    ) );
    $square_webhook_url = esc_url_raw( rest_url('ecwid-square-sync/v1/square-webhook') );

    $diag_result = null;
    if (isset($_POST['ec2sq_diag_sku'])) {
        check_admin_referer('ecwid_square_sync_settings');
        $sku = trim(sanitize_text_field($_POST['diag_sku'] ?? ''));
        $diag_result = ec2sq_run_sku_diagnostic($sku);
        if ($diag_result) {
            ec2sq_log('info', 'diagnostic', 'sku_diagnostic', ['sku' => $sku, 'result' => $diag_result]);
        }
    }

    $export_url = add_query_arg([
        'page' => 'ecwid-square-sync',
        'ec2sq_export' => '1',
        '_wpnonce' => wp_create_nonce('ec2sq_export_logs'),
    ], admin_url('admin.php'));

    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;justify-content:space-between;">
            <span><?php echo esc_html(EC2SQ_PLUGIN_TITLE); ?> Settings</span>
            <span style="font-size:12px;color:#6b7280;">Version <?php echo esc_html(EC2SQ_VERSION); ?></span>
        </h1>

        <form method="post" style="margin-bottom:1.5em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Easy Farm Cart Store ID</th>
                    <td><input type="text" value="<?php echo esc_attr($ecwid_store_id); ?>" readonly></td>
                </tr>
                <tr>
                    <th>Easy Farm Cart Secret Access Token</th>
                    <td>
                        <input type="text" name="ecwid_secret_token" value="<?php echo esc_attr($ecwid_secret_token); ?>" style="width: 480px;">
                        <br><small>Token with read access to products and orders (Ecwid/Easy Farm Cart v3 API).</small>
                    </td>
                </tr>
                <tr>
                    <th>Square Access Token</th>
                    <td>
                        <input type="text" name="square_access_token" value="<?php echo esc_attr($square_access_token); ?>" style="width: 480px;">
                        <br><small>Needs Catalog & Inventory write scopes.</small>
                    </td>
                </tr>
                <tr>
                    <th>Square Location ID</th>
                    <td>
                        <input type="text" name="square_location_id" value="<?php echo esc_attr($square_location_id); ?>" style="width: 480px;">
                        <br><small>Only this location is synced.</small>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Credentials'); ?>
        </form>

        <h2>Easy Farm Cart Webhook (orders + product updates → Square)</h2>
        <p>Use this URL in Easy Farm Cart (Ecwid) webhooks/notifications. Subscribe to: order.created, order.updated (or order.paid), and product.updated.</p>
        <p><code><?php echo esc_html($ecwid_webhook_url); ?></code></p>
        <form method="post" style="margin-top:0.5em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <input type="hidden" name="generate_webhook_secret" value="1" />
            <button class="button">Generate new webhook secret</button>
            <span style="margin-left:10px;color:#666;">Current secret: <?php echo $ecwid_webhook_secret ? esc_html(substr($ecwid_webhook_secret, 0, 6).'••••') : 'not set'; ?></span>
        </form>

        <hr>

        <h2>Square Webhook (POS inventory → Easy Farm Cart)</h2>
        <p>Add this URL in Square Developer Dashboard (Webhook subscriptions) and subscribe to inventory.count.updated.</p>
        <p><code><?php echo esc_html($square_webhook_url); ?></code></p>
        <form method="post" style="margin-top:0.5em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Square Webhook Signature Key (Production)</th>
                    <td>
                        <input type="text" name="square_webhook_signature_key" value="<?php echo esc_attr($square_webhook_signature_key); ?>" style="width: 480px;">
                        <br><small>From Square Dashboard → Webhooks → Signature Key.</small>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Square Webhook Settings'); ?>
        </form>

        <hr>
        <h2>Debug & Diagnostics</h2>
        <form method="post" style="margin-bottom: 1em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Enable Persistent Logs</th>
                    <td>
                        <label><input type="checkbox" name="ec2sq_logs_enabled" <?php checked($ec2sq_logs_enabled, 1); ?>> Capture webhook & API events</label>
                        <br><small>Stored in DB; oldest entries dropped past limit.</small>
                    </td>
                </tr>
                <tr>
                    <th>Log Retention Limit</th>
                    <td>
                        <input type="number" name="ec2sq_logs_limit" value="<?php echo esc_attr($ec2sq_logs_limit); ?>" min="100" max="5000" step="50">
                        <br><small>Maximum entries retained.</small>
                    </td>
                </tr>
            </table>
            <input type="hidden" name="ec2sq_save_debug_settings" value="1">
            <?php submit_button('Save Debug Settings'); ?>
        </form>

        <form method="post" style="margin-bottom: 1em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <input type="hidden" name="ec2sq_clear_logs" value="1">
            <button class="button">Clear Logs</button>
            <a class="button" href="<?php echo esc_url($export_url); ?>">Export Logs (JSON)</a>
        </form>

        <h3>Per-SKU Diagnostic</h3>
        <form method="post" style="margin-bottom:1em;">
            <?php wp_nonce_field('ecwid_square_sync_settings'); ?>
            <input type="text" name="diag_sku" placeholder="Enter SKU..." style="width: 300px;">
            <button class="button" name="ec2sq_diag_sku" value="1">Run Lookup</button>
        </form>
        <?php if ($diag_result): ?>
            <div style="background:#1e1e1e;color:#eaeaea;padding:1em;font-family:monospace;max-height:400px;overflow:auto;">
                <?php echo esc_html(json_encode($diag_result, JSON_PRETTY_PRINT)); ?>
            </div>
        <?php endif; ?>

        <h3>Recent Logs</h3>
        <?php
            $logs = ec2sq_logs_get_recent(200);
            if (empty($logs)) {
                echo '<p>No logs yet.</p>';
            } else {
                echo '<div style="background:#fff;border:1px solid #ddd;max-height:420px;overflow:auto;">';
                echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Level</th><th>Direction</th><th>Event</th><th>SKU</th><th>Square Var</th><th>Loc</th><th>RID</th><th>Details</th></tr></thead><tbody>';
                foreach ($logs as $e) {
                    $dir_raw = $e['dir'] ?? '';
                    $dir_disp = $dir_raw;
                    if ($dir_raw === 'ecwid->square') $dir_disp = 'Easy Farm Cart → Square';
                    elseif ($dir_raw === 'square->ecwid') $dir_disp = 'Square → Easy Farm Cart';
                    elseif ($dir_raw === 'manual') $dir_disp = 'Manual';
                    elseif ($dir_raw === 'diagnostic') $dir_disp = 'Diagnostic';
                    elseif ($dir_raw === 'config') $dir_disp = 'Config';

                    echo '<tr>';
                    echo '<td>'.esc_html($e['ts'] ?? '').'</td>';
                    echo '<td>'.esc_html(strtoupper($e['level'] ?? '')).'</td>';
                    echo '<td>'.esc_html($dir_disp).'</td>';
                    echo '<td>'.esc_html($e['event'] ?? '').'</td>';
                    echo '<td>'.esc_html($e['sku'] ?? '').'</td>';
                    echo '<td>'.esc_html($e['variation_id'] ?? '').'</td>';
                    echo '<td>'.esc_html($e['location_id'] ?? '').'</td>';
                    echo '<td>'.esc_html($e['rid'] ?? '').'</td>';
                    $details = $e['data'] ?? [];
                    $brief = json_encode($details);
                    if (strlen($brief) > 160) $brief = substr($brief, 0, 160) . '…';
                    echo '<td><code>'.esc_html($brief).'</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        ?>

        <hr>
        <h2>Manual Sync</h2>
        <form method="post">
            <?php wp_nonce_field('ecwid_square_sync_products'); ?>
            <input type="hidden" name="sync_now" value="1">
            <p><button class="button button-primary" type="submit">Sync Easy Farm Cart → Square NOW</button></p>
        </form>
        <?php
        if (isset($_POST['sync_now'])) {
            echo "<h2>Manual Sync Console</h2><div style='background:#1e1e1e;color:#eaeaea;padding:1em;font-family:monospace;max-height:600px;overflow:auto;'>";
            ecwid_square_sync_products_admin(true);
            echo "</div>";
        }
        ?>
    </div>
    <?php
}

/*
|--------------------------------------------------------------------------
| REST API Routes
|--------------------------------------------------------------------------
*/
add_action('rest_api_init', function() {
    register_rest_route('ecwid-square-sync/v1', '/order-webhook', [
        'methods' => 'POST',
        'callback' => 'ecwid_square_order_webhook',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('ecwid-square-sync/v1', '/square-webhook', [
        'methods' => 'POST',
        'callback' => 'ecwid_square_square_webhook',
        'permission_callback' => '__return_true'
    ]);
    // v1.12 change: Add diagnostic REST endpoint
    register_rest_route('ecwid-square-sync/v1', '/diag/sku', [
        'methods' => 'GET',
        'callback' => function($request) {
            if (!current_user_can('manage_options')) {
                return new WP_REST_Response(['error'=>'Unauthorized'],401);
            }
            $sku = trim(sanitize_text_field($request->get_param('sku')??''));
            if (!$sku) return new WP_REST_Response(['error'=>'Missing sku'],400);
            return new WP_REST_Response(ec2sq_run_sku_diagnostic($sku),200);
        },
        'permission_callback' => function(){ return current_user_can('manage_options'); }
    ]);
});

/*
|--------------------------------------------------------------------------
| Ecwid Webhook (orders + product.updated) → Square
|--------------------------------------------------------------------------
*/
function ecwid_square_order_webhook(WP_REST_Request $request) {
    $rid = 'ecwid_' . bin2hex(random_bytes(6));
    $secret_required = get_option('ecwid_webhook_secret', '');
    $provided = $request->get_param('secret') ?: '';

    if (!$secret_required || !$provided || !hash_equals($secret_required, $provided)) {
        ec2sq_log('warn', 'ecwid->square', 'unauthorized', ['reason' => 'secret_mismatch'], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }

    $body = $request->get_json_params();
    if (!is_array($body)) $body = [];
    $first = is_array($body) && isset($body[0]) && is_array($body[0]) ? $body[0] : $body;

    $eventType = $first['eventType'] ?? $first['type'] ?? '';
    $entityId  = $first['entityId'] ?? null;
    $productId = $first['productId'] ?? null;

    $store_id = get_option('ecwid_store_id');
    $ecwid_token = get_option('ecwid_secret_token');
    $square_token = get_option('square_access_token');
    $square_location_id = get_option('square_location_id');

    if (!$store_id || !$ecwid_token || !$square_token || !$square_location_id) {
        ec2sq_log('error', 'ecwid->square', 'config_missing', ['store_id' => !!$store_id, 'ecwid_token' => !!$ecwid_token, 'square_token' => !!$square_token, 'location' => !!$square_location_id], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Missing configuration'], 500);
    }

    // PRODUCT.UPDATED branch (Easy Farm Cart is authoritative for name/description/price + inventory)
    if (stripos($eventType, 'product.updated') !== false) {
        $pid = $productId ?: $entityId;
        ec2sq_log('info', 'ecwid->square', 'product_webhook_received', ['eventType' => $eventType, 'productId' => $pid], $rid);

        if (!$pid) {
            ec2sq_log('error', 'ecwid->square', 'product_payload_missing_id', ['body' => $body], $rid);
            return new WP_REST_Response(['ok' => false, 'error' => 'Missing productId/entityId'], 400);
        }

        $product = ecwid_square_get_product_by_id($store_id, $ecwid_token, $pid);
        if (!$product) {
            ec2sq_log('error', 'ecwid->square', 'ecwid_product_fetch_failed', ['productId' => $pid], $rid);
            return new WP_REST_Response(['ok' => false, 'error' => 'Failed to fetch product'], 502);
        }

        // Build (cached) Square SKU map: sku => { variation_id, item_id }
        $square_sku_map = ec2sq_get_square_sku_map($square_token, true);

        // Collect all SKUs (with metadata) from Easy Farm Cart product
        $sku_updates = []; // ['sku','qty','name','price','track_inventory']
        if (!empty($product['combinations'])) {
            foreach ($product['combinations'] as $combo) {
                $sku = $combo['sku'] ?? '';
                if (!$sku) continue;
                $tracks = array_key_exists('quantity', $combo);
                $qty = $tracks ? $combo['quantity'] : null;
                $price = isset($combo['price']) ? $combo['price'] : ($product['price'] ?? 0);
                $var_name = ecwid_square_build_variation_name($product, $combo);
                $sku_updates[] = [
                    'sku' => $sku,
                    'qty' => ($tracks && $qty !== null) ? intval($qty) : null,
                    'name' => $var_name,
                    'price' => floatval($price),
                    'track_inventory' => ($tracks && $qty !== null)
                ];
            }
        } else {
            $sku = $product['sku'] ?? '';
            if ($sku) {
                $tracks = array_key_exists('quantity', $product);
                $qty = $tracks ? $product['quantity'] : null;
                $sku_updates[] = [
                    'sku' => $sku,
                    'qty' => ($tracks && $qty !== null) ? intval($qty) : null,
                    'name' => $product['name'],
                    'price' => floatval($product['price'] ?? 0),
                    'track_inventory' => ($tracks && $qty !== null)
                ];
            }
        }

        // Update Square item core (name/description) for each involved item_id
        $item_ids = [];
        foreach ($sku_updates as $u) {
            $sku = $u['sku'];
            if (isset($square_sku_map[$sku]['item_id'])) {
                $item_ids[$square_sku_map[$sku]['item_id']] = true;
            }
        }
        foreach (array_keys($item_ids) as $item_id) {
            $ok = ecwid_square_update_item_core($square_token, $item_id, $product['name'] ?? '', $product['description'] ?? '', false);
            ec2sq_log($ok ? 'info' : 'error', 'ecwid->square', 'square_item_core_updated', [
                'item_id' => $item_id,
                'name' => $product['name'] ?? '',
            ], $rid, null, null, $square_location_id);
        }

        // Update each variation's meta, ensure presence/tracking, then set inventory
        $results = [];
        foreach ($sku_updates as $u) {
            $sku = $u['sku'];
            if (empty($square_sku_map[$sku]['variation_id'])) {
                $results[$sku] = ['ok' => false, 'error' => 'SKU not present in Square'];
                ec2sq_log('error', 'ecwid->square', 'square_sku_missing', ['sku' => $sku, 'productId' => $pid], $rid, $sku);
                continue;
            }
            $variation_id = $square_sku_map[$sku]['variation_id'];

            // Update variation meta from Easy Farm Cart (name/price/sku)
            $ecwid_var_payload = [
                'name' => $u['name'],
                'sku' => $sku,
                'price' => $u['price'],
                'description' => $product['description'] ?? '',
                'track_inventory' => $u['track_inventory'],
                'quantity' => $u['qty']
            ];
            $meta_ok = ecwid_square_update_square_variation($square_token, $variation_id, $ecwid_var_payload, false);
            ec2sq_log($meta_ok ? 'info' : 'error', 'ecwid->square', 'square_variation_update', [
                'sku' => $sku, 'variation_id' => $variation_id, 'fields' => ['name','price','sku']
            ], $rid, $sku, $variation_id, $square_location_id);

            // Ensure presence/tracking at location
            $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $variation_id, $square_location_id, false);
            if (!$ensured) {
                $results[$sku] = ['ok' => false, 'error' => 'Ensure location/tracking failed after meta update'];
                ec2sq_log('error', 'ecwid->square', 'square_presence_failed', ['sku' => $sku, 'variation_id' => $variation_id, 'location' => $square_location_id], $rid, $sku, $variation_id, $square_location_id);
                continue;
            }

            // If Easy Farm Cart tracks inventory for this SKU, set Square absolute inventory to match
            if ($u['qty'] !== null) {
                $before = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
                $inv_ok = ecwid_square_set_absolute_inventory($square_token, $variation_id, $square_location_id, $u['qty'], false);
                $after = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
                ec2sq_log($inv_ok ? 'info' : 'error', 'ecwid->square', 'square_inventory_set', [
                    'sku' => $sku, 'variation_id' => $variation_id, 'location' => $square_location_id,
                    'before' => $before, 'desired' => intval($u['qty']), 'after' => $after
                ], $rid, $sku, $variation_id, $square_location_id);
                $results[$sku] = $inv_ok ? ['ok' => true, 'square_set_to' => intval($u['qty'])] : ['ok' => false, 'error' => 'Square inventory update failed'];
            } else {
                ec2sq_log('info', 'ecwid->square', 'ecwid_not_tracking', ['sku' => $sku, 'productId' => $pid], $rid, $sku);
                $results[$sku] = ['ok' => true, 'message' => 'Metadata updated; inventory not tracked in Easy Farm Cart'];
            }
        }

        return new WP_REST_Response(['ok' => true, 'eventType' => $eventType, 'productId' => $pid, 'results' => $results], 200);
    }

    // ORDER.* branch (inventory only)
    $orderId = $first['entityId'] ?? $first['orderId'] ?? null;
    ec2sq_log('info', 'ecwid->square', 'order_webhook_received', ['eventType' => $eventType, 'orderId' => $orderId], $rid);

    if (!$orderId) {
        ec2sq_log('error', 'ecwid->square', 'payload_invalid', ['body' => $body], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Missing orderId/entityId'], 400);
    }

    $order = ecwid_square_get_order($store_id, $ecwid_token, $orderId);
    if ($order === false) {
        ec2sq_log('error', 'ecwid->square', 'ecwid_order_fetch_failed', ['orderId' => $orderId], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Failed to fetch order'], 502);
    }

    $line_items = $order['items'] ?? [];
    if (!is_array($line_items) || empty($line_items)) {
        ec2sq_log('info', 'ecwid->square', 'order_no_items', ['orderId' => $orderId], $rid);
        return new WP_REST_Response(['ok' => true, 'message' => 'No items in order'], 200);
    }

    // Build (cached) Square SKU map
    $square_sku_map = ec2sq_get_square_sku_map($square_token, true);

    $results = [];
    foreach ($line_items as $it) {
        $sku = $it['sku'] ?? '';
        if (!$sku) { $results[] = ['ok' => true, 'message' => 'No SKU on line item']; continue; }

        $prod = ecwid_square_find_product_by_sku($store_id, $ecwid_token, $sku);
        if (!$prod) {
            $results[$sku] = ['ok' => false, 'error' => 'Product by SKU not found in Easy Farm Cart'];
            ec2sq_log('error', 'ecwid->square', 'ecwid_product_not_found', ['sku' => $sku], $rid, $sku);
            continue;
        }

        $current_qty = null;
        if (!empty($prod['combinations'])) {
            foreach ($prod['combinations'] as $combo) {
                if (($combo['sku'] ?? '') === $sku) { $current_qty = array_key_exists('quantity', $combo) ? $combo['quantity'] : null; break; }
            }
        } else {
            if (($prod['sku'] ?? '') === $sku) $current_qty = array_key_exists('quantity', $prod) ? $prod['quantity'] : null;
        }

        if ($current_qty === null) {
            $results[$sku] = ['ok' => true, 'message' => 'SKU not tracked in Easy Farm Cart'];
            ec2sq_log('info', 'ecwid->square', 'ecwid_not_tracking', ['sku' => $sku], $rid, $sku);
            continue;
        }

        $variation_id = $square_sku_map[$sku]['variation_id'] ?? null;
        if (!$variation_id) {
            $results[$sku] = ['ok' => false, 'error' => 'SKU not present in Square'];
            ec2sq_log('error', 'ecwid->square', 'square_sku_missing', ['sku' => $sku], $rid, $sku);
            continue;
        }

        $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $variation_id, $square_location_id, false);
        if (!$ensured) {
            $results[$sku] = ['ok' => false, 'error' => 'Ensure location/tracking failed'];
            ec2sq_log('error', 'ecwid->square', 'square_presence_failed', ['sku' => $sku, 'variation_id' => $variation_id, 'location' => $square_location_id], $rid, $sku, $variation_id, $square_location_id);
            continue;
        }

        $before = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
        $inv_ok = ecwid_square_set_absolute_inventory($square_token, $variation_id, $square_location_id, $current_qty, false);
        $after = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
        ec2sq_log($inv_ok ? 'info' : 'error', 'ecwid->square', 'square_inventory_set', [
            'sku' => $sku, 'variation_id' => $variation_id, 'location' => $square_location_id,
            'before' => $before, 'desired' => intval($current_qty), 'after' => $after
        ], $rid, $sku, $variation_id, $square_location_id);

        $results[$sku] = $inv_ok ? ['ok' => true, 'square_set_to' => intval($current_qty)] : ['ok' => false, 'error' => 'Square inventory update failed'];
    }

    return new WP_REST_Response(['ok' => true, 'eventType' => $eventType, 'orderId' => $orderId, 'results' => $results], 200);
}

/*
|--------------------------------------------------------------------------
| Helper: safe int casting
|--------------------------------------------------------------------------
*/
if (!function_exists('ec2sq_int_or_null')) {
    function ec2sq_int_or_null($v) {
        if ($v === null) return null;
        if (is_numeric($v)) return intval($v);
    }
}

/*
|--------------------------------------------------------------------------
| Square Webhook (inventory.count.updated) → Easy Farm Cart (Improved anti-zero logic)
|--------------------------------------------------------------------------
*/
function ecwid_square_square_webhook(WP_REST_Request $request) {
    $rid = 'square_' . bin2hex(random_bytes(6));
    $signature_key = get_option('square_webhook_signature_key', '');
    $square_location_id = get_option('square_location_id');
    $store_id = get_option('ecwid_store_id');
    $ecwid_token = get_option('ecwid_secret_token');

    if (!$signature_key || !$square_location_id || !$store_id || !$ecwid_token) {
        ec2sq_log('error', 'square->ecwid', 'config_missing', ['sig_key' => !!$signature_key, 'location' => !!$square_location_id, 'store' => !!$store_id, 'ecwid_token' => !!$ecwid_token], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Missing configuration'], 500);
    }

    $raw_body = $request->get_body();
    $sig_header = '';
    foreach (['x-square-hmacsha256-signature', 'X-Square-HmacSHA256-Signature', 'x-square-signature', 'X-Square-Signature'] as $h) {
        $hv = $request->get_header($h);
        if ($hv) { $sig_header = $hv; break; }
    }
    $notification_url = rest_url('ecwid-square-sync/v1/square-webhook');
    $calc = base64_encode(hash_hmac('sha256', $notification_url . $raw_body, $signature_key, true));
    $calc_body_only = base64_encode(hash_hmac('sha256', $raw_body, $signature_key, true));
    if (!hash_equals($calc, $sig_header) && !hash_equals($calc_body_only, $sig_header)) {
        ec2sq_log('warn', 'square->ecwid', 'signature_invalid', ['received' => $sig_header, 'calc' => $calc], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid signature'], 401);
    }

    $payload = json_decode($raw_body, true);
    if (!is_array($payload)) $payload = [];
    $type = $payload['type'] ?? $payload['event_type'] ?? '';
    ec2sq_log('info', 'square->ecwid', 'square_webhook_received', ['type' => $type], $rid);

    if (stripos($type, 'inventory.count.updated') === false && stripos($type, 'inventory.updated') === false) {
        ec2sq_log('info', 'square->ecwid', 'event_ignored', ['type' => $type], $rid);
        return new WP_REST_Response(['ok' => true, 'ignored' => 'Event not handled', 'type' => $type], 200);
    }

    $counts = [];
    $obj = $payload['data']['object'] ?? [];
    if (isset($obj['inventory_count'])) $counts[] = $obj['inventory_count'];
    if (isset($obj['inventory_counts']) && is_array($obj['inventory_counts'])) $counts = array_merge($counts, $obj['inventory_counts']);
    if (empty($counts) && isset($payload['data']['object'])) $counts[] = $payload['data']['object'];

    if (empty($counts)) {
        ec2sq_log('info', 'square->ecwid', 'no_counts_in_payload', [], $rid);
        return new WP_REST_Response(['ok' => true, 'message' => 'No inventory counts in payload'], 200);
    }

    $square_token = get_option('square_access_token');
    if (!$square_token) {
        ec2sq_log('error', 'square->ecwid', 'square_token_missing', [], $rid);
        return new WP_REST_Response(['ok' => false, 'error' => 'Square token missing'], 500);
    }

    $results = [];
    foreach ($counts as $ic) {
        $variation_id = $ic['catalog_object_id'] ?? '';
        $loc_id = $ic['location_id'] ?? '';
        $state = strtoupper($ic['state'] ?? '');
        $occurred_at = $ic['occurred_at'] ?? ($payload['created_at'] ?? gmdate('c'));
        $event_qty_raw = $ic['quantity'] ?? null;
        $event_qty = ec2sq_int_or_null($event_qty_raw);

        if (!$variation_id || $loc_id !== $square_location_id) {
            ec2sq_log('info', 'square->ecwid', 'location_or_variation_mismatch', ['variation_id' => $variation_id, 'loc_id' => $loc_id], $rid, null, $variation_id, $loc_id);
            continue;
        }
        if ($state !== '' && $state !== 'IN_STOCK') {
            ec2sq_log('info', 'square->ecwid', 'state_not_instock', ['state' => $state, 'variation_id' => $variation_id], $rid, null, $variation_id, $loc_id);
            continue;
        }

        $recent = get_transient('ec2sq_last_sq_set_' . $variation_id);
        if (is_array($recent) && isset($recent['qty'], $recent['at']) && $event_qty !== null && intval($recent['qty']) === intval($event_qty) && (time() - intval($recent['at'])) < 120) {
            $results[] = ['variation_id' => $variation_id, 'ok' => true, 'skipped' => 'Duplicate self write (event qty)'];
            ec2sq_log('info', 'square->ecwid', 'dedup_skip_recent_self_write', ['variation_id' => $variation_id, 'qty' => $event_qty, 'basis' => 'event'], $rid, null, $variation_id, $square_location_id);
            continue;
        }

        $sku = ecwid_square_get_sku_for_variation($square_token, $variation_id);
        if (!$sku) {
            $results[] = ['variation_id' => $variation_id, 'ok' => false, 'error' => 'SKU not found'];
            ec2sq_log('error', 'square->ecwid', 'sku_not_found_for_variation', ['variation_id' => $variation_id], $rid, null, $variation_id, $square_location_id);
            continue;
        }

        $last_ts_key = 'ec2sq_last_sq_event_ts_' . $variation_id;
        $prev_ts = get_option($last_ts_key, '');
        if ($prev_ts && strtotime($occurred_at) < strtotime($prev_ts)) {
            $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => true, 'skipped' => 'Older than last applied'];
            ec2sq_log('info', 'square->ecwid', 'older_event_skipped', ['variation_id' => $variation_id, 'occurred_at' => $occurred_at, 'prev_ts' => $prev_ts], $rid, $sku, $variation_id, $square_location_id);
            continue;
        }

        $fetch_qty = null;
        $authoritative_qty = null;
        $used_source = null;

        if ($event_qty !== null) {
            $authoritative_qty = $event_qty;
            $used_source = 'event';
        }

        if ($authoritative_qty === null) {
            $fetch_qty = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
            if ($fetch_qty === false) {
                $results[] = ['variation_id' => $variation_id, 'ok' => false, 'error' => 'Fetch counts failed'];
                ec2sq_log('error', 'square->ecwid', 'square_count_fetch_failed', ['variation_id' => $variation_id], $rid, $sku, $variation_id, $square_location_id);
                continue;
            }
            $authoritative_qty = intval($fetch_qty);
            $used_source = 'fetch_only';
        } else {
            $fetch_qty = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
            if ($fetch_qty !== false) {
                $fetch_int = intval($fetch_qty);
                if ($authoritative_qty > 0 && $fetch_int === 0) {
                    $mismatchConfirmed = true;
                    for ($r=1; $r<=2; $r++) {
                        usleep(400000); // 0.4s
                        $retry = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
                        if ($retry !== false && intval($retry) === $authoritative_qty) {
                            $mismatchConfirmed = false;
                            $fetch_int = intval($retry);
                            break;
                        }
                    }
                    if ($mismatchConfirmed) {
                        ec2sq_log('warn', 'square->ecwid', 'event_fetch_mismatch_proceed_event', [
                            'variation_id' => $variation_id,
                            'event_qty' => $authoritative_qty,
                            'fetch_qty' => $fetch_int
                        ], $rid, $sku, $variation_id, $square_location_id);
                    }
                } elseif ($authoritative_qty === 0 && $fetch_int > 0) {
                    $authoritative_qty = $fetch_int;
                    $used_source = 'fetch_override_zero_event';
                }
            }
        }

        if ($authoritative_qty === null) {
            ec2sq_log('error', 'square->ecwid', 'authoritative_qty_null', ['variation_id' => $variation_id], $rid, $sku, $variation_id, $square_location_id);
            continue;
        }

        $product = ecwid_square_find_product_by_sku($store_id, $ecwid_token, $sku);
        if (!$product) {
            $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => false, 'error' => 'SKU not found in Easy Farm Cart'];
            ec2sq_log('error', 'square->ecwid', 'ecwid_sku_not_found', ['sku' => $sku], $rid, $sku, $variation_id, $square_location_id);
            continue;
        }

        $updated = false;
        if (!empty($product['combinations'])) {
            $combo_id = null;
            foreach ($product['combinations'] as $combo) {
                if (($combo['sku'] ?? '') === $sku) { $combo_id = $combo['id'] ?? null; break; }
            }
            if ($combo_id) {
                $updated = ecwid_square_update_ecwid_combination_quantity($store_id, $ecwid_token, $product['id'], $combo_id, $authoritative_qty);
                ec2sq_log($updated ? 'info' : 'error', 'square->ecwid', 'ecwid_combo_quantity_set', [
                    'sku' => $sku, 'product_id' => $product['id'], 'combo_id' => $combo_id,
                    'after' => $authoritative_qty, 'source' => $used_source, 'event_qty' => $event_qty, 'fetch_qty' => $fetch_qty
                ], $rid, $sku, $variation_id, $square_location_id);
            } else {
                $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => false, 'error' => 'Combination ID not found'];
                ec2sq_log('error', 'square->ecwid', 'ecwid_combo_id_missing', ['sku' => $sku, 'product_id' => $product['id']], $rid, $sku, $variation_id, $square_location_id);
                continue;
            }
        } else {
            if (($product['sku'] ?? '') === $sku) {
                $updated = ecwid_square_update_ecwid_product_quantity($store_id, $ecwid_token, $product['id'], $authoritative_qty);
                ec2sq_log($updated ? 'info' : 'error', 'square->ecwid', 'ecwid_product_quantity_set', [
                    'sku' => $sku, 'product_id' => $product['id'],
                    'after' => $authoritative_qty, 'source' => $used_source, 'event_qty' => $event_qty, 'fetch_qty' => $fetch_qty
                ], $rid, $sku, $variation_id, $square_location_id);
            } else {
                $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => false, 'error' => 'SKU mismatch on simple product'];
                ec2sq_log('error', 'square->ecwid', 'ecwid_simple_sku_mismatch', ['sku' => $sku, 'product_id' => $product['id']], $rid, $sku, $variation_id, $square_location_id);
                continue;
            }
        }

        if ($updated) {
            update_option($last_ts_key, $occurred_at);
            set_transient('ec2sq_last_ec_set_' . $sku, ['qty' => $authoritative_qty, 'at' => time()], 120);
            $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => true, 'ecwid_set_to' => $authoritative_qty, 'source' => $used_source];
        } else {
            $results[] = ['variation_id' => $variation_id, 'sku' => $sku, 'ok' => false, 'error' => 'Failed to update Easy Farm Cart'];
        }
    }

    return new WP_REST_Response(['ok' => true, 'type' => $type, 'results' => $results], 200);
}

/*
|--------------------------------------------------------------------------
| Manual / Bulk Sync (Easy Farm Cart → Square)
|--------------------------------------------------------------------------
*/
function ecwid_square_sync_products_admin($debug = false) {
    $ecwid_store_id = get_option('ecwid_store_id');
    $ecwid_token = get_option('ecwid_secret_token');
    $square_token = get_option('square_access_token');
    $square_location_id = get_option('square_location_id');

    $rid = 'manual_' . bin2hex(random_bytes(6));
    ec2sq_log('info', 'manual', 'sync_started', [], $rid);

    $ok = true;

    if (!$ecwid_store_id || !$ecwid_token) {
        ecwid_square_dbg("[Easy Farm Cart] Store ID or Token missing.", $debug, 'error');
        ec2sq_log('error', 'manual', 'ecwid_credentials_missing', [], $rid);
        $ok = false;
    } else {
        $res = ecwid_square_validate_ecwid_token($ecwid_store_id, $ecwid_token);
        if ($res['success']) {
            ecwid_square_dbg("[Easy Farm Cart] API token valid. Example product: " . esc_html($res['example_name']), $debug);
        } else {
            ecwid_square_dbg("[Easy Farm Cart] API token test failed: " . esc_html($res['error']), $debug, 'error');
            ec2sq_log('error', 'manual', 'ecwid_token_invalid', ['error' => $res['error']], $rid);
            $ok = false;
        }
    }

    if (!$square_token || !$square_location_id) {
        if (!$square_token) ecwid_square_dbg("[Square] Access token missing.", $debug, 'error');
        if (!$square_location_id) ecwid_square_dbg("[Square] Location ID missing.", $debug, 'error');
        ec2sq_log('error', 'manual', 'square_credentials_missing', ['token' => !!$square_token, 'location' => !!$square_location_id], $rid);
        $ok = false;
    } else {
        $res = ecwid_square_validate_square_token($square_token, $square_location_id);
        if ($res['success']) {
            ecwid_square_dbg("[Square] Token + Location valid. Location name: " . esc_html($res['location_name']), $debug);
        } else {
            ecwid_square_dbg("[Square] Token test failed: " . esc_html($res['error']), $debug, 'error');
            ec2sq_log('error', 'manual', 'square_token_invalid', ['error' => $res['error']], $rid);
            $ok = false;
        }
    }

    if (!$ok) {
        ecwid_square_dbg("Aborting sync due to credential errors.", $debug, 'error');
        ec2sq_log('error', 'manual', 'sync_aborted_credentials', [], $rid);
        return;
    }

    ecwid_square_dbg("[Easy Farm Cart] Fetching all products...", $debug);
    $ecwid_products = ecwid_square_get_all_ecwid_products($ecwid_store_id, $ecwid_token, $debug);
    if (!$ecwid_products) {
        ecwid_square_dbg("[Easy Farm Cart] Could not fetch products.", $debug, 'error');
        ec2sq_log('error', 'manual', 'ecwid_products_fetch_failed', [], $rid);
        return;
    }
    ecwid_square_dbg("[Easy Farm Cart] Fetched " . count($ecwid_products) . " products.", $debug);

    ecwid_square_dbg("[Square] Fetching all catalog items...", $debug);
    $square_items = ecwid_square_get_all_square_items($square_token, $debug);
    if ($square_items === false) {
        ecwid_square_dbg("[Square] Could not fetch catalog items.", $debug, 'error');
        ec2sq_log('error', 'manual', 'square_items_fetch_failed', [], $rid);
        return;
    }
    ecwid_square_dbg("[Square] Fetched " . count($square_items) . " items.", $debug);

    $square_sku_map = [];
    foreach ($square_items as $item) {
        if (empty($item['variations'])) continue;
        foreach ($item['variations'] as $variation) {
            $sku = $variation['item_variation_data']['sku'] ?? '';
            if ($sku) {
                $square_sku_map[$sku] = [
                    'item_id' => $item['id'],
                    'variation_id' => $variation['id'],
                    'item' => $item,
                    'variation' => $variation
                ];
            }
        }
    }

    ecwid_square_dbg("[Sync] Comparing products...", $debug);
    $created = 0; $updated = 0; $skipped = 0;

    foreach ($ecwid_products as $product) {
        if (!isset($product['enabled']) || !$product['enabled']) {
            ecwid_square_dbg("[Sync] SKU: ".($product['sku'] ?? '[no SKU]')." - Skipped (disabled).", $debug, 'info');
            continue;
        }

        $has_combos = !empty($product['combinations']) && is_array($product['combinations']);
        if ($has_combos) {
            $result = ecwid_square_sync_variable_product($product, $square_token, $square_location_id, $square_sku_map, $debug);
            $created += $result['created']; $updated += $result['updated']; $skipped += $result['skipped'];
        } else {
            $sku = $product['sku'] ?? '';
            if (!$sku) {
                ecwid_square_dbg("[Sync] Simple product missing SKU. Skipped.", $debug, 'error');
                continue;
            }
            $ecwid_data = [
                'name' => $product['name'],
                'sku' => $sku,
                'price' => $product['price'],
                'description' => $product['description'],
                'track_inventory' => array_key_exists('quantity', $product) && $product['quantity'] !== null,
                'quantity' => $product['quantity']
            ];
            $msg_prefix = "[Sync] SKU: {$sku} - ";
            if (isset($square_sku_map[$sku])) {
                $variation_id = $square_sku_map[$sku]['variation_id'];
                if (ecwid_square_variation_needs_update($ecwid_data, $square_sku_map[$sku]['variation'])) {
                    ecwid_square_dbg($msg_prefix."Needs update. Updating...", $debug);
                    $okup = ecwid_square_update_square_variation($square_token, $variation_id, $ecwid_data, $debug);
                    ec2sq_log($okup ? 'info' : 'error', 'manual', 'square_variation_update', ['sku' => $sku, 'variation_id' => $variation_id], $rid, $sku, $variation_id, $square_location_id);
                    if ($okup) { $updated++; ecwid_square_dbg($msg_prefix."Updated.", $debug); }
                    else { $skipped++; ecwid_square_dbg($msg_prefix."Update failed.", $debug, 'error'); }
                } else {
                    $skipped++; ecwid_square_dbg($msg_prefix."Up to date.", $debug);
                }

                ecwid_square_dbg($msg_prefix."Inventory sync.", $debug, 'info');
                $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $variation_id, $square_location_id, $debug);
                if ($ensured && $ecwid_data['quantity'] !== null) {
                    $before = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
                    $inv_ok = false;
                    for ($attempt=1; $attempt<=6; $attempt++) {
                        $inv_ok = ecwid_square_set_absolute_inventory($square_token, $variation_id, $square_location_id, $ecwid_data['quantity'], $debug);
                        if ($inv_ok) break;
                        ecwid_square_dbg($msg_prefix."Inventory attempt $attempt failed, retrying...", $debug, 'error');
                        sleep(5);
                    }
                    $after = ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id);
                    ec2sq_log($inv_ok ? 'info' : 'error', 'manual', 'square_inventory_set', ['sku' => $sku, 'variation_id' => $variation_id, 'before' => $before, 'desired' => $ecwid_data['quantity'], 'after' => $after], $rid, $sku, $variation_id, $square_location_id);
                }
            } else {
                ecwid_square_dbg($msg_prefix."Missing in Square. Creating...", $debug);
                $new_variation_id = ecwid_square_create_square_item($square_token, $square_location_id, $ecwid_data, $debug, $real_variation_id);
                if ($new_variation_id) {
                    $created++;
                    ecwid_square_dbg($msg_prefix."Created.", $debug, 'success');
                    ec2sq_log('info', 'manual', 'square_item_created', ['sku' => $sku, 'variation_id' => $real_variation_id], $rid, $sku, $real_variation_id, $square_location_id);

                    $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $real_variation_id, $square_location_id, $debug);
                    if ($ensured && $ecwid_data['quantity'] !== null) {
                        $verified = false;
                        for ($v=1; $v<=6; $v++) {
                            if (ecwid_square_verify_variation_exists($square_token, $real_variation_id, $debug)) { $verified = true; break; }
                            ecwid_square_dbg($msg_prefix."Waiting for catalog propagation ($v/6)...", $debug, 'info');
                            sleep(5);
                        }
                        if ($verified) {
                            $before = ecwid_square_get_square_instock_count($square_token, $real_variation_id, $square_location_id);
                            $inv_ok = false;
                            for ($attempt=1; $attempt<=6; $attempt++) {
                                $inv_ok = ecwid_square_set_absolute_inventory($square_token, $real_variation_id, $square_location_id, $ecwid_data['quantity'], $debug);
                                if ($inv_ok) break;
                                ecwid_square_dbg($msg_prefix."Inventory attempt $attempt failed, retrying...", $debug, 'error');
                                sleep(5);
                            }
                            $after = ecwid_square_get_square_instock_count($square_token, $real_variation_id, $square_location_id);
                            ec2sq_log($inv_ok ? 'info' : 'error', 'manual', 'square_inventory_set', ['sku' => $sku, 'variation_id' => $real_variation_id, 'before' => $before, 'desired' => $ecwid_data['quantity'], 'after' => $after], $rid, $sku, $real_variation_id, $square_location_id);
                        }
                    }
                } else {
                    $skipped++; ecwid_square_dbg($msg_prefix."Create failed.", $debug, 'error');
                    ec2sq_log('error', 'manual', 'square_item_create_failed', ['sku' => $sku], $rid, $sku, null, $square_location_id);
                }
            }
        }
    }

    ec2sq_log('info', 'manual', 'sync_finished', ['created' => $created, 'updated' => $updated, 'skipped' => $skipped], $rid);
}

/*
|--------------------------------------------------------------------------
| Variable Product Sync Helper
|--------------------------------------------------------------------------
*/
function ecwid_square_sync_variable_product($product, $square_token, $square_location_id, &$square_sku_map, $debug = false) {
    $created = 0; $updated = 0; $skipped = 0;

    $combos = [];
    foreach ($product['combinations'] as $combo) {
        $sku = $combo['sku'] ?? '';
        if (!$sku) continue;
        $price = $combo['price'] ?? $product['price'];
        $qty = array_key_exists('quantity', $combo) ? $combo['quantity'] : $product['quantity'];
        $var_name = ecwid_square_build_variation_name($product, $combo);
        $combos[] = [
            'sku' => $sku,
            'name' => $var_name,
            'price' => $price,
            'quantity' => $qty,
            'track_inventory' => $qty !== null
        ];
    }
    if (empty($combos)) {
        ecwid_square_dbg("[Sync] Product '{$product['name']}' has no valid combinations. Skipped.", $debug, 'error');
        return compact('created','updated','skipped');
    }

    $existing = []; $missing = []; $item_ids_present = [];
    foreach ($combos as $c) {
        if (isset($square_sku_map[$c['sku']])) {
            $existing[] = $c + [
                'variation_id' => $square_sku_map[$c['sku']]['variation_id'],
                'item_id' => $square_sku_map[$c['sku']]['item_id']
            ];
            $item_ids_present[$square_sku_map[$c['sku']]['item_id']] = true;
        } else {
            $missing[] = $c;
        }
    }

    $msg_prefix = "[Sync] Variable '{$product['name']}' - ";

    if (empty($existing)) {
        ecwid_square_dbg($msg_prefix."Create new multi-variation Square item...", $debug);
        $create_res = ecwid_square_create_square_item_with_variations($square_token, $square_location_id, $product['name'], $product['description'], $combos, $debug, $item_id_out, $sku_to_varid);
        if ($create_res) {
            $created++;
            foreach ($sku_to_varid as $sku => $var_id) {
                $square_sku_map[$sku] = [
                    'item_id' => $item_id_out,
                    'variation_id' => $var_id,
                    'item' => [ 'id' => $item_id_out, 'name' => $product['name'] ],
                    'variation' => [ 'id' => $var_id, 'item_variation_data' => [ 'sku' => $sku ] ]
                ];
                ec2sq_log('info', 'manual', 'square_variation_created', ['sku' => $sku, 'item_id' => $item_id_out, 'variation_id' => $var_id], null, $sku, $var_id, $square_location_id);
            }
            foreach ($combos as $c) {
                $var_id = $sku_to_varid[$c['sku']] ?? null;
                if (!$var_id) continue;
                $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $var_id, $square_location_id, $debug);
                if ($ensured && $c['quantity'] !== null) {
                    $before = ecwid_square_get_square_instock_count($square_token, $var_id, $square_location_id);
                    $inv_ok = ecwid_square_set_absolute_inventory($square_token, $var_id, $square_location_id, $c['quantity'], $debug);
                    $after = ecwid_square_get_square_instock_count($square_token, $var_id, $square_location_id);
                    ec2sq_log($inv_ok ? 'info' : 'error', 'manual', 'square_inventory_set', ['sku' => $c['sku'], 'variation_id' => $var_id, 'before' => $before, 'desired' => $c['quantity'], 'after' => $after], null, $c['sku'], $var_id, $square_location_id);
                }
            }
        } else {
            $skipped++; ecwid_square_dbg($msg_prefix."Create failed.", $debug, 'error');
            ec2sq_log('error', 'manual', 'square_multivar_create_failed', ['product' => $product['name']], null);
        }
        return compact('created','updated','skipped');
    }

    $unique_item_ids = array_keys($item_ids_present);
    if (count($unique_item_ids) > 1) {
        ecwid_square_dbg($msg_prefix."SKUs span multiple Square items. Manual cleanup needed.", $debug, 'error');
        ec2sq_log('warn', 'manual', 'square_multi_parent_items', ['item_ids' => $unique_item_ids], null);
        foreach ($existing as $e) {
            $var_id = $e['variation_id'];
            $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $var_id, $square_location_id, $debug);
            if ($ensured && $e['quantity'] !== null) {
                $inv_ok = ecwid_square_set_absolute_inventory($square_token, $var_id, $square_location_id, $e['quantity'], $debug);
                ec2sq_log($inv_ok ? 'info' : 'error', 'manual', 'square_inventory_set', ['sku' => $e['sku'], 'variation_id' => $var_id, 'desired' => $e['quantity']], null, $e['sku'], $var_id, $square_location_id);
            }
        }
        return compact('created','updated','skipped');
    }

    $item_id = $unique_item_ids[0];
    ecwid_square_update_item_core($square_token, $item_id, $product['name'], $product['description'], $debug);

    foreach ($existing as $e) {
        $var_id = $e['variation_id'];
        $ecwid_data = [
            'name' => $e['name'],
            'sku' => $e['sku'],
            'price' => $e['price'],
            'description' => $product['description'],
            'track_inventory' => $e['track_inventory'],
            'quantity' => $e['quantity']
        ];
        if (ecwid_square_variation_needs_update($ecwid_data, $square_sku_map[$e['sku']]['variation'])) {
            $ok = ecwid_square_update_square_variation($square_token, $var_id, $ecwid_data, $debug);
            ec2sq_log($ok ? 'info' : 'error', 'manual', 'square_variation_update', ['sku' => $e['sku'], 'variation_id' => $var_id], null, $e['sku'], $var_id, $square_location_id);
            if ($ok) $updated++; else $skipped++;
        } else {
            $skipped++;
        }
    }

    foreach ($missing as $m) {
        $new_var_id = ecwid_square_upsert_variation_for_item($square_token, $item_id, $m, $debug);
        if ($new_var_id) {
            $created++;
            $square_sku_map[$m['sku']] = [
                'item_id' => $item_id,
                'variation_id' => $new_var_id,
                'item' => [ 'id' => $item_id, 'name' => $product['name'] ],
                'variation' => [ 'id' => $new_var_id, 'item_variation_data' => [ 'sku' => $m['sku'], 'name' => $m['name'], 'price_money' => [ 'amount' => intval(round(floatval($m['price'])*100)) ] ] ]
            ];
            ec2sq_log('info', 'manual', 'square_variation_created', ['sku' => $m['sku'], 'variation_id' => $new_var_id], null, $m['sku'], $new_var_id, $square_location_id);
        } else {
            $skipped++; ec2sq_log('error', 'manual', 'square_variation_create_failed', ['sku' => $m['sku']], null, $m['sku'], null, $square_location_id);
        }
    }

    $all = array_merge($existing, $missing);
    foreach ($all as $c) {
        $var_id = $square_sku_map[$c['sku']]['variation_id'] ?? null;
        if (!$var_id) continue;
        $ensured = ecwid_square_ensure_variation_location_and_tracking($square_token, $var_id, $square_location_id, $debug);
        if ($ensured && $c['quantity'] !== null) {
            $before = ecwid_square_get_square_instock_count($square_token, $var_id, $square_location_id);
            $inv_ok = ecwid_square_set_absolute_inventory($square_token, $var_id, $square_location_id, $c['quantity'], $debug);
            $after = ecwid_square_get_square_instock_count($square_token, $var_id, $square_location_id);
            ec2sq_log($inv_ok ? 'info' : 'error', 'manual', 'square_inventory_set', ['sku' => $c['sku'], 'variation_id' => $var_id, 'before' => $before, 'desired' => $c['quantity'], 'after' => $after], null, $c['sku'], $var_id, $square_location_id);
        }
    }

    return compact('created','updated','skipped');
}

function ecwid_square_build_variation_name($product, $combo) {
    if (!empty($combo['options']) && is_array($combo['options'])) {
        $parts = [];
        foreach ($combo['options'] as $opt) {
            $n = isset($opt['name']) ? trim($opt['name']) : '';
            $v = isset($opt['value']) ? trim($opt['value']) : '';
            if ($n !== '' && $v !== '') $parts[] = "$n: $v";
            elseif ($v !== '') $parts[] = $v;
        }
        if (!empty($parts)) return implode(' / ', $parts);
    }
    return $product['name'];
}

/*
|--------------------------------------------------------------------------
| Square Catalog Helpers
|--------------------------------------------------------------------------
*/
function ecwid_square_create_square_item_with_variations($square_token, $location_id, $item_name, $description, $variations, $debug, &$item_id_out, &$sku_to_varid) {
    $var_objs = [];
    foreach ($variations as $v) {
        $var_objs[] = [
            'type' => 'ITEM_VARIATION',
            'id' => '#'.uniqid(),
            'present_at_all_locations' => true,
            'item_variation_data' => [
                'name' => $v['name'],
                'sku' => $v['sku'],
                'price_money' => [
                    'amount' => intval(round(floatval($v['price']) * 100)),
                    'currency' => 'USD'
                ],
                'track_inventory' => !!$v['track_inventory'],
                'stockable' => true,
                'sellable' => true
            ]
        ];
    }

    $body = [
        'idempotency_key' => uniqid('ecwid2sq_item_', true),
        'object' => [
            'type' => 'ITEM',
            'id' => '#'.uniqid(),
            'present_at_all_locations' => true,
            'item_data' => [
                'name' => $item_name,
                'description' => $description,
                'variations' => $var_objs
            ]
        ]
    ];

    $response = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ec2sq_log('error', 'manual', 'square_create_item_http_error', ['error' => $response->get_error_message()], null);
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $j = json_decode($resp_body, true);
    $obj = $j['object'] ?? $j['catalog_object'] ?? null;
    if (!($code >= 200 && $code < 300) || !$obj) {
        ec2sq_log('error', 'manual', 'square_create_item_failed', ['response' => $resp_body], null);
        return false;
    }

    $item_id_out = $obj['id'] ?? null;
    $sku_to_varid = [];
    $returned_vars = $obj['item_data']['variations'] ?? [];
    foreach ($returned_vars as $rv) {
        $sku = $rv['item_variation_data']['sku'] ?? '';
        $vid = $rv['id'] ?? '';
        if ($sku && $vid) $sku_to_varid[$sku] = $vid;
    }

    return $item_id_out && !empty($sku_to_varid);
}

function ecwid_square_upsert_variation_for_item($square_token, $item_id, $v, $debug = false) {
    $body = [
        'idempotency_key' => uniqid('ecwid2sq_var_', true),
        'object' => [
            'type' => 'ITEM_VARIATION',
            'id' => '#'.uniqid(),
            'present_at_all_locations' => true,
            'item_variation_data' => [
                'item_id' => $item_id,
                'name' => $v['name'],
                'sku' => $v['sku'],
                'price_money' => [
                    'amount' => intval(round(floatval($v['price']) * 100)),
                    'currency' => 'USD'
                ],
                'track_inventory' => !!$v['track_inventory'],
                'stockable' => true,
                'sellable' => true
            ]
        ]
    ];

    $response = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ec2sq_log('error', 'manual', 'square_upsert_variation_http_error', ['error' => $response->get_error_message()], null);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $j = json_decode($resp_body, true);
    $obj = $j['object'] ?? $j['catalog_object'] ?? null;
    if ($code >= 200 && $code < 300 && $obj && isset($obj['id'])) {
        return $obj['id'];
    }
    ec2sq_log('error', 'manual', 'square_upsert_variation_failed', ['response' => $resp_body], null);
    return false;
}

function ecwid_square_update_item_core($square_token, $item_id, $name, $description, $debug = false) {
    $get_url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($item_id);
    $resp = wp_remote_get($get_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $obj = $data['object'] ?? null;
    if (!$obj || ($obj['type'] ?? '') !== 'ITEM') return false;

    $obj['item_data']['name'] = $name;
    $obj['item_data']['description'] = $description;

    $up_body = [
        'idempotency_key' => uniqid('ecwid2sq_item_up_', true),
        'object' => $obj
    ];
    $up = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($up_body),
        'timeout' => 25
    ]);
    if (is_wp_error($up)) return false;
    $code = wp_remote_retrieve_response_code($up);
    return ($code >= 200 && $code < 300);
}

function ecwid_square_create_square_item($square_token, $location_id, $ecwid, $debug = false, &$real_variation_id = null) {
    $body = [
        'idempotency_key' => uniqid('ecwid2sq_', true),
        'object' => [
            'type' => 'ITEM',
            'id' => '#'.uniqid(),
            'present_at_all_locations' => true,
            'item_data' => [
                'name' => $ecwid['name'],
                'description' => $ecwid['description'],
                'variations' => [
                    [
                        'type' => 'ITEM_VARIATION',
                        'id' => '#'.uniqid(),
                        'present_at_all_locations' => true,
                        'item_variation_data' => [
                            'name' => $ecwid['name'],
                            'sku' => $ecwid['sku'],
                            'price_money' => [
                                'amount' => intval(round(floatval($ecwid['price'])*100)),
                                'currency' => 'USD'
                            ],
                            'track_inventory' => !!$ecwid['track_inventory'],
                            'stockable' => true,
                            'sellable' => true
                        ]
                    ]
                ]
            ]
        ]
    ];
    $response = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ec2sq_log('error', 'manual', 'square_create_http_error', ['error' => $response->get_error_message()], null);
        $real_variation_id = null;
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    $resp_body = wp_remote_retrieve_body($response);
    $body_json = json_decode($resp_body, true);
    $catalog_obj = $body_json['object'] ?? $body_json['catalog_object'] ?? null;

    if ($code >= 200 && $code < 300 && $catalog_obj && isset($catalog_obj['item_data']['variations'][0]['id'])) {
        $real_variation_id = $catalog_obj['item_data']['variations'][0]['id'];
        ec2sq_log('info', 'manual', 'square_item_created', ['variation_id' => $real_variation_id, 'sku' => $ecwid['sku']], null, $ecwid['sku'], $real_variation_id, $location_id);
        return $real_variation_id;
    }
    ec2sq_log('error', 'manual', 'square_create_failed', ['response' => $resp_body], null);
    $real_variation_id = null;
    return false;
}

function ecwid_square_update_square_variation($square_token, $variation_id, $ecwid, $debug = false) {
    $get_url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($variation_id);
    $resp = wp_remote_get($get_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) {
        ec2sq_log('error', 'manual', 'square_update_fetch_http_error', ['error' => $resp->get_error_message()], null, $ecwid['sku'], $variation_id);
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $object = $data['object'] ?? null;
    if (!$object) {
        ec2sq_log('error', 'manual', 'square_update_fetch_failed', ['variation_id' => $variation_id, 'resp' => $data], null, $ecwid['sku'], $variation_id);
        return false;
    }

    $object['item_variation_data']['name'] = $ecwid['name'];
    $object['item_variation_data']['sku'] = $ecwid['sku'];
    $object['item_variation_data']['price_money']['amount'] = intval(round(floatval($ecwid['price']) * 100));
    $object['item_variation_data']['price_money']['currency'] = 'USD';

    $update_body = [
        'idempotency_key' => uniqid('ecwid2sq_up_', true),
        'object' => $object
    ];

    $response = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($update_body),
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ec2sq_log('error', 'manual', 'square_update_http_error', ['error' => $response->get_error_message()], null, $ecwid['sku'], $variation_id);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if (!($code >= 200 && $code < 300)) {
        ec2sq_log('error', 'manual', 'square_update_failed', ['code' => $code, 'body' => wp_remote_retrieve_body($response)], null, $ecwid['sku'], $variation_id);
        return false;
    }
    return true;
}

/*
|--------------------------------------------------------------------------
| Square Inventory Helpers (v1.12 Enhanced with Force-Zero Logic)
|--------------------------------------------------------------------------
*/

/*
 * v1.12 ENHANCED: Force-zero inventory setting with robust logging and transients
 */
function ecwid_square_set_absolute_inventory($square_token, $variation_id, $location_id, $quantity, $debug = false) {
    if (empty($variation_id) || empty($location_id)) {
        ecwid_square_dbg("[Square] Inventory sync missing variation/location.", $debug, 'error');
        ec2sq_log('error', 'ecwid->square', 'inventory_set_missing_params', ['variation_id' => $variation_id, 'location_id' => $location_id]);
        return false;
    }
    if (!ecwid_square_verify_variation_exists($square_token, $variation_id, $debug)) {
        ecwid_square_dbg("[Square] Variation $variation_id not found.", $debug, 'error');
        ec2sq_log('error', 'ecwid->square', 'inventory_variation_not_found', ['variation_id' => $variation_id]);
        return false;
    }

    $desired = max(0, intval($quantity));
    $current = ecwid_square_get_square_instock_count($square_token, $variation_id, $location_id);
    if ($current !== false) $current = intval($current);

    // v1.12 change: Record last non-zero quantity when setting to zero
    if ($desired === 0 && $current !== false && $current > 0) {
        set_transient('ec2sq_last_nonzero_' . $variation_id, $current, 7 * DAY_IN_SECONDS);
    }

    // v1.12 change: No early noop for zero unless current is already 0 and verified
    if ($desired === 0) {
        ec2sq_log('info', 'ecwid->square', 'inventory_zero_requested', ['variation_id' => $variation_id, 'location' => $location_id, 'current' => $current], null, null, $variation_id, $location_id);
        
        // Only noop if current is already 0
        if ($current !== false && $current === 0) {
            ecwid_square_dbg("[Square] Inventory already 0 for variation $variation_id (verified).", $debug, 'success');
            ec2sq_log('info', 'ecwid->square', 'inventory_zero_already', ['variation_id' => $variation_id, 'location' => $location_id], null, null, $variation_id, $location_id);
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
            return true;
        }
        
        // Record zero attempt
        $zero_attempt = [
            'method' => 'force_zero',
            'before_qty' => $current,
            'at' => time()
        ];
        set_transient('ec2sq_last_zero_attempt_' . $variation_id, $zero_attempt, 7 * DAY_IN_SECONDS);
    } else {
        // Regular noop check for non-zero values
        if ($current !== false && $current === $desired) {
            ecwid_square_dbg("[Square] Inventory already $desired for variation $variation_id (noop).", $debug, 'success');
            ec2sq_log('info', 'ecwid->square', 'inventory_noop', ['variation_id' => $variation_id, 'location' => $location_id, 'qty' => $desired], null, null, $variation_id, $location_id);
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => $desired, 'at' => time()], 120);
            return true;
        }
    }

    // Local helper to post inventory changes
    $post_changes = function($changes, $tag) use ($square_token, $variation_id, $location_id, $debug) {
        $body = [
            'idempotency_key' => uniqid('ec2sq_inv_' . $tag . '_', true),
            'changes' => $changes
        ];
        $resp = wp_remote_post('https://connect.squareup.com/v2/inventory/changes/batch-create', [
            'headers' => [
                'Authorization' => 'Bearer ' . $square_token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($body),
            'timeout' => 25
        ]);
        if (is_wp_error($resp)) {
            ecwid_square_dbg("[Square][$tag] HTTP error: ".$resp->get_error_message(), $debug, 'error');
            ec2sq_log('error', 'ecwid->square', 'inventory_'.$tag.'_http_error', ['error' => $resp->get_error_message(), 'body' => $body], null, null, $variation_id, $location_id);
            return [false, 0, 'HTTP'];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $text = wp_remote_retrieve_body($resp);
        if ($code >= 200 && $code < 300) {
            ecwid_square_dbg("[Square][$tag] Applied successfully.", $debug, 'success');
            ec2sq_log('info', 'ecwid->square', 'inventory_'.$tag.'_applied', ['variation_id' => $variation_id, 'location' => $location_id], null, null, $variation_id, $location_id);
            return [true, $code, $text];
        }
        ecwid_square_dbg("[Square][$tag] Failed ($code).", $debug, 'error');
        ec2sq_log('warn', 'ecwid->square', 'inventory_'.$tag.'_failed', ['code' => $code, 'resp' => $text, 'body' => $body], null, null, $variation_id, $location_id);
        return [false, $code, $text];
    };

    // CASE A: Desired > 0 → use PHYSICAL_COUNT directly (absolute set)
    if ($desired > 0) {
        $pc_change = [
            'type' => 'PHYSICAL_COUNT',
            'physical_count' => [
                'catalog_object_id' => $variation_id,
                'location_id' => $location_id,
                'state' => 'IN_STOCK',
                'quantity' => strval($desired),
                'occurred_at' => gmdate('c'),
                'reference_id' => 'ecwid-sync-pc-' . substr($variation_id, -6)
            ]
        ];
        [$ok] = $post_changes([$pc_change], 'pc');
        if ($ok) {
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => $desired, 'at' => time()], 120);
            // Quick verification fetch
            $verify = ecwid_square_get_square_instock_count($square_token, $variation_id, $location_id);
            if ($verify !== false && intval($verify) !== $desired) {
                ec2sq_log('warn', 'ecwid->square', 'inventory_pc_verify_mismatch', ['desired' => $desired, 'fetched' => $verify], null, null, $variation_id, $location_id);
            }
            return true;
        }
        // Fallback: try again after short sleep (Square transient errors)
        sleep(1);
        [$ok2] = $post_changes([$pc_change], 'pc_retry');
        if ($ok2) {
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => $desired, 'at' => time()], 120);
            return true;
        }
        return false;
    }

    // CASE B: Desired == 0 → robust zeroing (v1.12 enhanced)
    // Strategy:
    // 1. If current > 0 => ADJUSTMENT from IN_STOCK -> NONE quantity=current
    // 2. Verify. If still >0, retry once.
    // 3. If ADJUSTMENT path fails entirely, do PHYSICAL_COUNT to 0 with state IN_STOCK as last resort.
    $current_for_zero = ($current !== false) ? $current : null;

    if ($current_for_zero === null) {
        // Couldn't fetch current reliably; attempt a PC to 0 (some Square accounts accept this).
        ecwid_square_dbg("[Square][zero] Current unknown; applying PHYSICAL_COUNT 0 fallback.", $debug, 'info');
        ec2sq_log('info', 'ecwid->square', 'inventory_zero_pc_fallback', ['variation_id' => $variation_id, 'reason' => 'unknown_current'], null, null, $variation_id, $location_id);
        $pc0 = [
            'type' => 'PHYSICAL_COUNT',
            'physical_count' => [
                'catalog_object_id' => $variation_id,
                'location_id' => $location_id,
                'state' => 'IN_STOCK',
                'quantity' => '0',
                'occurred_at' => gmdate('c'),
                'reference_id' => 'ecwid-sync-pc0-' . substr($variation_id, -6)
            ]
        ];
        [$pc_ok] = $post_changes([$pc0], 'pc_zero_unknown_current');
        if ($pc_ok) {
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
            ec2sq_log('info', 'ecwid->square', 'inventory_zero_success', ['variation_id' => $variation_id, 'location' => $location_id, 'method' => 'pc_unknown_current'], null, null, $variation_id, $location_id);
            return true;
        }
        return false;
    }

    if ($current_for_zero > 0) {
        ecwid_square_dbg("[Square][zero] Reducing $current_for_zero → 0 via ADJUSTMENT IN_STOCK→NONE.", $debug, 'info');
        ec2sq_log('info', 'ecwid->square', 'inventory_zero_adjustment_attempt', ['variation_id' => $variation_id, 'from_qty' => $current_for_zero], null, null, $variation_id, $location_id);
        $adj = [
            'type' => 'ADJUSTMENT',
            'adjustment' => [
                'catalog_object_id' => $variation_id,
                'location_id' => $location_id,
                'from_state' => 'IN_STOCK',
                'to_state' => 'NONE',
                'quantity' => strval($current_for_zero),
                'occurred_at' => gmdate('c'),
                'reference_id' => 'ecwid-sync-zero-' . substr($variation_id, -6)
            ]
        ];
        [$adj_ok] = $post_changes([$adj], 'zero_adj');
        if ($adj_ok) {
            // Verify after a brief delay (Square propagation)
            usleep(300000); // 0.3s
            $verify1 = ecwid_square_get_square_instock_count($square_token, $variation_id, $location_id);
            if ($verify1 !== false && intval($verify1) > 0) {
                ec2sq_log('warn', 'ecwid->square', 'inventory_zero_verify_still_positive', ['verify' => $verify1, 'attempt' => 1], null, null, $variation_id, $location_id);
                // Second verification with a re-fetch after slight delay
                usleep(500000);
                $verify2 = ecwid_square_get_square_instock_count($square_token, $variation_id, $location_id);
                if ($verify2 !== false && intval($verify2) > 0) {
                    ec2sq_log('warn', 'ecwid->square', 'inventory_zero_verify_still_positive', ['verify' => $verify2, 'attempt' => 2], null, null, $variation_id, $location_id);
                    // Fallback: apply PHYSICAL_COUNT 0
                    ec2sq_log('info', 'ecwid->square', 'inventory_zero_pc_fallback', ['variation_id' => $variation_id, 'reason' => 'verify_failed'], null, null, $variation_id, $location_id);
                    $pc0 = [
                        'type' => 'PHYSICAL_COUNT',
                        'physical_count' => [
                            'catalog_object_id' => $variation_id,
                            'location_id' => $location_id,
                            'state' => 'IN_STOCK',
                            'quantity' => '0',
                            'occurred_at' => gmdate('c'),
                            'reference_id' => 'ecwid-sync-pc0fb-' . substr($variation_id, -6)
                        ]
                    ];
                    [$pc_ok] = $post_changes([$pc0], 'pc_zero_fallback');
                    if ($pc_ok) {
                        set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
                        ec2sq_log('info', 'ecwid->square', 'inventory_zero_success', ['variation_id' => $variation_id, 'location' => $location_id, 'method' => 'pc_fallback'], null, null, $variation_id, $location_id);
                        return true;
                    }
                    return false;
                }
            }
            set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
            ec2sq_log('info', 'ecwid->square', 'inventory_zero_success', ['variation_id' => $variation_id, 'location' => $location_id, 'method' => 'adjustment'], null, null, $variation_id, $location_id);
            return true;
        } else {
            ecwid_square_dbg("[Square][zero] ADJUSTMENT failed, fallback PHYSICAL_COUNT 0.", $debug, 'error');
            ec2sq_log('info', 'ecwid->square', 'inventory_zero_pc_fallback', ['variation_id' => $variation_id, 'reason' => 'adjustment_failed'], null, null, $variation_id, $location_id);
            $pc0 = [
                'type' => 'PHYSICAL_COUNT',
                'physical_count' => [
                    'catalog_object_id' => $variation_id,
                    'location_id' => $location_id,
                    'state' => 'IN_STOCK',
                    'quantity' => '0',
                    'occurred_at' => gmdate('c'),
                    'reference_id' => 'ecwid-sync-pc0-alt-' . substr($variation_id, -6)
                ]
            ];
            [$pc_ok] = $post_changes([$pc0], 'pc_zero_after_adj_fail');
            if ($pc_ok) {
                set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
                ec2sq_log('info', 'ecwid->square', 'inventory_zero_success', ['variation_id' => $variation_id, 'location' => $location_id, 'method' => 'pc_after_adj_fail'], null, null, $variation_id, $location_id);
                return true;
            }
            return false;
        }
    } else {
        // This branch should not happen given our logic above, but keeping for completeness
        ecwid_square_dbg("[Square][zero] Current already 0 (treat as noop).", $debug, 'success');
        ec2sq_log('info', 'ecwid->square', 'inventory_zero_already', ['variation_id' => $variation_id, 'location' => $location_id], null, null, $variation_id, $location_id);
        set_transient('ec2sq_last_sq_set_' . $variation_id, ['qty' => 0, 'at' => time()], 120);
    }
}

function ecwid_square_get_square_instock_count($square_token, $variation_id, $location_id) {
    $count_url = "https://connect.squareup.com/v2/inventory/counts?catalog_object_ids=" . urlencode($variation_id) . "&location_ids=" . urlencode($location_id);
    $resp = wp_remote_get($count_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!isset($data['counts']) || !is_array($data['counts'])) return 0;
    foreach ($data['counts'] as $cnt) {
        if (($cnt['location_id'] ?? '') === $location_id && strtoupper($cnt['state'] ?? '') === 'IN_STOCK') {
            return intval($cnt['quantity']);
        }
    }
    return 0;
}

function ecwid_square_get_sku_for_variation($square_token, $variation_id) {
    $url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($variation_id);
    $resp = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return null;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $obj = $data['object'] ?? null;
    if (!$obj || ($obj['type'] ?? '') !== 'ITEM_VARIATION') return null;
    return ['success' => true, 'example_name' => $data['items'][0]['name']];
}

/*
|--------------------------------------------------------------------------
| Square Catalog Fetchers + Caching (Optimization)
|--------------------------------------------------------------------------
*/
function ecwid_square_validate_square_token($square_token, $location_id) {
    $url = 'https://connect.squareup.com/v2/locations';
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($response)) return ['success' => false, 'error' => 'HTTP error'];
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return ['success' => false, 'error' => "Locations API status $code"];
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['locations'])) return ['success' => false, 'error' => 'No locations in response'];

    $available = [];
    foreach ($data['locations'] as $loc) {
        $available[] = $loc['id'];
        if (($loc['id'] ?? '') === $location_id) {
            return ['success' => true, 'location_name' => $loc['name']];
        }
    }
    return ['success' => false, 'error' => 'Location ID not found. Available: ' . implode(', ', $available)];
}

function ecwid_square_get_all_ecwid_products($store_id, $token, $debug = false) {
    $products = [];
    $offset = 0; $limit = 100;
    do {
        $url = "https://app.ecwid.com/api/v3/$store_id/products?offset=$offset&limit=$limit&expand=options,combinations";
        $response = wp_remote_get($url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            ecwid_square_dbg("[Easy Farm Cart] HTTP error: ".$response->get_error_message(), $debug, 'error');
            ec2sq_log('error', 'manual', 'ecwid_products_page_fetch_failed', ['error' => $response->get_error_message(), 'offset' => $offset], null);
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['items'])) {
            ecwid_square_dbg("[Easy Farm Cart] Response missing 'items'.", $debug, 'error');
            ec2sq_log('error', 'manual', 'ecwid_products_missing_items', ['offset' => $offset, 'resp' => $data], null);
            return false;
        }
        $products = array_merge($products, $data['items']);
        $offset += $limit;
    } while ($offset < ($data['total'] ?? 0));
    return $products;
}

function ecwid_square_get_all_square_items($square_token, $debug = false) {
    $items = [];
    $cursor = null;
    do {
        $base = 'https://connect.squareup.com/v2/catalog/list';
        $params = [ 'types' => 'ITEM' ];
        if ($cursor) $params['cursor'] = $cursor;
        $url = add_query_arg($params, $base);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $square_token,
                'Accept' => 'application/json'
            ],
            'timeout' => 25
        ]);
        if (is_wp_error($response)) {
            ecwid_square_dbg("[Square] HTTP error: ".$response->get_error_message(), $debug, 'error');
            ec2sq_log('error', 'manual', 'square_list_items_http_error', ['error' => $response->get_error_message()], null);
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['objects'])) break;

        foreach ($data['objects'] as $obj) {
            if (($obj['type'] ?? '') !== 'ITEM') continue;
            $item_entry = $obj['item_data'] ?? [];
            $item_entry['id'] = $obj['id'];
            $item_entry['variations'] = $obj['item_data']['variations'] ?? [];
            $items[] = $item_entry;
        }
        $cursor = $data['cursor'] ?? null;
    } while ($cursor);

    return $items;
}

// Cached Square items and SKU map (to reduce API calls in webhooks)
function ec2sq_get_cached_square_items($square_token, $ttl = 60, $debug = false) {
    $key = 'ec2sq_sq_items_' . md5($square_token);
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;
    $items = ecwid_square_get_all_square_items($square_token, $debug);
    if ($items !== false) set_transient($key, $items, $ttl);
    return $items;
}

function ec2sq_get_square_sku_map($square_token, $use_cache = true) {
    $key = 'ec2sq_sku_map_' . md5($square_token);
    if ($use_cache) {
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
    }
    $items = $use_cache ? ec2sq_get_cached_square_items($square_token, 60, false) : ecwid_square_get_all_square_items($square_token, false);
    $map = [];
    if ($items !== false) {
        foreach ($items as $item) {
            if (!isset($item['variations'])) continue;
            foreach ($item['variations'] as $variation) {
                $s = $variation['item_variation_data']['sku'] ?? '';
                if ($s) {
                    $map[$s] = [
                        'variation_id' => $variation['id'],
                        'item_id' => $item['id']
                    ];
                }
            }
        }
    }
    if ($use_cache) set_transient($key, $map, 60);
    return $map;
}

function ecwid_square_variation_needs_update($ecwid, $square_variation) {
    $sv = $square_variation['item_variation_data'] ?? [];
    $square_price = isset($sv['price_money']['amount']) ? $sv['price_money']['amount'] / 100 : null;
    if (floatval($ecwid['price']) != floatval($square_price)) return true;
    if (isset($sv['name']) && trim($ecwid['name']) !== trim($sv['name'])) return true;
    if (isset($sv['sku']) && trim($ecwid['sku']) !== trim($sv['sku'])) return true;
    return false;
}

function ecwid_square_verify_variation_exists($square_token, $variation_id, $debug = false) {
    $url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($variation_id);
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 20
    ]);
    if (is_wp_error($response)) {
        ecwid_square_dbg("[Square] Variation verify error: ".$response->get_error_message(), $debug, 'error');
        ec2sq_log('error', 'manual', 'square_variation_verify_http_error', ['error' => $response->get_error_message(), 'variation_id' => $variation_id], null, null, $variation_id);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) return true;
    ecwid_square_dbg("[Square] Variation verify failed (code $code).", $debug, 'error');
    ec2sq_log('error', 'manual', 'square_variation_verify_failed', ['code' => $code, 'variation_id' => $variation_id, 'body' => wp_remote_retrieve_body($response)], null, null, $variation_id);
    return false;
}

// v1.12 Enhanced: Log previous tracking/presence values when changes are made
function ecwid_square_ensure_variation_location_and_tracking($square_token, $variation_id, $location_id, $debug = false) {
    $url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($variation_id) . '?include_related_objects=true';
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Accept' => 'application/json'
        ],
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ecwid_square_dbg("[Square] Presence fetch error: ".$response->get_error_message(), $debug, 'error');
        ec2sq_log('error', 'ecwid->square', 'square_presence_fetch_http_error', ['error' => $response->get_error_message(), 'variation_id' => $variation_id], null, null, $variation_id, $location_id);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !isset($data['object'])) {
        ecwid_square_dbg("[Square] Presence: no object. Code $code.", $debug, 'error');
        ec2sq_log('error', 'ecwid->square', 'square_presence_fetch_failed', ['code' => $code, 'body' => wp_remote_retrieve_body($response)], null, null, $variation_id, $location_id);
        return false;
    }

    $variation = $data['object'];
    $related = $data['related_objects'] ?? [];
    $parent_item = null;
    foreach ($related as $ro) {
        if (($ro['type'] ?? '') === 'ITEM') { $parent_item = $ro; break; }
    }

    $var_present_all = $variation['present_at_all_locations'] ?? false;
    $var_present_ids = $variation['present_at_location_ids'] ?? [];
    $var_needs_loc = !$var_present_all && !in_array($location_id, $var_present_ids, true);

    $var_track = $variation['item_variation_data']['track_inventory'] ?? false;
    $var_stockable = $variation['item_variation_data']['stockable'] ?? null;
    $var_needs_tracking = !$var_track || ($var_stockable === false);

    $item_needs_loc = false;
    if ($parent_item) {
        $item_present_all = $parent_item['present_at_all_locations'] ?? false;
        $item_present_ids = $parent_item['present_at_location_ids'] ?? [];
        $item_needs_loc = !$item_present_all && !in_array($location_id, $item_present_ids, true);
    }

    if (!$var_needs_loc && !$var_needs_tracking && !$item_needs_loc) return true;

    // v1.12 change: Log previous values before making changes
    $changes_log = [
        'variation_id' => $variation_id,
        'location_id' => $location_id,
        'previous' => [
            'var_present_all' => $var_present_all,
            'var_present_ids' => $var_present_ids,
            'var_track_inventory' => $var_track,
            'var_stockable' => $var_stockable,
        ]
    ];
    if ($parent_item) {
        $changes_log['previous']['parent_present_all'] = $parent_item['present_at_all_locations'] ?? false;
        $changes_log['previous']['parent_present_ids'] = $parent_item['present_at_location_ids'] ?? [];
    }

    if ($parent_item && $item_needs_loc) {
        $parent_item['present_at_all_locations'] = false;
        $parent_item['present_at_location_ids'] = array_values(array_unique(array_merge($parent_item['present_at_location_ids'] ?? [], [$location_id])));
        $ok = ecwid_square_upsert_catalog_object($square_token, $parent_item, $debug);
        ec2sq_log($ok ? 'info' : 'error', 'ecwid->square', 'square_parent_presence_set', ['item_id' => $parent_item['id'] ?? null, 'location' => $location_id], null, null, $variation_id, $location_id);
    }

    if ($var_needs_loc) {
        $variation['present_at_all_locations'] = false;
        $variation['present_at_location_ids'] = array_values(array_unique(array_merge($variation['present_at_location_ids'] ?? [], [$location_id])));
    }
    if ($var_needs_tracking) {
        $variation['item_variation_data']['track_inventory'] = true;
        if (array_key_exists('stockable', $variation['item_variation_data'])) $variation['item_variation_data']['stockable'] = true;
        if (array_key_exists('sellable', $variation['item_variation_data'])) $variation['item_variation_data']['sellable'] = true;
    }

    if ($var_needs_loc || $var_needs_tracking) {
        $ok = ecwid_square_upsert_catalog_object($square_token, $variation, $debug);
        // v1.12 change: Enhanced logging with previous values
        $changes_log['changes_made'] = [
            'set_location' => $var_needs_loc,
            'set_tracking' => $var_needs_tracking
        ];
        ec2sq_log($ok ? 'info' : 'error', 'ecwid->square', 'square_variation_presence_set', $changes_log, null, null, $variation_id, $location_id);
        return $ok;
    }

    return true;
}

function ecwid_square_upsert_catalog_object($square_token, $object, $debug = false) {
    $body = [
        'idempotency_key' => uniqid('ecwid2sq_up_', true),
        'object' => $object
    ];
    $response = wp_remote_post('https://connect.squareup.com/v2/catalog/object', [
        'headers' => [
            'Authorization' => 'Bearer ' . $square_token,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 25
    ]);
    if (is_wp_error($response)) {
        ec2sq_log('error', 'ecwid->square', 'square_upsert_http_error', ['error' => $response->get_error_message(), 'object_id' => $object['id'] ?? null]);
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) return true;
    $resp_body = wp_remote_retrieve_body($response);
    ec2sq_log('error', 'ecwid->square', 'square_upsert_failed', ['code' => $code, 'body' => $resp_body, 'object_id' => $object['id'] ?? null]);
    return false;
}

/*
|--------------------------------------------------------------------------
| Ecwid API Helpers (Easy Farm Cart white-label)
|--------------------------------------------------------------------------
*/
function ecwid_square_get_order($store_id, $token, $order_id) {
    $url = "https://app.ecwid.com/api/v3/$store_id/orders/" . urlencode($order_id);
    $resp = wp_remote_get($url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return false;
    return json_decode(wp_remote_retrieve_body($resp), true);
}

function ecwid_square_get_product_by_id($store_id, $token, $product_id) {
    $url = "https://app.ecwid.com/api/v3/$store_id/products/" . urlencode($product_id) . "?expand=options,combinations";
    $resp = wp_remote_get($url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return false;
    return json_decode(wp_remote_retrieve_body($resp), true);
}

function ecwid_square_find_product_by_sku($store_id, $token, $sku) {
    $url = "https://app.ecwid.com/api/v3/$store_id/products?sku=" . rawurlencode($sku) . "&expand=options,combinations&limit=1";
    $resp = wp_remote_get($url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ],
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return false;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $items = $data['items'] ?? [];
    return $items[0] ?? false;
}

function ecwid_square_update_ecwid_product_quantity($store_id, $token, $product_id, $quantity) {
    $url = "https://app.ecwid.com/api/v3/$store_id/products/" . urlencode($product_id);
    $body = json_encode(['quantity' => intval($quantity)]);
    $resp = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
        'body' => $body,
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if (!($code >= 200 && $code < 300)) {
        ec2sq_log('error', 'square->ecwid', 'ecwid_product_update_failed', ['product_id' => $product_id, 'code' => $code, 'body' => wp_remote_retrieve_body($resp)]);
        return false;
    }
    return true;
}

function ecwid_square_update_ecwid_combination_quantity($store_id, $token, $product_id, $combination_id, $quantity) {
    $url = "https://app.ecwid.com/api/v3/$store_id/products/" . urlencode($product_id) . "/combinations/" . urlencode($combination_id);
    $body = json_encode(['quantity' => intval($quantity)]);
    $resp = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
        'body' => $body,
        'timeout' => 20
    ]);
    if (is_wp_error($resp)) return false;
    $code = wp_remote_retrieve_response_code($resp);
    if (!($code >= 200 && $code < 300)) {
        ec2sq_log('error', 'square->ecwid', 'ecwid_combo_update_failed', ['product_id' => $product_id, 'combination_id' => $combination_id, 'code' => $code, 'body' => wp_remote_retrieve_body($resp)]);
        return false;
    }
    return true;
}

function ecwid_square_validate_ecwid_token($store_id, $token) {
    $url = "https://app.ecwid.com/api/v3/$store_id/products?limit=1";
    $response = wp_remote_get($url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return ['success' => false, 'error' => 'HTTP error'];
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return ['success' => false, 'error' => "API status $code"];
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['items'][0]['name'])) return ['success' => false, 'error' => 'No product data'];
    return ['success' => true, 'example_name' => $data['items'][0]['name']];
}

/*
|--------------------------------------------------------------------------
| Per-SKU Diagnostic (v1.12 Enhanced)
|--------------------------------------------------------------------------
*/
function ec2sq_run_sku_diagnostic($sku) {
    $store_id = get_option('ecwid_store_id');
    $ecwid_token = get_option('ecwid_secret_token');
    $square_token = get_option('square_access_token');
    $square_location_id = get_option('square_location_id');

    if (!$sku) return ['error' => 'No SKU entered'];
    if (!$store_id || !$ecwid_token || !$square_token || !$square_location_id) {
        return ['error' => 'Missing configuration (Easy Farm Cart token/store or Square token/location)'];
    }

    $ecwid_product = ecwid_square_find_product_by_sku($store_id, $ecwid_token, $sku);
    $ecwid_qty = null; $ecwid_type = 'unknown'; $ecwid_ids = [];
    if ($ecwid_product) {
        if (!empty($ecwid_product['combinations'])) {
            foreach ($ecwid_product['combinations'] as $combo) {
                if (($combo['sku'] ?? '') === $sku) {
                    $ecwid_qty = array_key_exists('quantity', $combo) ? $combo['quantity'] : null;
                    $ecwid_type = 'combination';
                    $ecwid_ids = ['product_id' => $ecwid_product['id'], 'combination_id' => $combo['id'] ?? null];
                    break;
                }
            }
        } else {
            if (($ecwid_product['sku'] ?? '') === $sku) {
                $ecwid_qty = array_key_exists('quantity', $ecwid_product) ? $ecwid_product['quantity'] : null;
                $ecwid_type = 'simple';
                $ecwid_ids = ['product_id' => $ecwid_product['id']];
            }
        }
    }

    // v1.12 change: Get detailed Square variation info including tracking flags
    $variation_id = null;
    $square_variation_data = null;
    $square_parent_item_data = null;
    $list = ec2sq_get_cached_square_items($square_token, 60, false);
    if ($list !== false) {
        foreach ($list as $item) {
            $vars = $item['variations'] ?? [];
            foreach ($vars as $v) {
                if (($v['item_variation_data']['sku'] ?? '') === $sku) {
                    $variation_id = $v['id'];
                    $square_variation_data = $v;
                    $square_parent_item_data = $item;
                    break 2;
                }
            }
        }
    }

    // v1.12 change: Get detailed Square presence and tracking info
    $square_detailed = null;
    if ($variation_id) {
        $url = 'https://connect.squareup.com/v2/catalog/object/' . urlencode($variation_id) . '?include_related_objects=true';
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $square_token,
                'Accept' => 'application/json'
            ],
            'timeout' => 25
        ]);
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['object'])) {
                $square_detailed = $data['object'];
                $related = $data['related_objects'] ?? [];
                foreach ($related as $ro) {
                    if (($ro['type'] ?? '') === 'ITEM') {
                        $square_parent_item_data = $ro;
                        break;
                    }
                }
            }
        }
    }

    $square_qty = $variation_id ? ecwid_square_get_square_instock_count($square_token, $variation_id, $square_location_id) : null;

    // v1.12 change: Get transient metadata
    $last_sq_set = $variation_id ? get_transient('ec2sq_last_sq_set_' . $variation_id) : null;
    $last_ec_set = get_transient('ec2sq_last_ec_set_' . $sku);
    $last_nonzero = $variation_id ? get_transient('ec2sq_last_nonzero_' . $variation_id) : null;
    $last_zero_attempt = $variation_id ? get_transient('ec2sq_last_zero_attempt_' . $variation_id) : null;

    // v1.12 change: Calculate will_force_zero_if_set_now
    $will_force_zero = false;
    if ($variation_id && $ecwid_qty === 0 && $square_qty !== false && $square_qty !== 0) {
        $will_force_zero = true;
    }

    $result = [
        'sku' => $sku,
        'easy_farm_cart' => [
            'found' => !!$ecwid_product,
            'type' => $ecwid_type,
            'quantity' => $ecwid_qty,
            'ids' => $ecwid_ids,
            'name' => $ecwid_product['name'] ?? null
        ],
        'square' => [
            'variation_id' => $variation_id,
            'quantity_at_location' => $square_qty,
            'location_id' => $square_location_id
        ]
    ];

    // v1.12 change: Add Square tracking flags if detailed data available
    if ($square_detailed) {
        $var_data = $square_detailed['item_variation_data'] ?? [];
        $result['square']['tracking_flags'] = [
            'track_inventory' => $var_data['track_inventory'] ?? false,
            'stockable' => $var_data['stockable'] ?? null,
            'sellable' => $var_data['sellable'] ?? null
        ];

        // v1.12 change: Add presence flags
        $result['square']['presence'] = [
            'present_at_all_locations' => $square_detailed['present_at_all_locations'] ?? false,
            'present_at_location_ids' => $square_detailed['present_at_location_ids'] ?? [],
            'present_at_target_location' => 
                ($square_detailed['present_at_all_locations'] ?? false) || 
                in_array($square_location_id, $square_detailed['present_at_location_ids'] ?? [], true)
        ];

        // v1.12 change: Add parent item presence
        if ($square_parent_item_data) {
            $result['square']['parent_item_presence'] = [
                'parent_item_present_at_target_location' => 
                    ($square_parent_item_data['present_at_all_locations'] ?? false) || 
                    in_array($square_location_id, $square_parent_item_data['present_at_location_ids'] ?? [], true),
                'parent_present_at_all_locations' => $square_parent_item_data['present_at_all_locations'] ?? false,
                'parent_present_at_location_ids' => $square_parent_item_data['present_at_location_ids'] ?? []
            ];
        }
    }

    // v1.12 change: Add transient metadata
    $result['metadata'] = [
        'last_sq_set' => $last_sq_set,
        'last_ec_set' => $last_ec_set,
        'last_nonzero_before_zero' => $last_nonzero,
        'last_zero_attempt' => $last_zero_attempt,
        'will_force_zero_if_set_now' => $will_force_zero
    ];

    $result['notes'] = [
        'sync_logic' => 'Easy Farm Cart (title/description/price) → Square on product.updated; inventory sync both ways (latest event wins).'
    ];

    return $result;
}

/*
|--------------------------------------------------------------------------
| Debug (streaming echo) Helper
|--------------------------------------------------------------------------
*/
function ecwid_square_dbg($msg, $debug, $type = 'info') {
    if ($debug) {
        $color = [
            'info' => '#1e293b',
            'error' => '#b91c1c',
            'success' => '#065f46'
        ][$type] ?? '#1e293b';
        echo "<div style='color:$color'>".esc_html($msg)."</div>";
        @ob_flush();
        @flush();
    }
}
