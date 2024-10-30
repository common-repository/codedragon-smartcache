<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Common functionality for the plugin.
 *
 * @package    Smart_Cache
 * @subpackage Smart_Cache/includes
 * @author     Dragon Slayer <info@codedragon.ca>
 */

defined('ABSPATH') or die('Nice try...');

class Smart_Cache_Base {
    /*
     * General properties
     */
    public $errors = array();
    public $notices = array();
    public $settings = array();

    /*
     * Admin properties
     */
    public $updated = false;
    public $file = null;
    public $form_groups = array(
        'misc',
        'minify',
        'combine',
        'page-caching',
        'browser-caching',
        'cdn',
        'tools',
        'reports'
    );
    public $page_group = null;

    /*
     * Core properties
     */
    public $page_url = null;
    public $cache_dir = null;
    public $cacheable_urls = array();
    public $cacheable_files = array();
    public $cacheable_files_tuning = array();
    public $debug_info = array();
    public $theme_folder = null;
    public $is_home_page = false;

    /*
     * Support classes
     */
    public $system;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct($plugin_file_name, $initial_call = false) {
        $this->file = $plugin_file_name;

        $this->system = new Smart_Cache_System(__FILE__, $this);
        $this->settings = $this->get_settings(SMART_CACHE_PLUGIN_NAME . "-settings");

        // Since the system and licensing are service classes, collect any errors/notices here
        if($initial_call){
            $this->system->self_check($this->notices, $this->errors);
        }
    }

    public function get_default_settings($group = null){
        $group = strtolower(trim($group));

        $defaults = array(
            "misc" => array(
            ),
            "minify" => array(
                "active" => true,
                "minify-css" => true,
                "minify-js" => true,
                "minify-html" => true,
                "minify-expiry" => 691200,              // 8 days
                "minify-logged-in-users" => false,
                "admin-users" => array(),
                "remove-query-strings" => true,
                "only-ver-query-strings" => false,
                "load-js-defer" => true,
                "load-js-async" => true,
                "load-css-async" => true,
                "exclude-jquery-migrate" => true,
                "include-wp-core-files" => false,
                "use-google-closure" => false,
                "styles" => array(),
                "scripts" => array(),
                "exclude-js-files" => "",
                "exclude-css-files" => "",
                "css-files-by-cpt" => array(),
                "js-files-by-cpt" => array(),
                "extract-@import" => true,
            ),
            "combine" => array(
                "active" => true,
                "combine-logged-in-users" => false,
                "admin-users" => array(),
                "combine-js" => true,
                "combine-css" => true,
                "combine-fonts" => true,
            ),
            "page-caching" => array(
                "active" => true,
                "page-caching-logged-in-users" => false,
                "admin-users" => array(),
                "gzip-compression" => true,
                "https" => false,
                "static-cache" => false,
                "exclude-front-page" => false,
                "exclude-by-page" => array(),
                "exclude-by-post-type" => array(),
            ),
            "browser-caching" => array(
                "active" => true,
                "exclude-user-agents" => array(),
                "isolate-mobile-cache" => true,
                "exclude-mobile-devices" => array(),
                "set-last-modified-header" => true,
                "set-expires-header-js" => 604800,
                "set-expires-header-css" => 604800,
                "set-expires-header-imgs" => 604800,
                "set-expires-header-pdf" => 604800,
                "set-accept-encoding-header" => true,
                "add-etag-header" => false,
                "cookie-block" => null,
            ),
            "cdn" => array(
                "active" => false,
                "include-attachments" => array(),
                "include-theme-files" => array(),
                "include-minified-files" => false,
                "include-custom-files" => array(),
                "exclude-custom-files" => array(),
                "cdn-validated" => false
            ),
            "tools" => array(
                "gtmetrix-test-username" => null,
                "gtmetrix-test-api" => null,
                "pingdon-test-username" => null,
                "pingdon-test-api" => null,
                "pagespeed-test-username" => null,
                "pagespeed-test-api" => null,
                "clear-post-type-saving" => array("posts", "pages"),
                "custom-cache-folder" => null,
                "debug-mode" => false,
                "deactivate-tasks" => array()
            ),
            "reports" => array(
            )
        );

        return (!empty($group) && isset($defaults[$group])) ? $defaults[$group] : $defaults;
    }

    /**
     * Save the default settings
     * @return array
     */
    public function initialize_settings(){
        $key = SMART_CACHE_PLUGIN_NAME . "-settings";
        $settings = $this->get_default_settings();
        foreach($settings as $group => $data){
            update_site_option($key . '-' . $group, $data);
        }
        return $settings;
    }

