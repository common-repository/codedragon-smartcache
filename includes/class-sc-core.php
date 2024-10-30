<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Performance Core
 *
 * @package    Smart_Cache
 * @subpackage Smart_Cache/includes
 * @author     Dragon Slayer <info@codedragon.ca>
 */

defined('ABSPATH') or die('Nice try...');

if(!defined('SMART_CACHE_DEF_CACHE_FOLDER')) define('SMART_CACHE_DEF_CACHE_FOLDER', DIRECTORY_SEPARATOR . 'sc_vault');
if(!defined('SMART_CACHE_SSL_CACHE_FOLDER')) define('SMART_CACHE_SSL_CACHE_FOLDER', DIRECTORY_SEPARATOR . 'sc_vault_ssl');
if(!defined('SMART_CACHE_MOBILE_CACHE_FOLDER')) define('SMART_CACHE_MOBILE_CACHE_FOLDER', DIRECTORY_SEPARATOR . 'sc_vault_mobile');
if(!defined('SMART_CACHE_COMBINE_FOLDER')) define('SMART_CACHE_COMBINE_FOLDER', 'sc_combine');
if(!defined('SMART_CACHE_REMOTE_FOLDER')) define('SMART_CACHE_REMOTE_FOLDER', 'sc_remote');
if(!defined('SMART_CACHE_STATIC_FOLDER')) define('SMART_CACHE_STATIC_FOLDER', 'sc_static');
if(!defined('SMART_CACHE_COMBINE_FILE_SIZE_LIMIT')) define('SMART_CACHE_COMBINE_FILE_SIZE_LIMIT', 150000);

require_once("minifier/class.magic-min.php");

class Smart_Cache_Core extends Smart_Cache_Base {
    public $cache_dir;
    public $combine_dir;
    public $remote_dir;
    public $static_dir;
    public $combine_css_index;
    public $combine_js_index;
    public $temp_file_stem;
    public $combine_file_stem;
    public $merged_last_file_path;
    public $files_encountered;
    public $files;
    public $ua_info;

    public function __construct($plugin_file_name){
        parent::__construct($plugin_file_name);
        $this->file = $plugin_file_name;
        $this->cache_dir = null;
        $this->combine_dir = null;
        $this->remote_dir = null;
        $this->combine_css_index = 1;
        $this->combine_js_index = 1;
        $this->temp_file_stem = null;
        $this->combine_file_stem = null;
        $this->files = array();
        $this->files_encountered = array();

        $this->ensure_cache_folder_exists();

        add_action('init', array($this, 'core_init'));
    }

    /**
     * Preparation
     */
    public function core_init(){
        static $init_done = false;
        if($init_done) return false;
        if(defined( 'DOING_AJAX' ) && DOING_AJAX) return false;

        // get the current page URL, site URL and extension
        $this->page_url = $this->system->get_current_page_url();
        if(strpos($this->page_url, 'wp-json')) return false;

        do_action(SMART_CACHE_PLUGIN_NAME . '-before-cache-start');

        $this->theme_folder = (!is_admin()) ? str_replace(site_url(), "", get_stylesheet_directory_uri()) : null;
        $extension = pathinfo($this->page_url, PATHINFO_EXTENSION);
        $this->is_home_page = ($this->page_url == site_url() || is_front_page());
        $this->combine_css_index = 1;
        $this->combine_js_index = 1;

        // do initial task if URL is a page or home page
        if(empty($extension) || $extension == 'php' || $this->is_home_page){
            $task = $this->get_val($_GET['task']);
            if(!empty($task)){
                // do secondary tasks if URL includes a task
                $halt = false;
                if(isset($_GET['_wpnonce']) && !empty($task)) {
                    if(wp_verify_nonce($_GET['_wpnonce'], SMART_CACHE_PLUGIN_NAME . '-task')) {
                        $halt = $this->handle_requested_task($task);
                    }
                }
                if($halt) return false;
            }
        }

        /*
         * Minification and Combination Directives
         */
        // the combined file stem is a hash of the page the browser is viewing
        // this way each unique page URL will have it's set of combined files
        $this->temp_file_stem = 'temp-' . md5(get_pagenum_link(get_query_var('paged')));

        // set the minify expiry if not set
        if(boolval($this->settings['minify']['active']) && $this->settings['minify']['minify-expiry'] < 60) $this->settings['minify']['minify-expiry'] = 3600;

        // allow minify/combine/caching operations if user is logged in and role was selected OR not logged in
        $minify_ok = true;
        $combine_ok = true;
        $page_cache_ok = true;
        $current_user = wp_get_current_user();

        if($current_user instanceof WP_User && !empty($current_user->roles)){

            $minify_admin_users = $this->get_val($this->settings['minify']['admin-users'], array());
            $minify_ok = boolval($this->settings['minify']['minify-logged-in-users']) && !empty(array_intersect($current_user->roles, array_keys($minify_admin_users)));

            $combine_admin_users = $this->get_val($this->settings['combine']['admin-users'], array());
            $combine_ok = boolval($this->settings['combine']['combine-logged-in-users']) && !empty(array_intersect($current_user->roles, array_keys($combine_admin_users)));

            $page_cache_admin_users = $this->get_val($this->settings['page-caching']['admin-users'], array());
            $page_cache_ok = boolval($this->settings['page-caching']['page-caching-logged-in-users']) && !empty(array_intersect($current_user->roles, array_keys($page_cache_admin_users)));
        }

        /*
         * Page Caching Directives
         */
        if(boolval($this->settings['page-caching']['active']) && $page_cache_ok){
            // we're on the front page and the setting "Do not cache front page" was set
            if($this->is_home_page && boolval($this->settings['page-caching']['exclude-front-page'])) {
                return false;
            }
        }

        do_action(SMART_CACHE_PLUGIN_NAME . '-before-minify');

        // Javascript combine+minify or just minify
        if(boolval($this->settings['combine']['active']) && boolval($this->settings['combine']['combine-js']) && $combine_ok){
            if(is_admin()){
                add_action('admin_enqueue_scripts', array($this, 'combine_js_files'), PHP_INT_MAX);
            }else{
                add_action('wp_enqueue_scripts', array($this, 'combine_js_files'), PHP_INT_MAX);
                add_action('wp_footer', array($this, 'combine_js_files'), 1);
            }
        }elseif(boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['minify-js']) && $minify_ok){
            if(is_admin()){
                add_action('admin_enqueue_scripts', array($this, 'minify_js_files'), PHP_INT_MAX);
            }else{
                add_action('wp_enqueue_scripts', array($this, 'minify_js_files'), PHP_INT_MAX);
                add_action('wp_footer', array($this, 'minify_js_files'), 1);
            }
        }

