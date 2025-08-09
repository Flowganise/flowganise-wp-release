<?php
/**
 * Debugging helper for Flowganise plugin
 */

defined('ABSPATH') || exit;

class Flowganise_Debug {
    public function __construct() {
        add_action('wp_ajax_flowganise_debug', array($this, 'handle_debug_request'));
        add_action('admin_footer', array($this, 'maybe_add_debug_button'));
    }
    
    public function maybe_add_debug_button() {
        $screen = get_current_screen();
        
        if (!current_user_can('manage_options') || 
            !$screen || $screen->id !== 'settings_page_flowganise-settings') {
            return;
        }
        
        ?>
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ccc;">
            <h3><?php esc_html_e('Troubleshooting Tools', 'flowganise-analytics'); ?></h3>
            <p>
                <button type="button" class="button" id="flowganise-debug">
                    <?php esc_html_e('Run Diagnostics', 'flowganise-analytics'); ?>
                </button>
            </p>
            <div id="flowganise-debug-output"></div>
        </div>
        <?php
    }
    
    public function handle_debug_request() {
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
            
            // Gather diagnostic information
            $diagnostics = array(
                'plugin_version' => FLOWGANISE_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'is_multisite' => is_multisite(),
                'active_plugins' => $this->get_active_plugins(),
                'theme' => $this->get_theme_info(),
                'settings' => $this->get_plugin_settings(),
                'caching' => $this->detect_caching_plugins(),
                'server_info' => $this->get_server_info(),
                'date' => current_time('mysql'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            );
            
            // Clear plugin caches
            delete_transient('flowganise_api_response');
            delete_transient('flowganise_github_api_response');
            
            wp_send_json_success($diagnostics);
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
        
        die();
    }
    
    private function get_active_plugins() {
        $active_plugins = get_option('active_plugins');
        $plugins_data = array();
        
        foreach ($active_plugins as $plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $plugins_data[$plugin] = array(
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'author' => $plugin_data['Author']
            );
        }
        
        return $plugins_data;
    }
    
    private function get_theme_info() {
        $theme = wp_get_theme();
        return array(
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'author' => $theme->get('Author')
        );
    }
    
    private function get_plugin_settings() {
        $settings = get_option('flowganise_settings', array());
        
        // Mask sensitive data
        if (isset($settings['site_id'])) {
            $site_id = $settings['site_id'];
            $settings['site_id'] = substr($site_id, 0, 4) . '...' . substr($site_id, -4);
        }
        
        return $settings;
    }
    
    private function detect_caching_plugins() {
        $caching_info = array();
        
        // WP Super Cache
        $caching_info['wp_super_cache'] = is_plugin_active('wp-super-cache/wp-cache.php');
        
        // W3 Total Cache
        $caching_info['w3_total_cache'] = is_plugin_active('w3-total-cache/w3-total-cache.php');
        
        // LiteSpeed Cache
        $caching_info['litespeed_cache'] = is_plugin_active('litespeed-cache/litespeed-cache.php');
        
        // WP Rocket
        $caching_info['wp_rocket'] = is_plugin_active('wp-rocket/wp-rocket.php');
        
        // WP Fastest Cache
        $caching_info['wp_fastest_cache'] = is_plugin_active('wp-fastest-cache/wpFastestCache.php');
        
        // Autoptimize
        $caching_info['autoptimize'] = is_plugin_active('autoptimize/autoptimize.php');
        
        // Cloudflare
        $caching_info['cloudflare'] = is_plugin_active('cloudflare/cloudflare.php');
        
        // Breeze
        $caching_info['breeze'] = is_plugin_active('breeze/breeze.php');
        
        // CDN Enabler
        $caching_info['cdn_enabler'] = is_plugin_active('cdn-enabler/cdn-enabler.php');
        
        // Swift Performance
        $caching_info['swift_performance'] = is_plugin_active('swift-performance-lite/performance.php') || 
                                           is_plugin_active('swift-performance/performance.php');
        
        // SG Optimizer
        $caching_info['sg_optimizer'] = is_plugin_active('sg-cachepress/sg-cachepress.php');
        
        // Check for server-level caching
        $caching_info['opcache_enabled'] = function_exists('opcache_get_status') && @opcache_get_status() !== false;
        
        // Detect Nginx FastCGI cache
        $caching_info['nginx_fastcgi'] = isset($_SERVER['SERVER_SOFTWARE']) && 
                                       stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
        
        return $caching_info;
    }
    
    private function get_server_info() {
        return array(
            'software' => $_SERVER['SERVER_SOFTWARE'],
            'sapi' => php_sapi_name(),
            'ssl' => is_ssl(),
        );
    }
}

// Initialize the debug helper if we're in the admin area
if (is_admin()) {
    new Flowganise_Debug();
}