    /**
     * Retrieve plugin settings
     * @param string $key
     * @return array
     */
    public function get_settings($key){
        $defaults = $this->get_default_settings();
        $all_settings = array();
        foreach($this->form_groups as $group){
            $settings = get_site_option($key . '-' . $group);
            if(false === $settings){
                // initial settings
                $all_settings[$group] = $defaults[$group];
                $this->save_settings($key, $group, $defaults[$group]);
            }else{
                // requested settings
                $all_settings[$group] = $settings;
            }
        }

        return $all_settings;
    }

    /**
     * Save plugin settings
     * @param string $key
     * @return boolean
     */
    public function save_settings($key, $group, $data){
        if(empty($key) || empty($group)) return false;

        if($group == 'dashboard'){
            // dashboard settings are handled a little differntly
            $sect_groups = array(
                "minify",
                "combine",
                "page-caching",
                "browser-caching",
                "cdn"
            );
            foreach($sect_groups as $sect_group) if(!isset($data[$sect_group]['active'])) $data[$sect_group]['active'] = false;

            foreach($data as $sect_group => $sect_data){
                $current_data = get_site_option($key . '-' . $sect_group);
                foreach($sect_data as $field => $value){
                    $current_data[$field] = $value;
                }
                update_site_option($key . '-' . $sect_group, $current_data);
            }
        }else{
            if(!in_array($group, $this->form_groups)) return false;

            if($group == 'minify'){
                // selectively update the tuning settings
                $tuning = $this->system->get_cacheable_files_tuning();
                if(isset($data['scripts']) && isset($tuning['scripts'])){
                    foreach($tuning['scripts'] as $handle => $atts){
                        if(strpos($handle, SMART_CACHE_MINIFY_TAG) === false){
                            $tuning['scripts'][$handle]['minify'] = (isset($data['scripts'][$handle]['minify']));
                            $tuning['scripts'][$handle]['defer'] = (isset($data['scripts'][$handle]['defer']));
                            $tuning['scripts'][$handle]['async'] = (isset($data['scripts'][$handle]['async']));
                        }
                    }
                }
                if(isset($data['styles']) && isset($tuning['styles'])){
                    foreach($tuning['styles'] as $handle => $atts){
                        if(strpos($handle, SMART_CACHE_MINIFY_TAG) === false){
                            $tuning['styles'][$handle]['minify'] = (isset($data['styles'][$handle]['minify']));
                            $tuning['styles'][$handle]['async'] = (isset($data['styles'][$handle]['async']));
                        }
                    }
                }
                unset($data['scripts']);
                unset($data['styles']);

                $this->system->update_cacheable_info(null, null, $tuning);
            }

            $current_data = get_site_option($key . '-' . $group);
            if(!isset($data['active']) && !in_array($group, array('tools', 'reports', 'misc'))){
                // if group is not active...
                $data = $current_data;  // ...recall all other settings in group
                unset($data['active']);
            }

            // ensure the settings array contains all saved and default values (for those items not submitted)
            $defaults = $this->get_default_settings($key);
            foreach($defaults[$group] as $default_item_key => $default_item_value){
                if(!isset($data[$default_item_key])) $data[$default_item_key] = null;
            }

            $ok = update_site_option($key . '-' . $group, $data);
        }
        return true;
    }

    /**
     * Return a formatted array of all current settings
     * @return array
     */
    public function get_settings_summary(){
        $info = array();

        foreach($this->settings as $group => $atts){
            if(!empty($atts)){
                foreach($atts as $key => $value){
                    if(empty($value)) $value = "0";
                    $info[] = array("item" => $this->capitalize($group.": ".$key), "value" => $value);
                }
            }
        }
        return $info;
    }

    /**
     * Return the license label
     * @return string
     */
    public function get_license_info(){
        $info = array('level' => 'Free', 'valid' => true, 'key' => null);
        return $info;
    }

    /**
     * Performs an add_filter only once. Helpful for factory constructors where an action only
     * needs to be added once. Because of this, there will be no need to do a static variable that
     * will be set to true after the first run, ala $firstLoad
     *
     * @since 1.9
     *
     * @param string   $tag             The name of the filter to hook the $function_to_add callback to.
     * @param callback $function_to_add The callback to be run when the filter is applied.
     * @param int      $priority        Optional. Used to specify the order in which the functions
     *                                  associated with a particular action are executed. Default 10.
     *                                  Lower numbers correspond with earlier execution,
     *                                  and functions with the same priority are executed
     *                                  in the order in which they were added to the action.
     * @param int      $accepted_args   Optional. The number of arguments the function accepts. Default 1.
     *
     * @return true
     */
    public function add_filter_once( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        global $_gambitFiltersRan;

        if (! isset($_gambitFiltersRan)) {
            $_gambitFiltersRan = array();
        }

        // Since references to $this produces a unique id, just use the class for identification purposes
        $idxFunc = $function_to_add;
        if (is_array($function_to_add)) {
            $idxFunc[0] = get_class($function_to_add[0]);
        }
        $idx = _wp_filter_build_unique_id($tag, $idxFunc, $priority);

        if (! in_array($idx, $_gambitFiltersRan)) {
            add_filter($tag, $function_to_add, $priority, $accepted_args);
        }

        $_gambitFiltersRan[] = $idx;

        return true;
    }

