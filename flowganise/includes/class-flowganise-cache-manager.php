<?php
/**
 * Cache management utilities for Flowganise
 */

defined('ABSPATH') || exit;

class Flowganise_Cache_Manager {
    /**
     * Clear all known WordPress caches
     */
    public static function clear_all_caches() {
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear plugin transients
        delete_transient('flowganise_api_response');
        delete_transient('flowganise_github_api_response');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Clear various caching plugins
        self::clear_wp_super_cache();
        self::clear_w3tc_cache();
        self::clear_wp_rocket_cache();
        self::clear_litespeed_cache();
        self::clear_breeze_cache();
        self::clear_cdn_enabler_cache();
        self::clear_swift_performance_cache();
        self::clear_sg_optimizer_cache();
        self::clear_wp_fastest_cache();
        self::clear_autoptimize_cache();
        self::clear_nginx_helper_cache();
    }
    
    /**
     * Clear WP Super Cache
     */
    public static function clear_wp_super_cache() {
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
    }
    
    /**
     * Clear W3 Total Cache
     */
    public static function clear_w3tc_cache() {
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
    }
    
    /**
     * Clear WP Rocket cache
     */
    public static function clear_wp_rocket_cache() {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    }
    
    /**
     * Clear LiteSpeed Cache
     */
    public static function clear_litespeed_cache() {
        if (class_exists('Litespeed_Cache_API') && method_exists('Litespeed_Cache_API', 'purge_all')) {
            Litespeed_Cache_API::purge_all();
        }
    }
    
    /**
     * Clear Breeze cache
     */
    public static function clear_breeze_cache() {
        if (class_exists('Breeze_Admin') && method_exists('Breeze_PurgeCache', 'breeze_cache_flush')) {
            Breeze_PurgeCache::breeze_cache_flush();
        }
    }
    
    /**
     * Clear CDN Enabler cache
     */
    public static function clear_cdn_enabler_cache() {
        if (class_exists('CDN_Enabler') && method_exists('CDN_Enabler', 'clear_cache')) {
            CDN_Enabler::clear_cache();
        }
    }
    
    /**
     * Clear Swift Performance cache
     */
    public static function clear_swift_performance_cache() {
        if (class_exists('Swift_Performance_Cache') && method_exists('Swift_Performance_Cache', 'clear_all_cache')) {
            Swift_Performance_Cache::clear_all_cache();
        }
    }
    
    /**
     * Clear SG Optimizer cache
     */
    public static function clear_sg_optimizer_cache() {
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }
    }
    
    /**
     * Clear WP Fastest Cache
     */
    public static function clear_wp_fastest_cache() {
        if (class_exists('WpFastestCache') && method_exists('WpFastestCache', 'deleteCache')) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache(true);
        }
    }
    
    /**
     * Clear Autoptimize cache
     */
    public static function clear_autoptimize_cache() {
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
        }
    }
    
    /**
     * Clear Nginx Helper cache
     */
    public static function clear_nginx_helper_cache() {
        if (class_exists('Nginx_Helper') && function_exists('get_nginx_helper_instance')) {
            $nginx_helper = get_nginx_helper_instance();
            if (method_exists($nginx_helper, 'purge_all')) {
                $nginx_helper->purge_all();
            }
        }
    }
}
