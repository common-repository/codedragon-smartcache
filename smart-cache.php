<?php

/**
 * @link              http://www.codedragon.ca
 * @since             1.0.0b
 * @package           smart-cache
 *
 * @wordpress-plugin
 * Plugin Name:       CodeDragon SmartCache
 * Plugin URI:        http://www.codedragon.ca/products/wordpress-plugins/smartcache/
 * Description:       An intelligent site performance and caching optimization facility for Wordpress.  It offers super-fast, adaptive minification, GZIP compression, browser caching, database performance improvements, and more...
 * Version:           1.1.8
 * Author:            CodeDragon Software
 * Author URI:        http://www.codedragon.ca/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       smart-cache
 * Domain Path:       /languages
 */

/**
 * Copyright (c) CodeDragon Software LLP and other contributors
 */

/**
 * If this file is called directly, abort.
 */
defined('ABSPATH') or die('Nice try...');

if(!defined('SMART_CACHE_VER')) define('SMART_CACHE_VER', '1.1.8');
if(!defined('SMART_CACHE_PLUGIN_NAME')) define('SMART_CACHE_PLUGIN_NAME', 'smart-cache');
if(!defined('SMART_CACHE_PLUGIN_NAME_CODE')) define('SMART_CACHE_PLUGIN_NAME_CODE', 'smart_cache');
if(!defined('SMART_CACHE_PLUGIN_NAME_PROPER')) define('SMART_CACHE_PLUGIN_NAME_PROPER', 'SmartCache');
if(!defined('SMART_CACHE_PLUGIN_ROOT')) define('SMART_CACHE_PLUGIN_ROOT', plugin_dir_url(__FILE__));
if(!defined('SMART_CACHE_MINIFY_TAG')) define('SMART_CACHE_MINIFY_TAG', 'sc-minified');
if(!defined('SMART_CACHE_IS_PREMIUM')) define('SMART_CACHE_IS_PREMIUM', false);
if(!defined('SMART_CACHE_HOME_AUTH')) define('SMART_CACHE_HOME_AUTH', 'preview:xm1D!hRC1T,O');
if(!defined('SMART_CACHE_HOME_URL')) define('SMART_CACHE_HOME_URL', 'https://www.codedragon.ca/');
if(!defined('SMART_CACHE_PLUGIN_URL')) define('SMART_CACHE_PLUGIN_URL', SMART_CACHE_HOME_URL . 'products/wordpress-plugins/smartcache/');
if(!defined('SMART_CACHE_DASH_NEWS_CAT')) define('SMART_CACHE_DASH_NEWS_CAT', 66);
if(!defined('SMART_CACHE_PRODUCT_SUPPORT_CAT')) define('SMART_CACHE_PRODUCT_SUPPORT_CAT', 76);
if(!defined('SMART_CACHE_PRODUCT_DOCUMENT_CAT')) define('SMART_CACHE_PRODUCT_DOCUMENT_CAT', 86);
if(!defined('SMART_CACHE_REMINDER_CAT')) define('SMART_CACHE_REMINDER_CAT', 67);

/**
 * Plugin activation.
 */
function Smart_Cache_activate() {
    global $wp_rewrite;

    // Initially, flush the rewrite rules
    $wp_rewrite->flush_rules();

    // Clear tuning data (if any)
    delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files");
    delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-urls");

    add_option(SMART_CACHE_PLUGIN_NAME . "-activated", true);
}
register_activation_hook(__FILE__, 'Smart_Cache_activate');

/**
 * Plugin deactivation.
 */
function Smart_Cache_deactivate() {
}
register_deactivation_hook(__FILE__, 'Smart_Cache_deactivate');

/**
 * Plugin has been activated
 * @param string $plugin
 * @param boolean $network_activation
 */
function Smart_Cache_detect_plugin_activation( $plugin, $network_activation ) {
}
add_action( 'activated_plugin', 'Smart_Cache_detect_plugin_activation', 10, 2 );

/**
 * Plugin has been deactivated
 * @param string $plugin
 * @param boolean $network_activation
 */
function Smart_Cache_detect_plugin_deactivation( $plugin, $network_activation ) {
    if(SMART_CACHE_IS_PREMIUM){
        do_action('smart-cache-force-deactivate');
    }
    delete_site_option(SMART_CACHE_PLUGIN_NAME . "-version");
    $settings = get_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-tools', array());
    if(isset($settings['deactivate-tasks'])){
        $deactivation_tasks = $settings['deactivate-tasks'];
        if(in_array('clear-caches', $deactivation_tasks)){
            if(method_exists('Smart_Cache_Core', 'clear_cache_folder')){
                $plugin_core = new Smart_Cache_Core(__FILE__);
                $plugin_core->clear_cache_folder();
            }
        }
        if(in_array('delete-settings', $deactivation_tasks)){
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-minify');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-combine');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-page-caching');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-browser-caching');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-cdn');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . '-settings-tools');
            delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files-tuning");
            delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files");
            delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-urls");
        }
    }
}
add_action( 'deactivated_plugin', 'Smart_Cache_detect_plugin_deactivation', 10, 2 );

/**
 * Do specific actions if the version has changed
 */
function Smart_Cache_version_check(){
    $current_version = get_site_option(SMART_CACHE_PLUGIN_NAME . '-version', false);
    if($current_version !== SMART_CACHE_VER){
        update_site_option(SMART_CACHE_PLUGIN_NAME . "-do-scan", true);
        update_site_option(SMART_CACHE_PLUGIN_NAME . "-version", SMART_CACHE_VER);
    }
}
add_action('admin_init', 'Smart_Cache_version_check');

/**
 * Smart Cache core plugin class that is used to define internationalization,
 * dashboard-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path(__FILE__) . 'includes/class-sc-init.php';

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function Smart_Cache_run() {
    $plugin = new Smart_Cache();
    $plugin->run();
}
Smart_Cache_run();