    /**
     * Return an array containing the list of Wordpress user role names
     * @return array
     */
    public function get_wp_roles(){
        global $wp_roles;

        if (! isset($wp_roles)) $wp_roles = new WP_Roles();
        $roles = $wp_roles->get_names();
        return $roles;
    }

    /*
     * Get user's role
     *
     * If $user parameter is not provided, returns the current user's role.
     * Only returns the user's first role, even if they have more than one.
     * Returns false on failure.
     *
     * @param  mixed            $user User ID or object.
     * @return string|bool      The User's role, or false on failure.
     */
    public function get_user_role( $user = null ) {
        $user = $user ? new WP_User( $user ) : wp_get_current_user();
        return $user->roles ? strtolower($user->roles[0]) : false;
    }

    /**
     * Add a query fragment to the end of a url (that may or may not already have a query)
     * @param  string $url
     * @param  string $query
     * @return string
     */
    public function append_query_var( $url, $query ){
        $query = trim($query, "&?");
        if(strpos($url, "?") === false)
            $url .= "?" . $query;
        else
            $url .= "&" . $query;
        return $url;
    }

    /**
     * Determine if query strings (all or only ver=) are to be removed from a path
     * @param  array $path_parts
     * @return string
     */
    public function check_query_string($path_parts){
        if(is_string($path_parts))
            $path_parts = explode("?", $path_parts);
        if(isset($path_parts[1])){
            // there is a query string
            if(boolval($this->settings['minify']['remove-query-strings'])){
                // and we are removing it
                if(boolval($this->settings['minify']['only-css-query-strings'])){
                    // only ver= strings though
                    $query_parts = explode("&", $path_parts[1]);
                    foreach($query_parts as $qk => $qv){
                        if(strpos($qv, "ver=") !== false) unset($query_parts[$qk]);
                    }
                    $path_parts[1] = join("&", $query_parts);
                    if(!empty($path_parts[1])) $path_parts[1] = "?" . $path_parts[1];
                }else{
                    $path_parts[1] = null;
                }
            }else{
                $path_parts[1] = "?" . $path_parts[1];
            }
        }else{
            $path_parts[1] = null;
        }
        return $path_parts[1];
    }

    /**
     * Perform specific initialization tasks
     */
    public function do_process_request(){
        $notice = null;
        $error = null;

        if(! empty($_REQUEST['process']) && ! empty($_REQUEST['_wpnonce'])){
            // process requests
            if(wp_verify_nonce( $_REQUEST['_wpnonce'], SMART_CACHE_PLUGIN_NAME . '-process' )){
                if(current_user_can('manage_options')){
                    $page_url = $this->system->get_current_page_url(true, array("process", "_wpnonce"));
                    switch($_REQUEST['process']){
                        case 'clear-caches':
                            $core = new Smart_Cache_Core($this->file);
                            if($core->clear_cache_folder('cache')){
                                $notice = 'Caches have been cleared';
                            }
                            // wp_redirect($page_url);
                            break;
                        case 'scan-site':
                            $system = new Smart_Cache_System($this->file, $this);
                            if($system->scan_files()){
                                $notice = '<span id="sc-scan-site" data-page="' . $page_url . '">Your site is being scanned... Please wait until it is finished.</span>';
                            }
                            break;
                        case 'export':
                            $jsettings = 'Name: ' . SMART_CACHE_PLUGIN_NAME_PROPER . PHP_EOL;
                            $jsettings.= 'Is Premium: ' . ((SMART_CACHE_IS_PREMIUM) ? 'yes' : 'no') . PHP_EOL;
                            $jsettings.= 'Version: ' . SMART_CACHE_VER . PHP_EOL . PHP_EOL;
                            $jsettings.= json_encode($this->get_settings(SMART_CACHE_PLUGIN_NAME . "-settings"));
                            $file = ABSPATH . SMART_CACHE_PLUGIN_NAME . ".set";
                            if($fh = fopen($file, "w")){
                                fwrite($fh, $jsettings);
                                fclose($fh);
                                header('Content-Type: application/octet-stream');
                                header('Content-Disposition: attachment; filename=' . basename($file));
                                header('Expires: 0');
                                header('Cache-Control: must-revalidate');
                                header('Pragma: public');
                                header('Content-Length: ' . filesize($file));
                                readfile($file);
                                exit;
                            }
                            break;
                    }
                }
            }
        }

        return array("notice" => $notice, "error" => $error);
    }

