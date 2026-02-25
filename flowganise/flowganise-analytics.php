<?php
/**
 * Plugin Name: Flowganise Analytics
 * Plugin URI: https://flowganise.com
 * Description: Integrates Flowganise analytics tracking with WordPress.
 * Version: 3.0.1
 * Author: Flowganise
 * Author URI: https://www.flowganise.com
 * Text Domain: flowganise-analytics
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

define('FLOWGANISE_VERSION', '3.0.1');

class Flowganise_Analytics {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Load required files
        require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-updater.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-cache-manager.php';
        
        if (is_admin()) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-debug.php';
        }

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_head', array($this, 'add_tracking_code'));
        add_action('wp_ajax_flowganise_disconnect', array($this, 'handle_disconnect_request'));
        add_action('wp_ajax_flowganise_save_settings', array($this, 'handle_save_settings_request'));

        // WooCommerce integration - server-side purchase tracking only
        if (class_exists('WooCommerce')) {
            // Use woocommerce_checkout_order_processed instead of woocommerce_payment_complete
            // This fires for ALL payment methods including COD, immediately after checkout
            add_action('woocommerce_checkout_order_processed', array($this, 'track_purchase_server_side'), 10, 1);
            // Session sync endpoint for server-side tracking
            add_action('wp_ajax_flowganise_sync_session', array($this, 'sync_session'));
            add_action('wp_ajax_nopriv_flowganise_sync_session', array($this, 'sync_session'));
            // Enqueue session sync script
            add_action('wp_enqueue_scripts', array($this, 'enqueue_sync_script'));
        }

        // Initialize the updater
        new Flowganise_Updater(__FILE__, FLOWGANISE_VERSION);
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Add site URL to admin script variables
        add_action('admin_enqueue_scripts', function($hook) {
            if ('settings_page_flowganise-settings' !== $hook) {
                return;
            }

            wp_enqueue_script(
                'flowganise-admin',
                plugins_url('js/admin.js', __FILE__),
                array('jquery'),
                FLOWGANISE_VERSION,
                true
            );

            // Determine the frontend URL based on environment
            $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
            $flowganise_url = $is_local_dev ? 'http://localhost:3000' : 'https://flowganise.com';

            // Override with constant if defined
            if (defined('FLOWGANISE_APP_URL')) {
                $flowganise_url = FLOWGANISE_APP_URL;
            }

            wp_localize_script('flowganise-admin', 'flowganiseAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('flowganise_connect'),
                'version' => FLOWGANISE_VERSION,
                'siteUrl' => get_site_url(),
                'flowganiseUrl' => $flowganise_url // Make sure this is properly set
            ));
        });
    }
    
    public function activate() {
        // Store current version for upgrade detection
        $stored_version = get_option('flowganise_version');
        
        if ($stored_version !== FLOWGANISE_VERSION) {
            update_option('flowganise_version', FLOWGANISE_VERSION);
            
            // Clear any caches
            $this->clear_caches();
            
            // Run any version-specific upgrade routines
            if (!empty($stored_version)) {
                $this->maybe_upgrade($stored_version, FLOWGANISE_VERSION);
            }
        }
    }
    
    public function deactivate() {
        // Clear any caches on deactivation
        $this->clear_caches();
    }
    
    private function clear_caches() {
        // Use our centralized cache manager
        Flowganise_Cache_Manager::clear_all_caches();
    }
    
    private function maybe_upgrade($old_version, $new_version) {
        // Handle version-specific upgrades if needed
        // This can be expanded as the plugin evolves
        error_log("Flowganise upgrade from {$old_version} to {$new_version}");
    }

    public function add_menu() {
        add_options_page(
            __('Flowganise Analytics', 'flowganise-analytics'),
            __('Flowganise', 'flowganise-analytics'),
            'manage_options',
            'flowganise-settings',
            array($this, 'settings_page')
        );
   
        // Add admin scripts only on our settings page
        add_action('admin_enqueue_scripts', function($hook) {
            if ('settings_page_flowganise-settings' !== $hook) {
                return;
            }
   
            wp_enqueue_script(
                'flowganise-admin',
                plugins_url('js/admin.js', __FILE__),
                array('jquery'),
                FLOWGANISE_VERSION,
                true
            );

            // Determine the frontend URL based on environment
            $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
            $flowganise_url = $is_local_dev ? 'http://localhost:3000' : 'https://flowganise.com';

            // Override with constant if defined
            if (defined('FLOWGANISE_APP_URL')) {
                $flowganise_url = FLOWGANISE_APP_URL;
            }

            wp_localize_script('flowganise-admin', 'flowganiseAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('flowganise_connect'),
                'version' => FLOWGANISE_VERSION,
                'siteUrl' => get_site_url(),
                'flowganiseUrl' => $flowganise_url // Make sure flowganiseUrl is properly set
            ));
        });
    }

    public function settings_page() {
        $settings = get_option('flowganise_settings', array());
        $is_connected = !empty($settings['site_id']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Flowganise Analytics Settings', 'flowganise-analytics'); ?></h1>
            
            <?php if ($is_connected): ?>
                <div class="notice notice-success">
                    <p>
                        <?php
                        printf(
                            esc_html__('Connected to Flowganise (Site ID: %s)', 'flowganise-analytics'),
                            esc_html($settings['site_id'])
                        );
                        ?>
                    </p>
                </div>

                <?php if (!empty($settings['api_key'])): ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('API Key', 'flowganise-analytics'); ?></th>
                        <td>
                            <code style="font-size: 12px; word-break: break-all;"><?php echo esc_html($settings['api_key']); ?></code>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>

                <p>
                    <button type="button" class="button button-secondary" id="flowganise-disconnect">
                        <?php esc_html_e('Disconnect', 'flowganise-analytics'); ?>
                    </button>
                </p>
            <?php else: ?>
                <p><?php esc_html_e('Connect your WordPress site with Flowganise to start tracking analytics.', 'flowganise-analytics'); ?></p>
                <div id="flowganise-connect-status"></div>
                <p>
                    <button type="button" class="button button-primary" id="flowganise-connect">
                        <?php esc_html_e('Connect with Flowganise', 'flowganise-analytics'); ?>
                    </button>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_disconnect_request() {
        try {
            // Verify nonce
            if (!check_ajax_referer('flowganise_connect', false, false)) {
                wp_send_json_error('Invalid nonce');
                return;
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            // Delete the settings
            $deleted = delete_option('flowganise_settings');
            
            if ($deleted) {
                wp_send_json_success(array(
                    'message' => 'Successfully disconnected from Flowganise'
                ));
            } else {
                wp_send_json_error('Failed to delete settings');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
        
        die(); // Make sure we stop execution
    }

    /**
     * Handle saving settings directly (called after OAuth redirect returns)
     */
    public function handle_save_settings_request() {
        try {
            // Verify nonce
            if (!check_ajax_referer('flowganise_connect', false, false)) {
                wp_send_json_error('Invalid nonce');
                return;
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
                return;
            }

            // Get the site ID from the request
            $site_id = isset($_POST['site_id']) ? sanitize_text_field($_POST['site_id']) : '';
            $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : get_site_url();

            if (empty($site_id)) {
                wp_send_json_error('Missing site ID');
                return;
            }

            // Get API key if provided (from OAuth callback)
            $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

            // Debug log
            error_log('[Flowganise] save_settings - site_id: ' . $site_id . ', api_key: ' . $api_key . ', domain: ' . $domain);

            // Save the settings
            update_option('flowganise_settings', array(
                'site_id' => $site_id,
                'api_key' => $api_key,
                'connected_at' => current_time('mysql'),
                'domain' => $domain
            ));

            wp_send_json_success(array(
                'message' => 'Successfully connected with Flowganise',
                'site_id' => $site_id
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function add_tracking_code() {
        $settings = get_option('flowganise_settings', array());
        $site_id = isset($settings['site_id']) ? $settings['site_id'] : '';
    
        if (empty($site_id)) {
            return;
        }
    
        // Check for local development environment
        $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
        
        // Use the new simplified script injection format
        // Add date-based version for cache busting (refreshes daily)
        $version_date = gmdate('Ymd');
        if ($is_local_dev) {
            $script_url = 'http://localhost:5173/index.min.js?site-id=' . urlencode($site_id) . '&v=' . $version_date;
        } else {
            $script_url = 'https://tracker.flowganise.com/?site-id=' . urlencode($site_id) . '&v=' . $version_date;
        }
        ?>
        <script src="<?php echo esc_url($script_url); ?>" async></script>
        <?php
        if ($is_local_dev || $this->is_debug_mode()): ?>
        <script>
            console.log('Flowganise: Version <?php echo esc_js(FLOWGANISE_VERSION); ?>');
        </script>
        <?php endif;
    }
    
    private function is_debug_mode() {
        return (defined('FLOWGANISE_DEBUG') && FLOWGANISE_DEBUG) ||
               (isset($_GET['flowganise_debug']) && current_user_can('manage_options'));
    }

    /**
     * Sync session IDs from localStorage to WooCommerce session.
     * Called via AJAX from the frontend to enable server-side purchase tracking.
     */
    public function sync_session() {
        // Verify nonce
        if (!check_ajax_referer('flowganise_sync', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $visitor_id = isset($_POST['visitor_id']) ? sanitize_text_field($_POST['visitor_id']) : '';

        if ($session_id && $visitor_id) {
            // Store synced data in a transient keyed by visitor ID.
            // WC sessions are unreliable (temporary session IDs change per request),
            // so we use transients which persist in the database regardless of session state.
            $transient_key = 'flowganise_' . sanitize_key($visitor_id);
            $sync_data = array(
                'session_id'      => $session_id,
                'visitor_id'      => $visitor_id,
                'viewport_width'  => isset($_POST['viewport_width']) ? intval($_POST['viewport_width']) : 0,
                'viewport_height' => isset($_POST['viewport_height']) ? intval($_POST['viewport_height']) : 0,
                'screen_width'    => isset($_POST['screen_width']) ? intval($_POST['screen_width']) : 0,
                'screen_height'   => isset($_POST['screen_height']) ? intval($_POST['screen_height']) : 0,
                'user_agent'      => isset($_POST['user_agent']) ? sanitize_text_field($_POST['user_agent']) : '',
                'language'        => isset($_POST['language']) ? sanitize_text_field($_POST['language']) : 'en-US',
            );

            // Store for 1 hour (more than enough for a checkout flow)
            set_transient($transient_key, $sync_data, HOUR_IN_SECONDS);

            wp_send_json_success();
        } else {
            wp_send_json_error('Missing session or visitor ID');
        }
    }

    /**
     * Enqueue the session sync script for WooCommerce sites.
     * This syncs localStorage sessionId/visitorId and device info to the server via transients.
     */
    public function enqueue_sync_script() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $settings = get_option('flowganise_settings', array());
        if (empty($settings['site_id'])) {
            return;
        }

        wp_enqueue_script(
            'flowganise-sync',
            plugins_url('js/sync-session.js', __FILE__),
            array(),
            FLOWGANISE_VERSION,
            true
        );

        wp_localize_script('flowganise-sync', 'flowganiseSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('flowganise_sync')
        ));
    }

    /**
     * Server-side purchase tracking via woocommerce_checkout_order_processed hook.
     * Fires for ALL payment methods (including COD) immediately after checkout.
     * Sends purchase event directly to Flowganise API (like Shopify Web Pixel).
     *
     * @param int $order_id The WooCommerce order ID.
     */
    public function track_purchase_server_side($order_id) {
        $settings = get_option('flowganise_settings', array());
        if (empty($settings['site_id'])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if already tracked (prevents duplicate tracking)
        if ($order->get_meta('_flowganise_tracked')) {
            return;
        }

        // Mark as tracked immediately to prevent race conditions from concurrent requests.
        // This is set before the API call to ensure only one request proceeds.
        $order->update_meta_data('_flowganise_tracked', true);
        $order->save();

        // Get synced session/device data from transient (stored by sync AJAX handler).
        // The visitor ID cookie is set by the sync JS and used as the transient lookup key.
        $session_id = null;
        $visitor_id = null;
        $device_info = array();

        $cookie_visitor_id = isset($_COOKIE['flowganise_visitor_id']) ? sanitize_text_field($_COOKIE['flowganise_visitor_id']) : '';

        if ($cookie_visitor_id) {
            $transient_key = 'flowganise_' . sanitize_key($cookie_visitor_id);
            $sync_data = get_transient($transient_key);

            if ($sync_data && is_array($sync_data)) {
                $session_id = $sync_data['session_id'];
                $visitor_id = $sync_data['visitor_id'];
                $device_info = array(
                    'viewport_width'  => $sync_data['viewport_width'] ?: 0,
                    'viewport_height' => $sync_data['viewport_height'] ?: 0,
                    'screen_width'    => $sync_data['screen_width'] ?: 0,
                    'screen_height'   => $sync_data['screen_height'] ?: 0,
                    'user_agent'      => $sync_data['user_agent'] ?: '',
                    'language'        => $sync_data['language'] ?: 'en-US',
                );

                // Clean up the transient after use
                delete_transient($transient_key);
            }
        }

        // Fallback: generate UUIDs if sync didn't happen
        if (!$session_id) {
            $session_id = wp_generate_uuid4();
        }
        if (!$visitor_id) {
            $visitor_id = $cookie_visitor_id ?: wp_generate_uuid4();
        }

        // Build event payload (matches Shopify Web Pixel format)
        $event_payload = array(
            'event_name' => 'purchase',
            'event_id' => wp_generate_uuid4(),
            'timestamp' => gmdate('c'),
            'session_sequence' => 1,
            'properties' => array_merge(
                array(
                    'event_identifier' => 'purchase:all',
                    'hostname' => wp_parse_url(home_url(), PHP_URL_HOST),
                    'title' => 'Transaction Complete',
                    'pathname' => '/checkout/order-received/',
                    'order_id' => $order->get_order_number(),
                    'currency' => $order->get_currency(),
                    'value' => (float) $order->get_total(),
                    'items' => $this->get_order_items($order),
                ),
                $device_info // Include device info (matches Shopify Web Pixel)
            ),
            'site_id' => $settings['site_id'],
            'session_id' => $session_id,
            'visitor_id' => $visitor_id,
            'url' => $order->get_checkout_order_received_url()
        );

        // Send to Flowganise API
        $api_key = $this->get_events_api_key();
        if (empty($api_key)) {
            return;
        }

        // Determine API URL based on environment (same pattern as other URLs)
        // For local dev, use host.docker.internal since PHP runs inside Docker container
        // (unlike the tracker script which runs in the browser on the host machine)
        $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
        $api_base_url = $is_local_dev ? 'http://host.docker.internal:4000' : 'https://api.flowganise.com';
        $api_url = $api_base_url . '/api/events?key=' . $api_key;

        // Build headers - include browser user agent if available (for device detection)
        $headers = array('Content-Type' => 'application/json');
        $browser_user_agent = isset($device_info['user_agent']) ? $device_info['user_agent'] : '';
        if (!empty($browser_user_agent)) {
            // Send browser UA as custom header AND override User-Agent so backend parses it correctly
            $headers['User-Agent'] = $browser_user_agent;
        }

        $response = wp_remote_post($api_url, array(
            'timeout' => 10,
            'headers' => $headers,
            'body' => wp_json_encode($event_payload)
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 202) {
            if ($this->is_debug_mode()) {
                error_log('Flowganise: Purchase tracked server-side for order ' . $order->get_order_number());
            }
        } else {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            error_log('Flowganise: Failed to track purchase server-side for order ' . $order->get_order_number() . ' - ' . $error_msg);
        }
    }

    /**
     * Get order items in Flowganise format.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return array Array of item data.
     */
    private function get_order_items($order) {
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'id' => $product ? $product->get_id() : null,
                'title' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : null,
                'quantity' => $item->get_quantity(),
                'price' => (float) $order->get_item_total($item, false),
                'currency' => $order->get_currency()
            );
        }
        return $items;
    }

    /**
     * Get the Events API key.
     *
     * 1. Site-specific key from OAuth connection (preferred)
     * 2. Constant override for local development
     *
     * @return string|null The API key, or null if not configured.
     */
    private function get_events_api_key() {
        // Priority 1: Site-specific key from OAuth connection
        $settings = get_option('flowganise_settings', array());
        if (!empty($settings['api_key'])) {
            return $settings['api_key'];
        }

        // Priority 2: Constant override (for local dev)
        if (defined('FLOWGANISE_EVENTS_API_KEY')) {
            return FLOWGANISE_EVENTS_API_KEY;
        }

        error_log('[Flowganise] No API key configured. Connect your site via the Flowganise dashboard.');
        return null;
    }

}

// Initialize plugin
function flowganise_init() {
    // Wait for init hook to ensure proper loading order
    Flowganise_Analytics::instance(); 
}
add_action('init', 'flowganise_init', 5);