        // CSS combine+minify or just minify
        if(boolval($this->settings['combine']['active']) && boolval($this->settings['combine']['combine-css']) && $combine_ok){
            if(is_admin()){
                add_action('admin_enqueue_scripts', array($this, 'combine_css_files'), PHP_INT_MAX);
            }else{
                add_action('wp_enqueue_scripts', array($this, 'combine_css_files'), PHP_INT_MAX);
                add_action('wp_footer', array($this, 'combine_css_files'), 1);
            }
        }elseif(boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['minify-css']) && $minify_ok){
            if(is_admin()){
                add_action('admin_enqueue_scripts', array($this, 'minify_css_files'), PHP_INT_MAX);
            }else{
                add_action('wp_enqueue_scripts', array($this, 'minify_css_files'), PHP_INT_MAX);
                add_action('wp_footer', array($this, 'minify_css_files'), 1);
            }
        }

        if(boolval($this->settings['minify']['active'])){
            do_action(SMART_CACHE_PLUGIN_NAME . '-before-defer-async');

            // CSS async
            $css_async = false;
            if(boolval($this->settings['minify']['load-css-async']) && $minify_ok){
                $css_async = true;
            }
            if($css_async){
                add_filter('style_loader_tag', array($this, 'async_files'), PHP_INT_MAX, 2);
            }

            add_filter('clean_url', array($this, 'late_process_file'), PHP_INT_MAX, 1);   // this catches minified files added to queue
        }

        $init_done = true;
        do_action(SMART_CACHE_PLUGIN_NAME . '-after-cache-prep');

        add_action('shutdown', array($this, 'clean_temp_files'));
    }

    /**
     * Ensure the cache folders exist and create them if not
     */
    public function ensure_cache_folder_exists(){
        $ok = true;
        $uploads_array = wp_upload_dir();
        $uploads_base_dir = $uploads_array['basedir'];

        // get user agent
        $this->ua_info = $this->system->parse_user_agent();
        if($this->system->mobile_matches($this->ua_info) && (boolean)$this->settings['browser-caching']['isolate-mobile-cache']){
            // use a mobile-only cache location
            if(!defined('SMART_CACHE_CACHE_FOLDER')) define('SMART_CACHE_CACHE_FOLDER', SMART_CACHE_MOBILE_CACHE_FOLDER);
        }else{
            // use the default location
            if(!defined('SMART_CACHE_CACHE_FOLDER')) define('SMART_CACHE_CACHE_FOLDER', SMART_CACHE_DEF_CACHE_FOLDER);
        }

        $this->cache_dir = $uploads_base_dir . SMART_CACHE_CACHE_FOLDER;

        // hook (cache location)
        $updated_cache_dir = apply_filters( 'smart_cache_set_cache_dir', $this->cache_dir );
        if(! empty($updated_cache_dir)){
            if(is_string($updated_cache_dir)){
                $this->cache_dir = $updated_cache_dir;
            }
        }

        // base cache directory
        if(!file_exists($this->cache_dir)){
            if($this->system->is_writable($uploads_base_dir . DIRECTORY_SEPARATOR)){
                mkdir($this->cache_dir);
                chmod($this->cache_dir, 0755);
            }else{
                $this->errors[] = 'The folder <strong>' . $uploads_base_dir . '</strong> is not writable.  Please ensure it exists and its permissions are at least 0755.';
                $ok = false;
            }
        }
        $this->cache_dir .= DIRECTORY_SEPARATOR;

        if($ok){
            // cache + domain directory
            $this->cache_dir .= str_replace(array("http://", "https://", "/"), array("http_", "https_", "_"), site_url());
            if(!file_exists($this->cache_dir)){
                mkdir($this->cache_dir);
                chmod($this->cache_dir, 0755);
            }
            $this->cache_dir .= DIRECTORY_SEPARATOR;

            // cache + domain + combination directory
            $this->combine_dir = $this->cache_dir . SMART_CACHE_COMBINE_FOLDER;
            if(!file_exists($this->combine_dir)){
                mkdir($this->combine_dir);
                chmod($this->combine_dir, 0755);
            }
            $this->combine_dir .= DIRECTORY_SEPARATOR;

            // cache + domain + remote directory
            $this->remote_dir = $this->cache_dir . SMART_CACHE_REMOTE_FOLDER;
            if(!file_exists($this->remote_dir)){
                mkdir($this->remote_dir);
                chmod($this->remote_dir, 0755);
            }
            $this->remote_dir .= DIRECTORY_SEPARATOR;

            // cache + domain + static directory
            $this->static_dir = $this->cache_dir . SMART_CACHE_STATIC_FOLDER;
            if(!file_exists($this->static_dir)){
                mkdir($this->static_dir);
                chmod($this->static_dir, 0755);
            }
            $this->static_dir .= DIRECTORY_SEPARATOR;

            if(isset($_REQUEST['clear'])) $this->clear_cache_folder($_REQUEST['clear']);

            $webroot_url = get_bloginfo('url') . DIRECTORY_SEPARATOR;
            if(!defined('SMART_CACHE_WEB_ROOT_URL')) define('SMART_CACHE_WEB_ROOT_URL', $webroot_url);
            if(!defined('SMART_CACHE_UPLOADS_BASE_URL')) define('SMART_CACHE_UPLOADS_BASE_URL', $uploads_base_dir);
        }else{
            $this->show_direct_message(join('<br/>', $this->errors), 'error');
        }
    }

    /**
     * Clears the cache folder
     * @param string $folder
     * @param string $extension              Extension or nothing for all (eg. '.js')
     */
    public function clear_cache_folder($folder = 'cache', $extension = ''){
        if($folder == 'cache'){
            $dir = $this->cache_dir;
        }elseif($folder == 'mobile'){
            $uploads_array = wp_upload_dir();
            $uploads_base_dir = $uploads_array['basedir'];
            $dir = $uploads_base_dir . SMART_CACHE_MOBILE_CACHE_FOLDER;
        }elseif($folder == 'combine'){
            $dir = $this->combine_dir;
        }elseif($folder == 'static'){
            $dir = $this->static_dir;
        }else{
            return false;
        }

        do_action(SMART_CACHE_PLUGIN_NAME . '-before-' . $folder . '-clear-cache');
        $this->system->recursive_unlink($dir);

        if($folder != 'mobile'){
            delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-urls");
            delete_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files");
            $this->clear_cache_folder('mobile');
        }
        do_action(SMART_CACHE_PLUGIN_NAME . '-after-' . $folder . '-clear-cache');
        return true;
    }

    /**
     * Take action when a spacified post type is saved or updated
     * @param  integer $post_id
     * @param  object $post
     * @param  boolean $update
     * @return
     */
    public function clear_cache_on_post_save($post_id, $post, $update){
        return false;
    }

    /**
     * Delete all non-live (temp) combined files
     */
    public function clean_temp_files(){
        $files = glob($this->combine_dir . '*.*');
        if(!empty($files)){
            foreach($files as $file){
                $base = basename($file);
                if(substr($base, 0, 5) !== 'live-' && substr($base, 0, 5) !== 'temp-')
                    unlink($file);
            }
        }
    }

    /*
     * Minification algorithms
     * -----------------------
     */

    /**
     * CSS minify bridge
     */
    public function minify_css_files(){
        $this->minify_fileset('.css');
    }

    /**
     * Javascript minify bridge
     */
    public function minify_js_files(){
        $this->minify_fileset('.js');
    }

    /**
     * Fileset minification
     * @param string $extension         .js or .css
     */
    public function minify_fileset($extension){
        global $wp_scripts, $wp_styles, $wp;

        // reorder the handles based on its dependency
        // the result will be saved in the to_do property ($wp_scripts->queue)
        if($extension === '.js'){
            if (!$wp_scripts instanceof WP_Scripts) {
                $wp_scripts = new WP_Scripts();
            }
            $this->files = $wp_scripts;
            $stats_file_type = 'scripts';
        }else{
            if (!$wp_styles instanceof WP_Styles) {
                $wp_styles = new WP_Styles();
            }
            $this->files = $wp_styles;
            $stats_file_type = 'styles';
        }
        $this->files->all_deps($this->files->queue);

        // get cacheable files list
        if(empty($this->cacheable_files))
            $this->cacheable_files = $this->system->get_cacheable_files();
        if(empty($this->cacheable_urls))
            $this->cacheable_urls = $this->system->get_cacheable_urls();

        // New file location
        $site_url = site_url();

        $excluded_file_handles = array();                       // files that are excluded from minification
        $dropped_file_handles = array();                        // files to be dropped from loading
        $minified_file_handles = array();                       // files that are minified
        $font_handles = array();                                // font files

        // jquery files
        $excluded_file_handles[] = "jquery";
        $excluded_file_handles[] = "jquery-core";
        if(is_admin()) $excluded_file_handles[] = "common";

        // hook
        $updated_file_handles = apply_filters( 'smart_cache_minify_fileset', $excluded_file_handles );
        if(! empty($updated_file_handles)){
            if(is_array($updated_file_handles)){
                $excluded_file_handles = $updated_file_handles;
            }
        }

        // run through all file handles
        foreach($this->files->queue as $handle){
            if(!isset($this->files->registered[$handle])) continue;
            $file_data = $this->files->registered[$handle];
            $file_data_deps = $file_data->deps;
            $file_data_ver  = $file_data->ver;
            $file_is_remote = false;

            $src = $file_data->src;
            if(substr($src, 0, 1) == DIRECTORY_SEPARATOR && substr($src, 0, 2) != "//") $src = $site_url . $src;
            $src_file_url = $src;

            // exclude already processed files
            if(strpos($src_file_url, '.php')){
                $excluded_file_handles[] = $handle;
            }

            $localize = null;
            if(strpos($src_file_url, 'fonts') !== false){
                // src is a font
                $excluded_file_handles[] = $handle;
                $font_handles[] = $handle;
                $src_file_url = null;
            }elseif(!in_array($handle, $excluded_file_handles) && !in_array($handle, $dropped_file_handles)){
                // if src does not include http or https, add it and continue
                if(strpos($src, 'http') === false){
                    if(is_SSL())
                        $src = preg_replace('/^\/\//', 'https://', $src);
                    else
                        $src = preg_replace('/^\/\//', 'http://', $src);
                }

                // to reduce HTTP requests, we are only dealing with local requests (same domain)
                if(strpos($src, $site_url) !== false){
                    // Check for wp_localize_script
                    if(@array_key_exists('data', $file_data->extra)) {
                        $localize = trim($file_data->extra['data'], ';');
                    }

                    list($src_file_url, $file_size) = $this->minify_file($src);
                    $minified_file_handles[] = $handle;
                }else{
                    // file is not in domain
                    list($src_file_url, $file_size) = $this->minify_file($src, true);
                    $minified_file_handles[] = $handle;
                    $file_is_remote = true;
                }
            }else{
                // since file was not minified (either is dropped or excluded), do not re-enqueue it
                $src_file_url = null;
            }

            // enqueue the URL of the minified file
            if(! empty($src_file_url)){
                list($file_excluded, $cacheable_file_info) = $this->enqueue_minified_file($stats_file_type, $handle, $src, $src_file_url, $file_data_deps, $file_data_ver, $localize);
                if($file_excluded) {
                    $excluded_file_handles[] = $handle;
                } elseif(!empty($cacheable_file_info)) {
                    $cacheable_file_info['cached-size'] = $file_size;       // record file size of file processed
                    $this->cacheable_files[$stats_file_type][$handle] = $cacheable_file_info;
                }
            }
        }

        // update global wp_scripts/wp_styles class
        if($stats_file_type == 'scripts'){
            $wp_scripts = $this->files;
        }else{
            $wp_styles = $this->files;
        }

        // deregister handles of minified or dropped files
        foreach($this->files->queue as $handle){
            if(!isset($this->files->registered[$handle])) continue;
            $file_data = $this->files->registered[$handle];
            $src = $file_data->src;

            if(in_array($handle, $dropped_file_handles)){
                // file is one of the merged or dropped ones, deregister/dequeue it
                if($stats_file_type == 'scripts'){
                    wp_deregister_script($handle);
                    wp_dequeue_script($handle);
                    $this->files->remove($handle);
                }else{
                    wp_deregister_style($handle);
                    wp_dequeue_style($handle);
                    $this->files->remove($handle);
                }
            }elseif(!in_array($handle, $excluded_file_handles)){
                if(!in_array($handle, $font_handles)){
                    // re-register any files that have a different protocol than the site
                    $src = explode("?", $src);
                    $src = $src[0] . $this->check_query_string($src);
                    if(substr($src, 0, 1) == DIRECTORY_SEPARATOR)
                        $src = $site_url . $src;

                    if($src != $file_data->src){
                        if($stats_file_type == 'scripts'){
                            wp_deregister_script($handle);
                            wp_register_script($handle, $src, $file_data->deps, $file_data->ver);
                            wp_enqueue_script($handle);
                        }else{
                            wp_deregister_style($handle);
                            wp_register_style($handle, $src, $file_data->deps, $file_data->ver);
                            wp_enqueue_style($handle);
                        }
                    }
                }
            }
        }

        // save stats
        $this->system->update_cacheable_info($this->cacheable_files, $this->cacheable_urls);
    }

    /**
     * Single file minification
     * @param string $url               The URL of a single file to minify
     * @param boolean $file_is_remote
     * @return array($url, $file_size)
     */
    public function minify_file($url, $file_is_remote = false){
        if(empty($url)) return $url;

        $site_url = site_url();
        $remote_file = false;

        $path = str_replace($site_url . DIRECTORY_SEPARATOR, '', $url);
        $path = explode("?", $path);
        $query= $this->check_query_string($path);
        $path = $path[0];
        $file = basename($path);
        $extension = "." . pathinfo($path, PATHINFO_EXTENSION);
        $already_minified = (strpos($file, ".min") !== false);

        // extension blank or not accepted.  skip it
        if($extension === '.' || ! in_array($extension, array('.js', '.css', '.xml', '.php')))
            return array($url, 0);

        // this is a remote file.  prepare the path of the downloaded file
        if(strpos($url, $site_url) === false || $file_is_remote){
            $path = str_replace(array(ABSPATH, 'http://', 'https://'), '', $path) . $file;
            $remote_file = true;
        }

        // nginx fix for error 403 on PHP-prefixed combined files
        // disables gzip header in favour of using server conf
        if($this->system->is_server_nginx())
            $gzip = false;
        else
            $gzip = boolval($this->settings['page-caching']['gzip-compression']);

        $file_size = 0;
        if( (file_exists(ABSPATH . $path) || $remote_file) && ! empty($this->cache_dir)){
            // the file becomes .min.js (if not already minified)
            if($already_minified){
                $cached_file = pathinfo($file, PATHINFO_FILENAME) . $extension;
            }else{
                $cached_file = pathinfo($file, PATHINFO_FILENAME) . ".min" . $extension;
            }

            // prepare cached file path
            if(strpos($path, SMART_CACHE_CACHE_FOLDER) === false){
                // ensure the folder tree of the source file is replicated in the cache folder
                $rel_path = $this->system->build_cache_folder_tree($this->cache_dir, $path);
                $cached_path = $this->cache_dir . $rel_path . $cached_file;
            }else{
                $cached_path = $this->combine_dir . $cached_file;
            }

            // again, this is a remote file.  so download it to the remote folder now
            if($remote_file){
                // file is remote.  set the file timestamp to zero so it's not checked
                $file_timestamp = 0;
            }else{
                // get last modification time of raw file (false if file not found)
                $file_timestamp = @filemtime(ABSPATH . $path);
            }

            if(file_exists($cached_path)){
                // get age of latest version of minified file
                $cached_file_timestamp = @filemtime($cached_path);
            }else{
                // or set to zero if file can't be found -- age then will always be greater than limit
                $cached_file_timestamp = 0;
            }

            // get the minimum cache lifespan value. a value less than 60 seconds defaults to 1 hour
            $cached_file_life = time() - $cached_file_timestamp;

            // create a we21new cached file if the raw file is newer, or there is no cached file saved, or if the cached file is older than min cached lifespan
            if(! file_exists($cached_path) || $cached_file_timestamp < $file_timestamp || $cached_file_life > $this->settings['minify']['minify-expiry']){
                // delete old versions of file
                $similar_cached_files = str_replace($extension, "-*" . $extension, $file);
                $this->files_array = glob($this->cache_dir . $rel_path . $similar_cached_files);
                foreach($this->files_array as $old_file) unlink($old_file);

                $minify_atts = array(
                    'echo'              => false,
                    'encode'            => true,
                    'timer'             => false,
                    'gzip'              => $gzip,
                    'closure'           => false,
                    'include_fonts'     => false,
                    'expand_@imports'   => false,
                    'remove_comments'   => true,
                    'expiry_offset'     => intval($this->settings['minify']['minify-expiry'])
                );
                $minifier = new Minifier($minify_atts);

                // minify the single file
                if($remote_file){
                    list($cached_path, $file_size) = $minifier->minify($url, $cached_path);
                }else{
                    list($cached_path, $file_size) = $minifier->minify(ABSPATH . $path, $cached_path);
                }
            }
            $url = str_replace(ABSPATH, $site_url . DIRECTORY_SEPARATOR, $cached_path) . $query;
        }

        return array($url, $file_size);
    }

    /**
     * Enqueue a minified file
     * @param  string $stats_file_type
     * @param  string $handle
     * @param  string $orig_file_url
     * @param  string $mod_file_url
     * @param  array  $file_data_deps
     * @param  string $file_data_ver
     * @param  string $localize
     * @return array
     */
    public function enqueue_minified_file($stats_file_type, $handle, $orig_file_url, $mod_file_url, $file_data_deps = array(), $file_data_ver = null, $localize = null, $media = 'all'){
        $site_url = site_url();
        $file_is_to_be_excluded = false;
        $cacheable_file_info = null;

        // file is not the minified version
        if(strpos($orig_file_url, '.php') === false){
            // remove the ?ver= query if chosen
            if(boolval($this->settings['minify']['remove-query-strings'])) $file_data_ver = null;

            $src_file_path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $mod_file_url);

            $async = true;
            $defer = true;
            if(! is_admin() ){
                // update stats (non-admin files only)
                $cacheable_file_info = array(
                    'source' => $orig_file_url,
                    'source-size' => @filesize(str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $orig_file_url)),
                    'cached' => $mod_file_url,
                    'combined-into' => null
                );
            }

            // enqueue the file now
            if(file_exists($src_file_path)){
                $defer = $defer && boolval($this->settings['minify']['load-js-defer']);
                $async = $async && boolval($this->settings['minify']['load-js-async']);

                // re-register files as new minified versions
                if($stats_file_type == 'scripts'){
                    wp_deregister_script($handle);
                    wp_register_script($handle, $mod_file_url, $file_data_deps, $file_data_ver, $defer);
                    if(! empty($localize)){
                        // redo localization
                        if(preg_match('/var ([a-z0-9-_]+) = (.+)/i', $localize, $localize_parts)){
                            $localize_value = json_decode(stripslashes(trim($localize_parts[2], ';')), true);
                            if(!empty($localize_value)){
                                wp_localize_script($handle, $localize_parts[1], $localize_value);
                            }
                        }
                    }
                    wp_enqueue_script($handle);
                }else{
                    wp_deregister_style($handle);
                    wp_register_style($handle, $mod_file_url . (($async) ? '#asyncload' : ''), $file_data_deps, $file_data_ver, $media);
                    wp_enqueue_style($handle);
                }
            }else{
                $file_is_to_be_excluded = true;
            }
        }

        return array($file_is_to_be_excluded, $cacheable_file_info);
    }

    /*
     * Combination algorithms
     * ----------------------
     */

    /**
     * CSS combine bridge
     */
    public function combine_css_files(){
        $this->combine_fileset('.css');
    }

    /**
     * Javascript combine bridge
     */
    public function combine_js_files(){
        $this->combine_fileset('.js');
    }

    /**
     * Combine multiple files (minify -> combine)
     * @param string $extension
     */
    public function combine_fileset($extension){
        global $wp_scripts, $wp_styles, $wp;

        // reorder the handles based on its dependency
        // the result will be saved in the to_do property ($wp_scripts->queue)
        if($extension === '.js'){
            if (!$wp_scripts instanceof WP_Scripts) {
                $wp_scripts = new WP_Scripts();
            }
            $this->files = $wp_scripts;
            $stats_file_type = 'scripts';
        }else{
            if (!$wp_styles instanceof WP_Styles) {
                $wp_styles = new WP_Styles();
            }
            $this->files = $wp_styles;
            $stats_file_type = 'styles';
        }
        $this->files->all_deps($this->files->queue);

        // get cacheable files list
        if(empty($this->cacheable_files))
            $this->cacheable_files = $this->system->get_cacheable_files();
        if(empty($this->cacheable_urls))
            $this->cacheable_urls = $this->system->get_cacheable_urls();

        // temp file location
        $site_url = site_url();
        list($temp_combined_file_path, $combined_index) = $this->get_temp_combined_file_path($extension, $this->temp_file_stem);
        $file_size = 0;
        $replace_combined_file = true;

        // nginx fix for error 403 on PHP-prefixed combined files
        // disables gzip header in favour of using server conf
        if($this->system->is_server_nginx())
            $gzip = false;
        else
            $gzip = boolval($this->settings['page-caching']['gzip-compression']);

        // regenerate combined file
        $excluded_file_handles      = array();                       // files that are excluded from minification
        $dropped_file_handles       = array();                       // files to be dropped from loading
        $minified_file_handles      = array();                       // files that are minified
        $unminified_file_handles    = array();                       // files that will not be minified in the combiner
        $files_to_combine           = array();
        $localize                   = array();
        $deps                       = array();

        // jquery files
        $excluded_file_handles[] = "jquery";
        $excluded_file_handles[] = "jquery-core";
        // $excluded_file_handles[] = "jquery-ui";
        if(is_admin()) $excluded_file_handles[] = "common";

        // hook
        $updated_file_handles = apply_filters( 'smart_cache_combine_fileset', $excluded_file_handles );
        if(! empty($updated_file_handles)){
            if(is_array($updated_file_handles)){
                $excluded_file_handles = $updated_file_handles;
            }
        }

        foreach($this->files->queue as $handle){
            if(!isset($this->files->registered[$handle])) continue;
            $file_data = $this->files->registered[$handle];

            // remove query strings from url
            $src = strtok($file_data->src, '?');
            if(substr($src, 0, 1) == DIRECTORY_SEPARATOR && substr($src, 0, 2) != "//") $src = $site_url . $src;
            $src_file_path = null;
            $src_file_url = $src;
            $file_is_remote = false;

            // exclude WP core files from combining
            if((strpos($src, 'wp-includes') !== false || strpos($src, 'wp-admin') !== false) && ! boolval($this->settings['minify']['include-wp-core-files'])) {
                $excluded_file_handles[] = $handle;
            }

            // exclude file from minifying if it was deselected via tuning options
            if(isset($this->cacheable_files_tuning[$stats_file_type][$handle]['minify']) && $this->cacheable_files_tuning[$stats_file_type][$handle]['minify'] != 1) {
                $unminified_file_handles[] = $handle;
            }

            // exclude already processed files
            if(strpos($src_file_url, '.php')){
                $excluded_file_handles[] = $handle;
            }

            if(!in_array($handle, $excluded_file_handles) && !in_array($handle, $dropped_file_handles)){
                // if src does not include http or https, add it and continue
                if(strpos($src, 'http') === false){
                    if(is_SSL())
                        $src = preg_replace('/^\/\//', 'https://', $src);
                    else
                        $src = preg_replace('/^\/\//', 'http://', $src);
                }

                // to reduce HTTP requests, we are only dealing with local requests (same domain)
                if(strpos($src, $site_url) !== false){
                    // check for wp_localize_script
                    if(@array_key_exists('data', $file_data->extra)) {
                        $localize[$handle] = trim($file_data->extra['data'], ';');
                    }

                    // check for dependencies
                    if(isset($file_data->deps)) {
                        foreach($file_data->deps as $dep) $deps[] = $dep;
                    }

                    $src_file_path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $src_file_url);

                    // update stats
                    if(! is_admin()){
                        $this->cacheable_files[$stats_file_type][$handle] = array(
                            'source' => $src_file_url,
                            'source-size' => 0,
                            'cached' => null,
                            'combined-into' => str_replace(ABSPATH, $site_url . DIRECTORY_SEPARATOR, $temp_combined_file_path)
                        );
                    }
                    $minified_file_handles[] = $handle;
                }else{
                    // file is not in this domain
                    $file_is_remote = true;
                    $minified_file_handles[] = $handle;
                }
            }else{
                // Since file was either is dropped or excluded, do not combine it
                $src_file_url = null;
            }

            // Record file in files_to_merge array
            if(!empty($src_file_url)){
                if($file_is_remote){
                    $files_to_combine[] = $src_file_url;
                }elseif(file_exists($src_file_path)){
                    $files_to_combine[] = $src_file_path;
                }
            }

            // Add file to list of files encountered
            if(!in_array($handle, $this->files_encountered))
                $this->files_encountered[] = $handle;
        }

        // get live combined file url
        $files_to_combine = array_unique($files_to_combine);
        if(empty($files_to_combine)){
            // nothing to combine.  skip rest
            $temp_combined_file_path = false;
            $replace_combined_file = false;
        }else{
            $combined_file_path = $this->get_live_combined_file_path($files_to_combine, $temp_combined_file_path);

            // determine if live combined file needs to be refreshed
            if(file_exists($combined_file_path)){
                // get age of latest version of combined file
                $combined_file_timestamp = @filemtime($combined_file_path);
                $combined_file_life = time() - $combined_file_timestamp;

                if($combined_file_life < $this->settings['minify']['minify-expiry']){
                    // file has not expired.  do not continue combining
                    $replace_combined_file = false;
                }else{
                    // file has expired. delete all versions
                    $this->system->masked_unlink($combined_file_path);
                }
            }
        }

        if($replace_combined_file){
            // Merge files_to_merge fileset
            $minify_atts = array(
                'echo'              => false,
                'encode'            => true,
                'timer'             => false,
                'gzip'              => $gzip,
                'closure'           => false,
                'expand_@imports'   => false,
                'remove_comments'   => true,
                'expiry_offset'     => intval($this->settings['minify']['minify-expiry'])
            );
            $files_to_combine = array_unique($files_to_combine);
            $minifier = new Minifier($minify_atts);
            list($temp_combined_file_path, $file_size) = $minifier->merge($temp_combined_file_path, $extension, $files_to_combine);
        }

        if(false !== $temp_combined_file_path){
            // rename temp file to live file
            // this automatically removes the temp file
            if($replace_combined_file && file_exists($temp_combined_file_path))
                rename($temp_combined_file_path, $combined_file_path);

            // get URL of merged file
            $combined_file_url  = str_replace(ABSPATH, $site_url . DIRECTORY_SEPARATOR, $combined_file_path);

            $deps = array_filter(array_unique($deps));
            $deps = array_diff($deps, $dropped_file_handles);           // remove dependencies that were dropped
            $deps = array_intersect($deps, $excluded_file_handles);     // only depend on uncombined dependencies

            // Enqueue the URL of merged file
            $defer = (boolval($this->settings['minify']['load-js-defer']));
            $async = (boolval($this->settings['minify']['load-css-async']));
            if($stats_file_type == 'scripts'){
                $comb_handle = 'sc-combined-script-' . $combined_index;

                if(empty($localize)){
                    wp_enqueue_script($comb_handle, $combined_file_url, $deps, null, $defer);
                }else{
                    // redo localization
                    wp_register_script($comb_handle, $combined_file_url, $deps, null, $defer);
                    $localize = array_filter(array_unique($localize));
                    foreach($localize as $localize_string){
                        if(is_string($localize_string) && ! empty($localize_string)){
                            if(preg_match('/var ([a-z0-9-_]+) = (.+)/i', $localize_string, $localize_parts)){
                                $localize_value = json_decode(stripslashes(trim($localize_parts[2], ';')), true);
                                if(!empty($localize_value)){
                                    wp_localize_script($comb_handle, $localize_parts[1], $localize_value);
                                }
                            }
                        }
                    }
                    wp_enqueue_script($comb_handle);
                }
            }else{
                $comb_handle = 'sc-combined-style-' . $combined_index;
                wp_enqueue_style($comb_handle, $combined_file_url . (($async) ? '#asyncload' : ''), $deps, null);
            }

            // add combined file info to cacheable files array
            $this->cacheable_files[$stats_file_type][$comb_handle] = array(
                'source' => $combined_file_url,
                'source-size' => @filesize($combined_file_path),
                'cached' => $combined_file_url,
                'cached-size' => $file_size,
            );

            // deregister handles of merged or dropped files
            $this->files = (($stats_file_type == 'scripts') ? $wp_scripts : $wp_styles);
            foreach($this->files->queue as $handle){
                if(!isset($this->files->registered[$handle])) continue;
                $file_data = $this->files->registered[$handle];
                $src = $file_data->src;

                if(strpos($handle, 'sc-combined-') === false){
                    if(in_array($handle, $minified_file_handles) || in_array($handle, $dropped_file_handles)){
                        // file is one of the merged or dropped ones, deregister/dequeue it
                        if($stats_file_type == 'scripts'){
                            wp_deregister_script($handle);
                            wp_dequeue_script($handle);
                            $this->files->remove($handle);
                        }else{
                            wp_deregister_style($handle);
                            wp_dequeue_style($handle);
                            $this->files->remove($handle);
                        }
                    }else{
                        // file was skipped
                        if(!empty($file_data->deps)){
                            // it is dependent on something...
                            $change_deps = false;
                            $deps = $file_data->deps;
                            foreach($deps as $indx => $dep){
                                if(in_array($dep, $minified_file_handles)){
                                    // ... and is dependent on one of the newly merged files
                                    unset($deps[$indx]);
                                    $change_deps = true;
                                }
                            }
                            if($change_deps){
                                if($stats_file_type == 'scripts'){
                                    $deps[] = 'sc-combined-script-' . $combined_index;
                                    wp_deregister_script($handle);
                                    wp_register_script($handle, $src, $deps, $file_data->ver, true);
                                    wp_enqueue_script($handle);
                                }else{
                                    $deps[] = 'sc-combined-style-' . $combined_index;
                                    wp_deregister_style($handle);
                                    wp_register_style($handle, $src, $deps, $file_data->ver, 'all');
                                    wp_enqueue_style($handle);
                                }
                            }
                        }
                    }
                }
            }
        }

        // save stats
        $this->system->update_cacheable_info($this->cacheable_files, $this->cacheable_urls);
    }

    /**
     * Derive the path of the live/final combined file
     * @param array $files
     * @param string $temp_file_path
     * @return string
     */
    public function get_live_combined_file_path($files, $temp_file_path){
        $files_mash = join(" ", $files);
        $stem = md5($files_mash);
        $path = dirname($temp_file_path);
        $file = basename($temp_file_path);
        $file_parts = explode("-", $file);

        if(!empty($file_parts[1]))
            $file_location = $this->combine_dir . 'live-' . $stem . "-" . $file_parts[2] . '.php';
        else
            $file_location = false;

        return $file_location;
    }

    /**
     * Derive the path of the combined file
     * @param string $extension             .js or .css
     * @param string $stem
     * @return string
     */
    public function get_temp_combined_file_path($extension, $stem){
        // assume file does not exist
        if($extension == ".css"){
            $file_index = $this->combine_css_index;
            $this->combine_css_index++;
        }else{
            $file_index = $this->combine_js_index;
            $this->combine_js_index++;
        }
        $file_location = $this->combine_dir . $stem . "-" . strval($file_index) . $extension;

        return array($file_location, $file_index);
    }

    /**
     * Return the latest combined file path for the specified type
     * @param  string $file_type
     * @param  string $stem
     * @return string
     */
    public function get_latest_combined_file_path($file_type){
        if($file_type == 'script'){
            $file_index = $this->combine_js_index - 1;
            $extension = '.js';
        }else{
            $file_index = $this->combine_css_index - 1;
            $extension = '.css';
        }
        if(boolval($this->settings['page-caching']['gzip-compression'])) $extension .= '.php';
        $file_location = $this->combine_dir . $this->combine_file_stem . "-" . strval($file_index) . $extension;
        return $file_location;
    }

    /**
     * Create an empty "shell" file that will contain code from other files
     * @param  string $path
     * @param  string $extension
     * @return boolean
     */
    public function create_empty_file($path, $extension){
        $header = '';
        if(boolval($this->settings['page-caching']['gzip-compression'])){
            $offset = 60 * 60 * 24 * 31;
            $header = '<?php' . PHP_EOL;
            $header .= 'date_default_timezone_set(\'America/Los_Angeles\');' . PHP_EOL;
            $header .= 'if( extension_loaded( "zlib" ) ){' . PHP_EOL;
            $header .= '    ob_start( "ob_gzhandler" );' . PHP_EOL;
            $header .= '}else{' . PHP_EOL;
            $header .= '    ob_start();' . PHP_EOL;
            $header .= '}' . PHP_EOL;
            $header .= 'header( \'Content-Encoding: gzip\' );' . PHP_EOL;
            $header .= 'header( \'Cache-Control: max-age=' . $offset.'\' );' . PHP_EOL;
            $header .= 'header( \'Expires: ' . gmdate( "D, d M Y H:i:s", time() + $offset ) . ' GMT\' );' . PHP_EOL;
            $header .= 'header( \'Last-Modified: ' . gmdate( "D, d M Y H:i:s", filemtime( __FILE__ ) ) . ' GMT\' );' . PHP_EOL;

            //Add the header content type output for correct rendering
            if( $extension == 'css' || ( strpos( $path, '.css' ) !== false ) ){
                $header .= 'header( \'Content-type: text/css; charset: UTF-8\' );' . PHP_EOL;
            }

            if( $extension == 'js' || ( strpos( $path, '.js' ) !== false ) ){
                $header .= 'header( \'Content-type: application/javascript; charset: UTF-8\' );' . PHP_EOL;
            }
            $header .= '?>' . PHP_EOL;
        }

        $header .= '/* Overflow File Generated by ' . SMART_CACHE_PLUGIN_NAME_PROPER . ': ' . date('Y-m-d h:i:s A') . ' */';

        if($ok = $this->system->make_file($path, $header)){
            touch($path, $this->system->gmtime());
        }
        return $ok;
    }

    /**
     * Append file contents at $path to the overflow combined file
     * @param  string $path
     * @param  string $file_type
     * @return boolean
     */
    public function append_file_to_combined($path, $file_type){
        $ok = false;
        if(!empty($path)){
            $this->merged_last_file_path = $this->get_latest_combined_file_path($file_type);
            if(strpos($path, ABSPATH) === false) $path = ABSPATH . $path;
            if(file_exists($path) && file_exists($this->merged_last_file_path)){
                $contents = file_get_contents($path);
                $contents = PHP_EOL . '/* File: ' . $path . '*/' . $contents;
                $ok = (file_put_contents($this->merged_last_file_path, $contents, FILE_APPEND) !== false);
            }
        }
        if($ok){
            if($file_type == 'script')
                if(!defined('SMART_CACHE_OVERFLOW_SCRIPT_STARTED')) define('SMART_CACHE_OVERFLOW_SCRIPT_STARTED', true);
            else
                if(!defined('SMART_CACHE_OVERFLOW_STYLE_STARTED')) define('SMART_CACHE_OVERFLOW_STYLE_STARTED', true);
        }
        return $ok;
    }

    /*
     * Deferrment/Asynchronization algorithm
     * -------------------------------------
     */

    /**
     * Set a file handle for deferred loading
     * @param string $tag
     * @param string $handle
     */
    public function defer_files($tag, $handle){
        if(empty($tag)) return $tag;

        if(in_array($handle, array('jquery-core', 'jquery'))) return $tag;

        $site_url = site_url();
        if(strpos($tag, $site_url) === false) return $tag;

        preg_match("/(src|href)='([^']+)'/", $tag, $parts);
        if(count($parts) < 2) return $tag;

        $new_tag = preg_replace("/(src|href)='([^']+)'/", "$1='...' xtag='' handle='" . $handle . "'", $tag);
        $url = $parts[2];

        $path = str_replace($site_url . DIRECTORY_SEPARATOR, '', $url);
        $path = explode("?", $path);
        $path = $path[0];

        if(isset($this->settings['combine']['active']) && (boolean)$this->settings['combine']['active'] && isset($this->settings['combine']['combine-js']) && (boolean)($this->settings['combine']['combine-js']) && strpos($handle, 'combined') === false){
            // this file should have been combined already
            // since it hasn't, we will add it to the overflow combined file and nullify it
            if($this->append_file_to_combined($path, 'script'))
                $new_tag = null;
        }

        if(!empty($new_tag)){
            $can_minify = boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['minify-js']) && 'jquery-core' !== $handle;
            $can_defer = boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['load-js-defer']) && 'jquery-core' !== $handle;
            $can_async = boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['load-js-async']) && 'jquery-core' !== $handle;

            $extension = str_replace('.php', '', $path);
            $extension = substr($extension, strrpos($extension, '.'));
            $xtag = '';

            $defer_ok = false;
            if(boolval($this->settings['minify']['load-js-defer']) && ($extension == '.js' || $extension == '.min.js') && strpos($tag, 'defer') === false && $can_defer){
                $defer_ok = true;
            }
            if($defer_ok){
                $xtag .= " defer=\"defer\"";
            }

            $async_ok = false;
            if(boolval($this->settings['minify']['load-js-async']) && ($extension == '.js' || $extension == '.min.js') && strpos($tag, 'async') === false && $can_async){
                $async_ok = true;
            }
            if($async_ok){
                $xtag .= " async=\"async\"";
            }

            if(!empty($url)){
                $url = explode("?", $url);
                $url = $url[0] . $this->check_query_string($url);

                if(strpos($path, '.php') === false && $can_minify){
                    // file was not minified earlier
                    list($url, $file_size) = $this->minify_file($url);
                }

                $new_tag = str_replace("...", $url, $new_tag);
                $new_tag = str_replace("xtag=''", $xtag, $new_tag);
            }else{
                $new_tag = null;
            }
        }
        return $new_tag;
    }

    /**
     * Set a file handle for asyncronous loading
     * @param string $tag
     * @param string $handle
     */
    public function async_files($tag, $handle){
        if(empty($tag)) return $tag;

        $site_url = site_url();
        if(strpos($tag, $site_url) === false) return $tag;

        preg_match("/(src|href)='([^']+)'/", $tag, $parts);
        if(count($parts) < 3) return $tag;

        $new_tag = preg_replace("/(src|href)='([^']+)'/", "$1='...' xtag='' handle='" . $handle."'", $tag);
        $url = $parts[2];

        $path = str_replace($site_url . DIRECTORY_SEPARATOR, '', $url);
        $path = explode("?", $path);
        $path = $path[0];

        if(isset($this->settings['combine']['active']) && (boolean)$this->settings['combine']['active'] && isset($this->settings['combine']['combine-css']) && (boolean)($this->settings['combine']['combine-css']) && strpos($handle, 'combined') === false){
            // this file should have been combined already
            // since it hasn't, we will add it to the overflow combined file and nullify it
            if($this->append_file_to_combined($path, 'style'))
                $new_tag = null;
        }

        if(!empty($new_tag)){
            $can_minify = boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['minify-css']);
            $can_async = boolval($this->settings['minify']['active']) && boolval($this->settings['minify']['load-css-async']);

            $extension = str_replace('.php', '', $path);
            $extension = substr($extension, strrpos($extension, '.'));
            $xtag = '';

            $async_ok = false;
            if(boolval($this->settings['minify']['load-css-async']) && ($extension == '.css' || $extension == '.min.css') && strpos($tag, 'async') === false){
                $async_ok = true;
            }
            if($async_ok){
                $xtag .= " async=\"async\" media=\"none\" onload=\"if(media!='all')media='all'\"";
            }

            if(!empty($url)){
                $url = explode("?", $url);
                $url = $url[0] . $this->check_query_string($url);

                if(strpos($path, '.php') === false && $can_minify){
                    // file was not minified earlier
                    list($url, $file_size) = $this->minify_file($url);
                }

                $new_tag = str_replace("...", $url, $new_tag);
                $new_tag = str_replace("xtag=''", $xtag, $new_tag);
            }else{
                $new_tag = null;
            }
        }
        return $new_tag;
    }

    /**
     * Set a file enqueued late (such as after minification/combination) for deferred loading
     * @param string $url
     */
    public function late_process_file($url){
        if(strpos($url, '#deferload') !== false){
            if(!is_admin() && strpos($url, 'defer=') === false){
                $url = str_replace('#deferload', '', $url) . "' defer='defer";
            }else{
                $url = str_replace('#deferload', '', $url);
            }
        }
        if (strpos($url, '#asyncload') !== false){
            if(!is_admin() && strpos($url, 'async=') === false){
                $url = str_replace('#asyncload', '', $url) . "' async='async";
            }else{
                $url = str_replace('#asyncload', '', $url);
            }
        }
        return $url;
    }

    /**
     * Return true if each of a set of settings tests are true
     * @param  array  $test
     * @return boolean
     */
    public function is_set($test){
        $ok = true;
        if(in_array('minify-active', $test))
            $ok = $ok && boolval($this->setting['minify']['active']);
        if(in_array('combine-active', $test))
            $ok = $ok && boolval($this->setting['combine']['active']);
        if(in_array('page-caching-active', $test))
            $ok = $ok && boolval($this->setting['page-caching']['active']);
        if(in_array('browser-caching-active', $test))
            $ok = $ok && boolval($this->setting['browser-caching']['active']);
        if(in_array('cdn-active', $test))
            $ok = $ok && boolval($this->setting['cdn']['active']);
        if(in_array('minify-js', $test))
            $ok = $ok && boolval($this->setting['minify']['minify-js']);
        if(in_array('minify-css', $test))
            $ok = $ok && boolval($this->setting['minify']['minify-css']);
        if(in_array('exclude-jquery-migrate', $test))
            $ok = $ok && boolval($this->setting['minify']['exclude-jquery-migrate']);
        if(in_array('load-js-defer', $test))
            $ok = $ok && boolval($this->setting['minify']['load-js-defer']);
        if(in_array('load-js-async', $test))
            $ok = $ok && boolval($this->setting['minify']['load-js-async']);
        if(in_array('load-css-defer', $test))
            $ok = $ok && boolval($this->setting['minify']['load-css-defer']);
        if(in_array('include-wp-core-files', $test))
            $ok = $ok && boolval($this->setting['minify']['include-wp-core-files']);
        if(in_array('extract-@import', $test))
            $ok = $ok && boolval($this->setting['minify']['extract-@import']);
        if(in_array('gzip-compression', $test))
            $ok = $ok && boolval($this->setting['page-caching']['gzip-compression']);
        if(in_array('exclude-front-page', $test))
            $ok = $ok && boolval($this->setting['page-caching']['exclude-front-page']);
        if(in_array('include-custom-files', $test))
            $ok = $ok && boolval($this->setting['cdn']['include-custom-files']);
        if(in_array('include-theme-files', $test))
            $ok = $ok && boolval($this->setting['cdn']['include-theme-files']);
        if(in_array('include-minified-files', $test))
            $ok = $ok && boolval($this->setting['cdn']['include-minified-files']);

        return $ok;
    }

    public function test(){
        return true;
    }
}