    /**
     * Process a requested task
     * @param  string $task
     * @return boolean
     */
    public function handle_requested_task($task){
        $halt = false;

        switch($task){
        }
        return $halt;
    }

    //
    //** STATS CALCULATIONS **
    //

    /**
     * Calculate performance statistics (percent optimized vs. unoptimized and difference between same)
     * @return array
     */
    public function get_stats(){
        $this->cacheable_files = $this->system->get_cacheable_files();

        $stats = array(
            "scripts" => array(
                "unoptimized" => array(
                    "source-size" => 0,
                    "count" => 0
                ),
                "cached" => array(
                    "count" => 0,
                    "source-size" => 0,
                    "cached-size" => 0,
                    "count-ratio" => 0,
                    "size-ratio" => 0,
                    "diff" => 0
                ),
                "combined" => array(
                ),
            ),
            "styles" => array(
                "unoptimized" => array(
                    "source-size" => 0,
                    "count" => 0
                ),
                "cached" => array(
                    "count" => 0,
                    "source-size" => 0,
                    "cached-size" => 0,
                    "count-ratio" => 0,
                    "size-ratio" => 0,
                    "diff" => 0
                ),
                "combined" => array(
                ),
            )
        );
        $site_url = site_url();

        foreach($this->cacheable_files as $group => $files){
            $s = 0;             // source size sum
            $sn = 0;            // number of source files
            $k = 0;             // cached size sum
            $kn = 0;            // number of cached files
            $c = array();       // combined files array
            $site_url = site_url();

            foreach($files as $file_handle => $file){
                if(strpos($file_handle, 'sc-combined') !== false){
                    // a combined target file
                    $sp = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $file['source']);
                    if(file_exists($sp)){
                        $k += filesize($sp);
                    }
                }else{
                    if(! empty($file['combined-into']) && ! empty($file['source'])){
                        // a single combined file
                        $sp = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $file['source']);
                        if(file_exists($sp)){
                            $s += filesize($sp);
                            $sn++;
                            $kn++;
                        }
                    }elseif(! empty($file['cached-size']) && ! empty($file['cached']) && ! empty($file['source-size'])){
                        // a single minified file
                        $k += intval($file['cached-size']);
                        $kn++;
                        $s += intval($file['source-size']);
                        $sn++;
                    }
                }
            }

            $stats[$group]['unoptimized']['source-size'] = $s;
            $stats[$group]['unoptimized']['count'] = $sn;
            $stats[$group]['cached']['source-size'] = $s;
            $stats[$group]['cached']['cached-size'] = $k;
            $stats[$group]['cached']['count'] = $kn;
            $stats[$group]['cached']['count-ratio'] = ($sn > 0) ? ($kn / $sn) * 100 : 0;
            $stats[$group]['cached']['size-ratio'] = ($s > 0 && $k > 0) ? (1 - $k / $s) * 100 : 0;
            $stats[$group]['cached']['diff'] = $s - $k;

