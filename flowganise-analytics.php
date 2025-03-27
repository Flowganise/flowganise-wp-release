<?php
/**
 * Plugin Name: Flowganise Analytics
 * Plugin URI: https://flowganise.com
 * Description: Integrates Flowganise analytics tracking with WordPress.
 * Version: 1.1.0
 * Author: Flowganise
 * Author URI: https://www.flowganise.com
 * Text Domain: flowganise-analytics
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

define('FLOWGANISE_VERSION', '1.1.0');

class Flowganise_Analytics {
    private static $instance = null;
    private $api_base;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
        $this->api_base = $is_local_dev ? 'http://localhost:4000/api' : 'https://backend.flowganise.com/api';

        // Load required files
        require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-updater.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-cache-manager.php';
        
        if (is_admin()) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-flowganise-debug.php';
        }

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_head', array($this, 'add_tracking_code'));
        add_action('wp_ajax_flowganise_connect', array($this, 'handle_connect_request'));
        add_action('wp_ajax_flowganise_disconnect', array($this, 'handle_disconnect_request'));

        // Add AJAX handler for saving settings directly
        add_action('wp_ajax_flowganise_save_settings', array($this, 'handle_save_settings_request'));

        // WooCommerce integration
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_thankyou', array($this, 'track_transaction'));
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
            $flowganise_url = $is_local_dev ? 'http://localhost:3000' : 'https://app.flowganise.com';

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
            $flowganise_url = $is_local_dev ? 'http://localhost:3000' : 'https://app.flowganise.com';

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
        $is_connected = !empty($settings['organization_id']);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Flowganise Analytics Settings', 'flowganise-analytics'); ?></h1>
            
            <?php if ($is_connected): ?>
                <div class="notice notice-success">
                    <p>
                        <?php 
                        printf(
                            esc_html__('Connected to Flowganise (Organization ID: %s)', 'flowganise-analytics'),
                            esc_html($settings['organization_id'])
                        ); 
                        ?>
                    </p>
                </div>
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

    public function handle_connect_request() {
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

            // Get full site URL
            $site_url = get_site_url();
            
            // Call Flowganise API
            $api_url = flowganiseAdmin.flowganiseUrl . '/api/wordpress/connect';
            $response = wp_remote_get(add_query_arg(
                array('domain' => $site_url),
                $api_url
            ), array(
                'timeout' => 15
            ));

            if (is_wp_error($response)) {
                wp_send_json_error('Failed to connect to Flowganise: ' . $response->get_error_message());
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status = wp_remote_retrieve_response_code($response);

            if (!$body['success']) {
                // Enhance error message to be more helpful
                wp_send_json_error('This domain is not registered with Flowganise. Please make sure you\'ve entered the correct website URL in your Flowganise dashboard.');
                return;
            }

            if (empty($body['organization_id'])) {
                wp_send_json_error('Invalid response from Flowganise');
                return;
            }

            // Save the settings
            update_option('flowganise_settings', array(
                'organization_id' => $body['organization_id'],
                'connected_at' => current_time('mysql'),
                'domain' => $site_url
            ));

            // Return success with callback URL to complete the connection
            $callback_url = flowganiseAdmin.flowganiseUrl . '/oauth/wordpress?' . 
                            'teamId=' . urlencode($body['organization_id']) . 
                            '&siteUrl=' . urlencode($site_url);
            
            wp_send_json_success(array(
                'message' => 'Successfully connected with Flowganise',
                'organization_id' => $body['organization_id'],
                'callback_url' => $callback_url
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle saving settings directly (called when using the direct API)
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

            // Get the organization ID from the request
            $organization_id = isset($_POST['organization_id']) ? sanitize_text_field($_POST['organization_id']) : '';
            $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : get_site_url();

            if (empty($organization_id)) {
                wp_send_json_error('Missing organization ID');
                return;
            }

            // Save the settings
            update_option('flowganise_settings', array(
                'organization_id' => $organization_id,
                'connected_at' => current_time('mysql'),
                'domain' => $domain
            ));

            wp_send_json_success(array(
                'message' => 'Successfully connected with Flowganise',
                'organization_id' => $organization_id
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function add_tracking_code() {
        $settings = get_option('flowganise_settings', array());
        $organization_id = isset($settings['organization_id']) ? $settings['organization_id'] : '';
    
        if (empty($organization_id)) {
            return;
        }
    
        // Check for local development environment
        $is_local_dev = defined('WP_LOCAL_DEV') && WP_LOCAL_DEV === true;
        
        // Add cache busting parameter for the script
        $script_base = $is_local_dev ? 'http://localhost:5173/index.min.js' : 'https://script.flowganise.com/';
        $cache_buster = '?v=' . FLOWGANISE_VERSION . '-' . substr(md5($organization_id), 0, 8);
        $script_url = $script_base . $cache_buster;
        ?>
        <script async src="<?php echo esc_url($script_url); ?>"></script>
        <script>
            window.flowganise = window.flowganise || [];
            function fgan(){flowganise.push(arguments);}
            fgan('js', new Date());
            fgan('config', <?php echo json_encode($organization_id); ?>);
            <?php if ($is_local_dev || $this->is_debug_mode()): ?>
            console.log('Flowganise: Version <?php echo esc_js(FLOWGANISE_VERSION); ?>');
            <?php endif; ?>
        </script>
        <?php
    }
    
    private function is_debug_mode() {
        return (defined('FLOWGANISE_DEBUG') && FLOWGANISE_DEBUG) || 
               (isset($_GET['flowganise_debug']) && current_user_can('manage_options'));
    }

    public function track_transaction($order_id) {
        $settings = get_option('flowganise_settings', array());
        if (empty($settings['organization_id'])) {
            return;
        }

        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if this transaction was already tracked
        if ($order->get_meta('_flowganise_tracked')) {
            return;
        }

        // Track transaction
        ?>
        <script>
            fgan('transaction', 
                <?php echo json_encode($order->get_currency()); ?>,
                <?php echo (float) $order->get_total(); ?>,
                'transaction',
                {
                    order_id: <?php echo json_encode($order->get_order_number()); ?>,
                    payment_method: <?php echo json_encode($order->get_payment_method_title()); ?>,
                    coupon: <?php echo json_encode(implode(', ', $order->get_coupon_codes())); ?>,
                    shipping: <?php echo json_encode($order->get_shipping_method()); ?>
                }
            );
        </script>
        <?php

        // Mark order as tracked
        $order->update_meta_data('_flowganise_tracked', true);
        $order->save();
    }
}

// Initialize plugin
function flowganise_init() {
    // Wait for init hook to ensure proper loading order
    Flowganise_Analytics::instance(); 
}
add_action('init', 'flowganise_init', 5);