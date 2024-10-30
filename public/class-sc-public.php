<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Public-facing functionality
 *
 * @package    Smart_Cache
 * @subpackage smart-cache/public
 * @author     Dragon Slayer <info@codedragon.ca>
 */
class Smart_Cache_Public extends Smart_Cache_Base {
    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct($plugin_file_name) {
        parent::__construct($plugin_file_name);
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        if( ! is_admin()){
            wp_register_script(SMART_CACHE_PLUGIN_NAME, plugin_dir_url(__FILE__) . "js/sc-public.js", array("jQuery"), SMART_CACHE_VER, true);
            wp_localize_script(SMART_CACHE_PLUGIN_NAME, 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
            wp_enqueue_script(SMART_CACHE_PLUGIN_NAME);
        }
    }

    /**
     * Process form submissions and query requests
     */
    public function public_post_init() {
        if(! is_admin()){
            if(isset($_REQUEST['sc-debug']) && $_REQUEST['sc-debug'] == md5('sc-debug-' . date("Ymdh"))){
                add_action( 'wp_footer', array($this, 'do_nonce_debug') );
            }
            $this->notices[] = $this->do_process_request();
        }
    }

    /**
     * Issue call to show_debug shortcode function on valid nonce
     */
    public function do_nonce_debug() {
        $html = do_shortcode('[sc-show-debug force=1]');
        $html = str_replace("<br/>", PHP_EOL, $html);
        echo $html;
    }

    /**
     * Execute public AJAX calls
     */
    public function public_ajax_handler(){
        global $wpdb;

        if(empty($_POST['task']) || empty($_POST['key']) || $_POST['key'] != md5($_POST['task'])) {
            echo false;
        }else{
            $retn = false;
            switch(strtolower($_POST['task'])){
            }
            echo $retn;
        }

        wp_die();
    }
}