            $stats[$group]['combined'] = $c;
        }
        return $stats;
    }

    //
    //** REST API CONNECTIONS **
    //

    /**
     * Get list of add-ons from home url
     * @return array
     */
    public function get_addons_list(){
        $data = array();
        $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/download/?filter[download_category]=wordpress-addons");
        if($response){
            $data = json_decode($response, true);
            if(count($data) > 0){
                foreach($data as $key => $addon){
                    $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/codedragon/v1/download_meta/" . $addon['id']);
                    if($response){
                        $meta_data = json_decode($response, true);
                        $data[$key] = array_merge($data[$key], $meta_data);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Get news posts
     * @return array
     */
    public function get_codedragon_posts($cat){
        $data = array();
        $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/posts?categories=" . $cat);
        if($response){
            $data = json_decode($response, true);
        }
        return $data;
    }

    /**
     * Get document posts
     * @return array
     */
    public function get_codedragon_documents($cat){
        $data = array();
        $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/document?categories=" . $cat);
        if($response){
            $data = json_decode($response, true);
        }
        return $data;
    }

    /**
     * Get dashboard news posts
     * @return array
     */
    public function get_nag($single = true){
        $retn = array();
        $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/posts?categories=" . SMART_CACHE_REMINDER_CAT . "&tags=68");
        if($response){
            $data = json_decode($response, true);
            if(count($data) > 0){
                if($single){
                    $key = rand(0, count($data) - 1);
                    $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/posts/" . $data[$key]['id'] . '/meta');
                    if($response){
                        $retn = $data[$key];
                        $meta_data = json_decode($response, true);
                        $retn = array_merge($retn, $meta_data);
                    }
                }else{
                    foreach($data as $key => $post){
                        $retn_post = $post;
                        $response = $this->system->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/wp/v2/posts/" . $post['id'] . '/meta');
                        if($response){
                            $meta_data = json_decode($response, true);
                            $retn_post = array_merge($retn_post, $meta_data);
                        }
                        $retn[] = $retn_post;
                    }
                }
            }
        }
        return $retn;
    }

    //
    //** DEBUGGER **
    //

    /**
     * Shortcode to output debug array
     * @param  array $atts
     * @return string
     */
    public function show_debug($atts = array()){
        $html = '';
        $show = ($this->get_if_set($atts, 'force') == 1 || boolval($this->get_if_set($this->settings['tools'], 'debug-mode')));
        if($show && isset($GLOBALS['sc-debug-info'])){
            $html = join("<br/>", $GLOBALS['sc-debug-info']);
        }
        if(!empty($html)) $html = '<div id="sc-debug-info" style="border: 1px solid gray; padding: 10px;">' . $html . '</div>';
        return $html;
    }

    /**
     * Output a direct message div block instead of through admin hook
     * @param  string $message
     * @param  string $level
     */
    public function show_direct_message($message, $level = 'success'){
        if(!empty($message) && is_string($message) && in_array($level, array('success', 'error'))){
            echo '
            <div class="notice-' . $level . ' notice is-dismissible smart-cache-message">
                <p>
                    ' . $message . '
                </p>
            </div>';
        }
    }

    //
    //**  MISCELLANY **
    //

    /**
     * Display a "prettified" version of array or object variable
     * @param $arr;
     */
    public function pr($arr, $title = null){
        echo '<pre class="row clear">' . $title . '<br/>' . print_r($arr, true) . '</pre>';
    }

    /**
     * Convert string to UTF8
     */
    private function convert_utf8($string) {
        if (strlen(utf8_decode($string)) == strlen($string)) {
            // $string is not UTF-8
            return iconv("ISO-8859-1", "UTF-8", $string);
        } else {
            // already UTF-8
            return $string;
        }
    }

    /**
     * Slugify string (change anything other than letters, numbers, underscore or dashes to dashes)
     * @param string
     * @return string
     */
    public function slugify($text){
        return strtolower(preg_replace("/[^0-9a-zA-Z_-]/", "-", $text));
    }

    /**
     * Convert string to proper capitals
     * @param string
     * @return string
     */
    public function capitalize($text){
        $str = str_replace(
            array("Css", "Cdn", "Api", "Gtmetrix", "Html", "Js", "Https", "Aws", "Pdf", "Img", "Ssl", "Jquery"),
            array("CSS", "CDN", "API", "GTMetrix", "HTML", "JS", "HTTPS", "AWS", "PDF", "Image", "SSL", "JQuery"),
            ucwords(str_replace(array("-", "_"), " ", $text))
        );
        return $str;
    }

    /**
     * Return array element value if key exists
     */
    public function get_if_set($array, $key){
        if(isset($array[$key]))
            return $array[$key];
        else
            return null;
    }

    /**
     * Return variable value, if set, or default/null
     * @param mixed $var
     * @param mixed $default
     * @return mixed|null
     */
    public function get_val(&$var, $default = null){
        if(isset($var)){
            return $var;
        }else{
            return $default;
        }
    }

    /**
     * Return TRUE or FALSE from variable
     * @param $val
     * @return boolean
     */
    public function as_boolean($val){
        if(is_string($val)){
            $val = strtolower($val);
            switch($val){
                case '+':
                case 'y':
                case 'yes':
                case 'on':
                case 'true':
                case 'enabled':
                    return true;
                case '-':
                case 'n':
                case 'no':
                case 'off':
                case 'false':
                case 'disabled':
                    return false;
            }
        }

        return (boolean) $val;
    }

    /**
     * Return if string is a timestamp
     * @param  string $timestamp
     * @return boolean
     */
    public function is_timestamp($timestamp) {
        if(ctype_digit($timestamp) && strtotime(date('Y-m-d H:i:s', $timestamp)) === (int)$timestamp) {
            return true;
        } else {
            return false;
        }
    }
}