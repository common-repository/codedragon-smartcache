<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * A service class that handles system requests
 *
 * @package    Smart_Cache
 * @subpackage Smart_Cache/includes
 * @author     Dragon Slayer <info@codedragon.ca>
 */

defined('ABSPATH') or die('Nice try...');

if(!defined('SMART_CACHE_MEMCACHE_PORT')) define('SMART_CACHE_MEMCACHE_PORT', 11211);
if(!defined('SMART_CACHE_CURL_METHOD_GET')) define('SMART_CACHE_CURL_METHOD_GET', 'GET');
if(!defined('SMART_CACHE_CURL_METHOD_POST')) define('SMART_CACHE_CURL_METHOD_POST', 'POST');
if(!defined('SMART_CACHE_NGINX_CONF')) define('SMART_CACHE_NGINX_CONF', 'smartcacheopt.conf');

// require_once( ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php' );
// require_once( ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php' );

class Smart_Cache_System {
    /*
     * General properties
     */
    private $cacheable_urls = array();
    private $cacheable_files = array();
    private $cacheable_files_tuning = array();
    private $base;

    /*
     * Background file worker
     */
    private $file_worker;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct($plugin_file_name, $base) {
        $this->base = $base;
    }

    /**
     * Self check for filesystem
     * @param array $_notices
     * @param array $_errors
     */
    public function self_check(&$_notices, &$_errors){
        $notices = array();
        $errors = array();

        if(is_admin()){
            if($this->is_server_nginx()){
                // NGINX
                if($this->mod_rewrites_need_saving()){
                    // NGINX optimizations are prepared in a special conf file saved in the domain root
                    if($this->conf_is_writable()){
                        // Update/create the conf file now
                        if($this->update_conf() == 'created'){
                            $errors[] = 'The optimization file, ' . ABSPATH . SMART_CACHE_NGINX_CONF . ', has been created.  Please visit the <a href="' . get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME . '-reports">Reports</a> page for instructions on how to include this file in your NGINX configuration.';
                        }else{
                            $errors[] = 'The optimization file, ' . ABSPATH . SMART_CACHE_NGINX_CONF . ', has been updated.  Please restart your server to effect these changes.';
                        }
                    }else{
                        $errors[] = 'You have made changes to the <strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . '</strong> settings or have just activated it.  Since this site is on an <strong>NGINX server</strong> a special configuration file, ' .SMART_CACHE_NGINX_CONF . ', is automatically maintained by the plugin.  Since this file is not writable, either because of permissions to it or the root folder, you will need to make the updates manually.  Please visit the <a href="' . get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME . '-reports">Reports</a> page for further instructions.';
                    }
                }
            }else{
                // Apache/Litespeed
                if($this->mod_rewrites_need_saving()){
                    // the rewrites have changed and need to be saved
                    if($this->htaccess_is_writable()){
                        // Update .htaccess with the new mod_rewrite settings
                        $this->update_htaccess();
                    }else{
                        $errors[] = 'It looks like your <code>.htaccess</code> file is not writable (<em>' . ABSPATH . '.htaccess</em>).  If you temporarily make it writable, <strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . '</strong> will be able to make speed-enhancing updates to it.  If you prefer to adjust them yourself, visit the <a href="' . get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME . '-reports">Reports</a> page.';
                    }
                }
            }

            if(!empty($notices)) $_notices = array_merge($_notices, $notices);
            if(!empty($errors)) $_errors = array_merge($_errors, $errors);
        }
    }

    /**
     * Get the current page URL
     * @param  boolean $keep_query
     * @return string
     */
    public function get_current_page_url( $keep_query = false, $exclude_query = array() ){
        $p = trim(get_pagenum_link(get_query_var('paged')), DIRECTORY_SEPARATOR);
        if(! $keep_query) {
            $p = explode("?", $p);
            $p = $p[0];
        }elseif(! empty($exclude_query) && is_array($exclude_query)){
            $p = explode("?", $p);
            if(count($p) > 1){
                parse_str($p[1], $q);
                foreach($q as $qk => $qv){
                    unset($q[$qk]);
                    $qk = str_replace("#038;", "", $qk);
                    if(! in_array($qk, $exclude_query)) $q[$qk] = $qv;
                }
                $p = $p[0] . "?" . http_build_query($q);
                $p = trim($p, "?");
            }else{
                $p = $p[0];
            }
        }
        return $p;
    }

    /**
     * Return the value of a $_GET variable
     * @param  string $var
     * @return string|null
     */
    public function get_page_query($var){
        $val = null;
        if(!empty($_GET[$var])) $val = $_GET[$var];
        return $val;
    }

    /**
     * Return whether or not cURL functions are active in this version of PHP
     * @return boolean
     */
    public function is_curl_active(){
        if(!extension_loaded('curl') && !@dl(PHP_SHLIB_SUFFIX == 'so' ? 'curl.so' : 'php_curl.dll'))
            return false;
        else
            if(!function_exists("curl_init") &&
              !function_exists("curl_setopt") &&
              !function_exists("curl_exec") &&
              !function_exists("curl_close")){
                return false;
            }else{
                return true;
            }
    }

    /**
     * Send a simple CURL request
     * @param  string $url
     * @param  string $method      GET, POST
     * @param  string|array $data
     * @param  array $header
     * @return string
     */
    public function do_simple_curl($url, $method = SMART_CACHE_CURL_METHOD_GET, $data = null, $header = array()){
        $retn = false;
        if(!empty($url) && is_string($url)){
            if($method == SMART_CACHE_CURL_METHOD_GET){
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                if(!empty(SMART_CACHE_HOME_AUTH)) curl_setopt($ch, CURLOPT_USERPWD, SMART_CACHE_HOME_AUTH);
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
                $response = curl_exec($ch);
                curl_close($ch);

                $retn = $response;
            }elseif($method == SMART_CACHE_CURL_METHOD_POST && !empty($data)){
                $data_str = $data;
                if(is_array($data))
                    $data_str = json_encode($data);
                $header_add = array(
                    'Content-Type' => 'application/json',
                    'Content-Length' => strlen($data_str),
                    'Expect' => ''
                );
                if(is_array($header) && !empty($header))
                    $header = array_merge($header, $header_add);
                else
                    $header = $header_add;
                $options = array(
                    CURLOPT_HTTPHEADER => $header,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_POST => true,
                    CURLOPT_URL => $url,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_POSTFIELDS => $data,
                );
                if(!empty(SMART_CACHE_HOME_AUTH)) $options[CURLOPT_USERPWD] = SMART_CACHE_HOME_AUTH;
                $ch = curl_init();
                curl_setopt_array($ch, $options);
                $response = curl_exec($ch);
                curl_close($ch);

                $retn = $response;
            }
        }
        return $retn;
    }

    /**
     * Return if .htaccess is writable
     * @return boolean
     */
    public function htaccess_is_writable(){
        return is_writable(ABSPATH . ".htaccess");
    }

    /**
     * Return if smartcacheopt.conf is writable or has not yet been created
     * @return boolean
     */
    public function conf_is_writable(){
        return (is_writable(ABSPATH . SMART_CACHE_NGINX_CONF) || !file_exists(ABSPATH . SMART_CACHE_NGINX_CONF));
    }

    /**
     * Return whether the mod-rewrites-saved record has been set
     * @return boolean
     */
    public function mod_rewrites_need_saving(){
        $up_to_date = false;

        // check if the required changes were made to .htaccess
        if($this->is_server_nginx()){
            if(file_exists(ABSPATH . SMART_CACHE_NGINX_CONF)){
                // if conf file contents are same as prepared contents settings are up-to-date
                $file_contents = @file_get_contents(ABSPATH . SMART_CACHE_NGINX_CONF);
                $opt_contents = $this->prepare_conf();
                $up_to_date = (trim($opt_contents) == trim($file_contents));
            }
        }else{
            if(file_exists(ABSPATH . ".htaccess")){
                $file_contents = @file_get_contents(ABSPATH . ".htaccess");
                if(preg_match('/\# BEGIN ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization(.*)\# END ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization/sm', $file_contents, $matches)){
                    // mod_rewrites were saved before, check if they have changed
                    $opt_contents = $this->prepare_htaccess();
                    $up_to_date = (trim($opt_contents) == trim($matches[1]));
                }
            }
        }

        // if up-to-date, set the saved flag to indicate that the settings were saved
        if($up_to_date)
            update_site_option(SMART_CACHE_PLUGIN_NAME . '-mod-rewrites-saved', true);

        $was_saved = get_site_option(SMART_CACHE_PLUGIN_NAME . '-mod-rewrites-saved', null);
        return (empty($was_saved) || !$up_to_date);
    }

    /**
     * Prepare optimization mod rewrites
     * @return  string
     */
    public function prepare_htaccess(){
        $settings = $this->base->get_settings(SMART_CACHE_PLUGIN_NAME . "-settings");
        $ver = SMART_CACHE_VER;

        $gzip_settings = null;
        $vary_encoding_settings = null;
        $etag_settings = null;

        if(boolval($settings['page-caching']['gzip-compression'])){
            $gzip_settings = <<<EOD

# Gzip Compression (legacy, Apache 1.3)
<IfModule mod_gzip.c>
    mod_gzip_on           Yes
    mod_gzip_dechunk      Yes
    mod_gzip_item_include file \.(html?|xml|txt|css|js)$
    mod_gzip_item_include handler ^cgi-script$
    mod_gzip_item_include mime ^text/.*
    mod_gzip_item_include mime ^application/x-javascript.*
    mod_gzip_item_exclude mime ^image/.*
    mod_gzip_item_exclude rspheader ^Content-Encoding:.*gzip.*
</IfModule>
# END Gzip Compression

# Deflate
<IfModule mod_deflate.c>
    SetOutputFilter DEFLATE

    <IfModule mod_setenvif.c>
        # Remove browser bugs (only needed for really old browsers)
        BrowserMatch ^Mozilla/4 gzip-only-text/html
        BrowserMatch ^Mozilla/4\.0[678] no-gzip
        BrowserMatch \bMSIE !no-gzip !gzip-only-text/html
        BrowserMatch \bMSI[E] !no-gzip !gzip-only-text/html

        <IfModule mod_headers.c>
            Header append Vary User-Agent env=!dont-vary
            SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$ ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding
            RequestHeader append Accept-Encoding "gzip,deflate" env=HAVE_Accept-Encoding
            {$vary_encoding_settings}

            # Don't compress image files
            SetEnvIfNoCase Request_URI \
                \.(?:gif|jpe?g|png|rar|zip|exe|flv|mov|wma|mp3|avi|swf|mp?g|mp4|webm|webp)$ no-gzip dont-vary
        </IfModule>
    </IfModule>

    <IfModule mod_filter.c>
        AddOutputFilterByType DEFLATE "application/atom+xml" \
                                      "application/javascript" \
                                      "application/json" \
                                      "application/ld+json" \
                                      "application/manifest+json" \
                                      "application/rdf+xml" \
                                      "application/rss+xml" \
                                      "application/schema+json" \
                                      "application/vnd.geo+json" \
                                      "application/vnd.ms-fontobject" \
                                      "application/x-font-ttf" \
                                      "application/x-javascript" \
                                      "application/x-web-app-manifest+json" \
                                      "application/xhtml+xml" \
                                      "application/xml" \
                                      "font/eot" \
                                      "font/opentype" \
                                      "image/bmp" \
                                      "image/svg+xml" \
                                      "image/vnd.microsoft.icon" \
                                      "image/x-icon" \
                                      "text/cache-manifest" \
                                      "text/css" \
                                      "text/html" \
                                      "text/javascript" \
                                      "text/plain" \
                                      "text/vcard" \
                                      "text/vnd.rim.location.xloc" \
                                      "text/vtt" \
                                      "text/x-component" \
                                      "text/x-cross-domain-policy" \
                                      "text/xml"

    </IfModule>

    <IfModule mod_mime.c>
        AddEncoding gzip              svgz
        AddOutputFilter               DEFLATE js css htm html xml
    </IfModule>
</IfModule>
# END Deflate
EOD;
        }

        $content = <<<EOD
\n# Send CORS headers if requested
<IfModule mod_setenvif.c>
    <IfModule mod_headers.c>
        # Mod_headers
        <FilesMatch "\.(cur|gif|png|jpe?g|svgz?|ico|webp)$">
            SetEnvIf Origin ":" IS_CORS
            Header set Access-Control-Allow-Origin "*" env=IS_CORS
        </FilesMatch>
    </IfModule>
</IfModule>
# END CORS headers

# Provide access to webfonts
<FilesMatch "\.(eot|otf|tt[cf]|woff2?)$">
    <IfModule mod_headers.c>
        Header set Access-Control-Allow-Origin "*"
    </IfModule>
</FilesMatch>
# END webfonts

# Public cache control for aliased file types
<IfModule mod_alias.c>
    <FilesMatch "\.(css|htc|js|asf|asx|wax|wmv|wmx|avi|bmp|class|divx|doc|docx|eot|exe|gif|gz|gzip|ico|jpg|jpeg|jpe|json|mdb|mid|midi|mov|qt|mp3|m4a|mp4|m4v|mpeg|mpg|mpe|mpp|otf|odb|odc|odf|odg|odp|ods|odt|ogg|pdf|png|pot|pps|ppt|pptx|ra|ram|svg|svgz|swf|tar|tif|tiff|ttf|ttc|wav|wma|wri|xla|xls|xlsx|xlt|xlw|zip)$">
        <IfModule mod_headers.c>
            Header unset Pragma
            Header append Cache-Control "public"
        </IfModule>
    </FilesMatch>
</IfModule>
# END Alias cache control

# Cache Control
<IfModule mod_headers.c>
    Header set Connection keep-alive
</IfModule>
# END Cache Control
{$etag_settings}
{$gzip_settings}

# PHP No-Cache
<FilesMatch "\.(php)$">
    <IfModule mod_expires.c>
        ExpiresActive Off
    </IfModule>
    <IfModule mod_headers.c>
        Header set Cache-Control "private, no-cache, no-store, proxy-revalidate, no-transform"
    </IfModule>
</FilesMatch>
# END PHP No-Cache\n
EOD;

        return $content;
    }

    /**
     * Prepare optimization mod rewrites
     * @return  string
     */
    public function prepare_conf(){
        $settings = $this->base->get_settings(SMART_CACHE_PLUGIN_NAME . "-settings");
        $ver = SMART_CACHE_VER;
        $created_date = date("M j, Y");
        $site = site_url();

        $gzip_settings = null;
        $vary_encoding_settings = null;
        $etag_settings = null;

        if(boolval($settings['page-caching']['gzip-compression'])){
            $gzip_settings = <<<EOD

# GZip compression
gzip on;
gzip_min_length 20;
gzip_buffers 4 32k;
gzip_comp_level 6;
gzip_proxied expired no-cache no-store private auth;
gzip_types
    text/plain
    text/css
    text/js
    text/javascript
    text/x-component
    application/javascript
    application/x-javascript
    application/xml
    application/json
    application/atom+xml
    application/rss+xml
    application/vnd.ms-fontobject
    application/x-font-ttf
    application/x-web-app-manifest+json
    application/xhtml+xml
    font/opentype
    image/svg+xml
    image/x-icon;
gzip_disable "MSIE [1-6]\.(?!.*SV1)";
EOD;
        }

        $content = <<<EOT
# SmartCache {$ver} NGINX Configuration File
#
# Created: {$created_date}
# {$site}

# Uncomment following two lines if using proxy cache
#fastcgi_cache_path /tmp/cache levels=1:2 keys_zone=smartcache:100m inactive=60m;
#fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";

set \$cache_uri \$request_uri;
set \$skip_cache 0;

add_header X-Smart-Cache \$upstream_cache_status;

# POST requests and URLs with a query string should always go to PHP
if (\$request_method = POST) {
    set \$skip_cache 1;
    set \$cache_uri "null cache";
}

# Queries
if (\$query_string != "") {
    set \$skip_cache 1;
    set \$cache_uri "null cache";
}

# Favicons
location ~ favicon.ico {
    log_not_found off;
    access_log off;
}

# robots.txt
location ~ robots.txt {
    log_not_found off;
    access_log off;
}

# PHP
#location ~ \.php\$ {
    #fastcgi_cache smartcache; # Uncomment for proxy cache
    #fastcgi_cache_methods GET HEAD;
    #add_header X-Fastcgi-Cache \$upstream_cache_status;
    #fastcgi_cache_bypass \$skip_cache;
    #fastcgi_no_cache \$skip_cache;

    # PHP-FPM
        #fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        #fastcgi_pass unix:/var/run/php/php7.0-fpm.sock;
#}
{$gzip_settings}
EOT;

        return $content;
    }

    /**
     * Update the .htaccess file with SmartCache settings
     * @return  boolean
     */
    public function update_htaccess(){
        if(!function_exists('insert_with_markers')){
            require_once(ABSPATH . 'wp-admin/includes/misc.php');
        }

        // Get path to main .htaccess for WordPress
        $htaccess = ABSPATH . ".htaccess";

        // get new content
        $content = $this->prepare_htaccess();

        $file_contents = @file_get_contents($htaccess);
        if(0 === preg_match('/\# BEGIN ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization(.*)\# END ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization/sm', $file_contents, $matches)){
            // append .htaccess if content is not in file
            $contents = '# BEGIN ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization' . PHP_EOL . $content . PHP_EOL . '# END ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Optimization' . PHP_EOL;
            $file_contents = $contents . PHP_EOL . $file_contents;
            file_put_contents($htaccess, $file_contents);
            do_action(SMART_CACHE_PLUGIN_NAME . '-after-htaccess-mod');
        }else{
            // update content if it is in it
            $content_lines = explode("\n", $content);
            insert_with_markers($htaccess, SMART_CACHE_PLUGIN_NAME_PROPER . " Optimization", $content_lines);
            do_action(SMART_CACHE_PLUGIN_NAME . '-after-htaccess-mod');
        }

        // sets an indicator to tell system to save rewrites later
        update_site_option(SMART_CACHE_PLUGIN_NAME . '-mod-rewrites-saved', true);
        return true;
    }

    /**
     * Update the smartcacheopt.conf file with SmartCache settings
     * @return  boolean
     */
    public function update_conf(){
        $conf = ABSPATH . SMART_CACHE_NGINX_CONF;
        $exists = file_exists($conf);

        $content = $this->prepare_conf();
        if(file_put_contents($conf, $content)){
            $retn = (($exists) ? 'updated' : 'created');
        }else{
            $retn = 'error';
        }

        // sets an indicator to tell system to save rewrites later
        update_site_option(SMART_CACHE_PLUGIN_NAME . '-mod-rewrites-saved', true);
        return $retn;
    }

    /**
     * Return a text value for server type
     * @return string
     */
    public function get_server_type(){
        $server = '';
        if($this->is_server_apache()){
            $server = 'Apache';
        }elseif($this->is_server_litespeed()){
            $server = 'Litespeed';
        }elseif($this->is_server_nginx()){
            $server = 'NGINX';
        }elseif($this->is_server_iis()){
            $server = 'IIS';
        }
        return $server;
    }

    /**
     * Return true if server is Apache
     * @return boolean
     */
    public function is_server_apache(){
        if(empty($_SERVER['SERVER_SOFTWARE']))
            return true;
        else
            return (stripos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false);
    }

    /**
     * Return true if server is Litespeed
     * @return boolean
     */
    public function is_server_litespeed(){
        return (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'Litespeed') !== false);
    }

    /**
     * Return true if server is Nginx
     * @return boolean
     */
    public function is_server_nginx(){
        // return true;
        return (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'Nginx') !== false);
    }

    /**
     * Return true if server is IIS
     * @return boolean
     */
    public function is_server_iis(){
        return (isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'IIS') !== false);
    }

    /**
     * Return if GZip is active on server
     * @return boolean
     */
    public function is_gzip_active(){
        return (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false);
    }

    /**
     * Return if GZip is active in PHP
     * @return boolean
     */
    public function is_gzip_php_active(){
        return (function_exists('ob_gzhandler') && ini_get('zlib.output_compression'));
    }

    /**
     * Save script/stylesheet file URLs to database
     */
    public function record_files(){
        global $wp_scripts, $wp_styles, $wp;

        if(is_admin()) return false;

        $this->page_url = $this->get_current_page_url();
        if(strpos($this->page_url, 'admin-ajax') !== false) return false;

        // get the previously recorded cacheable files and urls
        $this->cacheable_files = $this->get_cacheable_files();
        $this->cacheable_urls = $this->get_cacheable_urls();
        $site_url = site_url();

        if(!in_array($this->page_url, $this->cacheable_urls)){

            // this URL was not tapped... gather its scripts and stylesheets
            $this->cacheable_urls[] = $this->page_url;
            sort($this->cacheable_urls);
        }

        $scripts = $wp_scripts;
        $scripts->all_deps($scripts->queue);
        $js_files = $this->get_cacheable_file_handle_paths($scripts->registered, "js");

        $styles = $wp_styles;
        $styles->all_deps($styles->queue);
        $css_files = $this->get_cacheable_file_handle_paths($styles->registered, "css");

        if(!isset($this->cacheable_files['scripts'])) $this->cacheable_files['scripts'] = array();
        foreach($js_files as $handle => $file_data){
            if(strpos($handle, SMART_CACHE_MINIFY_TAG) === false){
                $file_path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $file_data);
                $file_size = (strpos($file_path, "//") === false) ? @filesize($file_path) : 0;

                $ok_to_add = false;
                if(!isset($this->cacheable_files['scripts'][$handle])){
                    $ok_to_add = true;      // file is not in array
                }elseif(isset($this->cacheable_files['scripts'][$handle]['source'])){
                    if($this->cacheable_files['scripts'][$handle]['source'] != $file_data){
                        $ok_to_add = true;      // file has a different source
                    }elseif($this->cacheable_files['scripts'][$handle]['source-size'] != $file_size){
                        $ok_to_add = true;      // file size has changed
                    }
                }

                if($ok_to_add) {
                    // not in files list, add it
                    $this->cacheable_files['scripts'][$handle] = array(
                        'source' => $file_data,
                        'source-size' => $file_size,
                        'cached' => null,
                        'combined-into' => null
                    );
                }
            }
        }

        if(!isset($this->cacheable_files['styles'])) $this->cacheable_files['styles'] = array();
        foreach($css_files as $handle => $file_data){
            if(strpos($handle, SMART_CACHE_MINIFY_TAG) === false){
                $file_path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $file_data);
                $file_size = (strpos($file_path, "//") === false) ? @filesize($file_path) : 0;

                $ok_to_add = false;
                if(!isset($this->cacheable_files['styles'][$handle])){
                    $ok_to_add = true;      // file is not in array
                }elseif(isset($this->cacheable_files['styles'][$handle]['source'])){
                    if($this->cacheable_files['styles'][$handle]['source'] != $file_data){
                        $ok_to_add = true;      // file has a different source
                    }elseif($this->cacheable_files['styles'][$handle]['source-size'] != $file_size){
                        $ok_to_add = true;      // file size has changed
                    }
                }


                if($ok_to_add) {
                    // not in files list, add it
                    $this->cacheable_files['styles'][$handle] = array(
                        'source' => $file_data,
                        'source-size' => $file_size,
                        'cached' => null,
                        'combined-into' => null
                    );
                }
            }
        }

        // save the updated cacheable files and urls back to the database
        $this->update_cacheable_info($this->cacheable_files, $this->cacheable_urls);
    }

    /**
     * Retrieve the cacheable urls list from the wp_options table
     * @return array
     */
    public function get_cacheable_urls(){
        $data = get_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-urls", array());
        if(!is_array($data)) $data = array();
        return $data;
    }

    /**
     * Retrieve the cacheable files list from the wp_options table
     * @return array
     */
    public function get_cacheable_files(){
        $data = get_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files", array("scripts" => array(), "styles" => array()));
        if(!is_array($data)) $data = array();
        return $data;
    }

    /**
     * Retrieve the cacheable files tuning list from the wp_options table
     * @return array
     */
    public function get_cacheable_files_tuning(){
        return array();
    }

    /**
     * Save cacheable file information
     * @param  array $handles
     * @param  string $extension
     * @return boolean
     */
    public function get_cacheable_file_handle_paths($handles, $extension){
        $site_url = site_url();

        $retn = array();
        foreach($handles as $handle => $file_data){
            $src = strtok($file_data->src, '?');
            if(substr($src, 0, 1) == DIRECTORY_SEPARATOR) $src = $site_url . $src;

            if(strpos($src, 'http') !== false){
                $retn[$handle] = $src;
            }
        }
        return $retn;
    }

    /**
     * Save cacheable info array back to database
     */
    public function update_cacheable_info($files = null, $urls = null, $tuning = null){
        $the_files = array();
        $the_urls = array();

        // update properties with provided parameters
        if(! empty($files) && is_array($files)) $the_files = $files;
        if(! empty($urls) && is_array($urls)) $the_urls = $urls;

        // retrieve missing properties
        if(empty($the_files)) $the_files = $this->get_cacheable_files();
        if(empty($the_urls)) $the_urls = $this->get_cacheable_urls();

        // save properties
        update_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-urls", $the_urls);
        update_site_option(SMART_CACHE_PLUGIN_NAME . "-cacheable-files", $the_files);
    }

    /**
     * Return the file contents with relative resource paths prepended with the absolute path to the source file
     * @param string $contents        Contents
     * @param string $path               Source file path
     * @return string
     */
    public function prefix_css_file_path($contents, $src_path){
        $src_path = dirname($src_path);

        $contents = preg_replace('#url\((?!\s*([\'"]?(((?:https?:)?\/\/)|(?:data\:?:))))\s*([\'"])?([\.\/])*#i', 'url($6' . $src_path, $contents);
        return $contents;
    }

    /**
     * Build a folder tree based on the structure of a path
     * @param string $path
     */
    public function build_cache_folder_tree($cache_dir, $path){
        $path = dirname($path);
        $folders = explode(DIRECTORY_SEPARATOR, $path);
        $dir = $cache_dir;
        foreach($folders as $folder){
            $dir .= $folder . DIRECTORY_SEPARATOR;
            if(!file_exists($dir)){
                mkdir($dir);
                chmod($dir, 0755);
            }else{
                chmod($dir, 0755);
            }
        }
        $dir = str_replace($cache_dir, '', $dir);
        return $dir;
    }

    /**
     * WP version of file_put_contents
     * @param  string $file
     * @param  string $contents
     * @return boolean
     */
    public function put_file_contents($file, $contents){
        $chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
        return $this->put_contents( $file, $contents, $chmod );
    }

    /**
     * Return if resource (file or directory) is writable
     * @param  string  $path
     * @return boolean
     */
    public function is_writable($path) {
        if (substr($path, -1, 1) == DIRECTORY_SEPARATOR) {
            if (file_exists($path)) {
                $path .= uniqid(mt_rand()) . '.tmp';
                if (!($f = @fopen($path, 'w')))
                    return false;
                fclose($f);
                unlink($path);
                return true;
            }
            return false;
        }

        if (file_exists($path)) {
            if (!($f = @fopen($path, 'r+')))
                return false;
            fclose($f);
            return true;
        }

        if (!($f = @fopen($path, 'w')))
            return false;
        fclose($f);
        unlink($path);
        return true;
    }

    /**
     * Save contents to file
     * @param  string $path
     * @param  string $contents [description]
     * @return boolean
     */
    public function make_file($path, $contents){
        $ok = false;
        if(!empty($path)){
            //If the file already exists, open it and empty it
            if( file_exists( $path ) && is_writeable( $path ) ){
                $f = fopen( $path, 'w' );
                fclose( $f );
            }

            //Create the new file
            $handle = fopen( $path, 'w' );
            fwrite( $handle, $contents );
            fclose( $handle );
            $ok = true;
        }
        return $ok;
    }

    /**
     * WP version of mkdir
     * @param  string $dir
     * @return boolean
     */
    public function make_dir( $dir ){
        $chmod = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : ( fileperms( WP_CONTENT_DIR ) & 0777 | 0755 );
        return $this->mkdir( $dir, $chmod );
    }

    /**
     * Delete a file or files based on filemask
     * @param  string $filemask
     */
    public function masked_unlink($filemask){
        if(!empty($filemask) && $filemask != DIRECTORY_SEPARATOR){
            if(strpos($filemask, '*') !== false || strpos($filemask, '?') !== false)
                array_map( "unlink", glob( $filemask ) );
            else
                unlink($filemask);
        }
    }

    /**
     * Delete contents matching pattern from folder and all subfolders
     * @param  string $dir
     * @param  string $pattern
     */
    public function recursive_unlink($dir, $pattern = "*") {
        // find all files and folders matching pattern
        $files = glob($dir . "/$pattern");

        //interate thorugh the files and folders
        foreach($files as $file){
            //if it is a directory then re-call recursive_unlink function to delete files inside this directory
            if(!is_link($file)){
                if (is_dir($file) && !in_array($file, array('..', '.')))  {
                    $this->recursive_unlink($file, $pattern);
                    //remove the directory itself
                    rmdir($file);
                } elseif(is_file($file) && ($file != __FILE__)) {
                    // make sure you don't delete the current script
                    unlink($file);
                }
            }
        }
    }

    /**
     * Download remote file contents and save to a local path
     * @param  string $url
     * @param  string $path
     * @return boolean
     */
    public function download_remote_file($url, $path){
        $ok = false;
        $site_url = site_url();
        if(!empty($url) && !empty($path) && strpos($path, ABSPATH) !== false){
            $contents = file_get_contents($url, false, null);
            if(!empty($contents)){
                try{
                    @file_put_contents($path, $contents);
                    $ok = true;
                }catch (Exception $e){
                }
            }
        }
        return $ok;
    }

    /**
     * Internal function to output everything as a gmdate
     * Prevents issues with servertime vs. PHP time settings by
     * turning all timestamps into gmt
     * @param int time
     * @return int
     */
    public function gmtime($time = ''){
        $time = !empty($time) ? $time : time();
        return gmdate('U', $time);
    }

    /**
     * Using the WP_Dependencies API, scan_files analyses each registered file
     * to determine sizes and prepare minified files
     * @return boolean
     */
    public function scan_files_regdep(){
        global $wp_scripts, $wp_styles;

        if (!$wp_scripts instanceof WP_Scripts) {
            $wp_scripts = new WP_Scripts();
        }
        if (!$wp_styles instanceof WP_Styles) {
            $wp_styles = new WP_Styles();
        }

        $cacheable_files = $this->get_cacheable_files();
        $cacheable_urls = $this->get_cacheable_urls();
        $groups = array("scripts", "styles");
        $core = new Smart_Cache_Core(SMART_CACHE_PLUGIN_NAME);
        $site_url = site_url();

        foreach($groups as $group){
            if($group == 'scripts'){
                $wp_files = $wp_scripts;
            }else{
                $wp_files = $wp_styles;
            }
            $reg_files = $wp_files->registered;
            $cache_files = $cacheable_files[$group];

            // compare registered files against cached files and if the file has been minified:
            foreach($reg_files as $handle => $reg_file){
                $src = $reg_file->src;
                if(substr($src, 0, 2) == '//')
                    continue;

                if(isset($cache_files[$handle])){
                    // a. log the true minified file size
                    if(empty($cache_files[$handle]['cached-size']))
                        $minify_it = true;
                }else{
                    // b. produce a minified version and log the true minified file size
                    $minify_it = true;
                }

                if($minify_it){
                    if(substr($src, 0, 1) == DIRECTORY_SEPARATOR) $src = $site_url . $src;
                    $path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $src);
                    list($url, $file_size) = $core->minify_file($src);
                    if($file_size > 0){
                        $cache_files[$handle] = array(
                            'source' => $src,
                            'source-size' => @filesize($path),
                            'cached' => $url,
                            'cached-size' => $file_size
                        );
                    }
                }
            }

            $cacheable_files[$group] = $cache_files;
        }

        $this->update_cacheable_info($cacheable_files, $cacheable_urls);
        return true;
    }

    /**
     * Scan WP permalinks, triggering the core optimizer
     * @return boolean
     */
    public function scan_files(){
        // build permalink list
        $permalinks = array();
        $post_types = array("post", "page");
        $ok = false;
        foreach($post_types as $post_type){
            $limit = (($post_type == 'page') ? -1 : 5);
            $pt_posts = get_posts(
                array(
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'numberposts' => $limit
                )
            );
            $permalinks[$post_type] = array();
            foreach($pt_posts as $post){
                $permalinks[$post_type][] = get_page_link($post->ID);
            }
        }

        // access each link
        if(!empty($permalinks)){
            $nonce = wp_create_nonce(SMART_CACHE_PLUGIN_NAME . '-task');

            $tasklist = array();
            foreach($permalinks as $post_type => $links){
                if(!empty($links)){
                    foreach($links as $url){
                        $tasklist[] = $url . '?_wpnonce=' . $nonce . '&task=record-files';
                    }
                }
            }

            $cron = new Smart_Cache_Cron(SMART_CACHE_PLUGIN_NAME);
            $cron->save_queue($tasklist);
            $ok = true;
        }
        return $ok;
    }

    /**
     * Process a file for the precache scanner
     * @return array
     */
    public function do_scan_file(){
        $cron = new Smart_Cache_Cron(SMART_CACHE_PLUGIN_NAME);
        $task = $cron->get_next_task();
        $ok = false;
        $msg = '';
        if(empty($task)){
            $current_url = $this->get_current_page_url(true, array('process', '_wpnonce'));
            $msg = 'Scanning complete';
        }else{
            $task = stripslashes($task);
            $url = explode("?", $task);
            $file = trim(str_replace(array(site_url() . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR), array("", "_"), $url[0]), '_');
            $body = $this->do_simple_curl($url[0]);
            $msg = 'Scanning: ' . $url[0] . '... This page needs to remain open until the scan is finihsed.';
            $ok = true;
        }
        return array($ok, $msg);
    }

    /**
     * Retrieve contents of page
     * @param  string $url
     * @param  string $pattern
     * @return array
     */
    public function get_page_contents($url, $pattern = null){
        $url = strtolower(trim($url));
        $data = '';
        $ok = false;
        $site_url = str_replace(array("http://", "https://"), '', site_url());
        if(strpos($url, $site_url) !== false && !empty($url)){
            $protocol = ((is_ssl()) ? 'https:' : 'http:');
            if(substr($url, 0, 4) != 'http')
                $url = $protocol . '//' . $url;
            else
                $url = str_replace(array("http:", "https:"), $protocol, $url);
            $response = $this->do_simple_curl($url);
            if($response){
                if(!empty($pattern)){
                    if(preg_match('/' . $pattern . '/si', $response, $content_parts)){
                        $data = $content_parts[1];
                    }
                }else{
                    $data = $response;
                }
            }
            $ok = true;
        }
        return array($ok, $data);
    }

    /**
     * Delete post revisions and all associated terms and postmeta data
     * @return array
     */
    public function clean_revisions(){
        return array(false, null);
    }

    /**
     * Delete transient data
     * @return array
     */
    public function clean_transients(){
        return array(false, null);
    }

    /**
     * Delete auto-draft data
     * @return array
     */
    public function clean_auto_drafts(){
        return array(flse, null);
    }

    /**
     * Delete trashed posts
     * @return array
     */
    public function clean_trashed_posts(){
        return array(false, null);
    }

    /**
     * Delete spam comments
     * @return array
     */
    public function clean_spam_comments(){
        return array(false, null);
    }

    /**
     * Delete trashed comments
     * @return array
     */
    public function clean_trashed_comments(){
        return array(false, null);
    }

    /**
     * Delete file tuning cache
     * @return array
     */
    public function reset_tuning(){
        return array(false, null);
    }

    /**
     * Purge opcache (Memcached)
     * @return array
     */
    public function purge_opcache(){
        return array(false, null);
    }

    /**
     * Purge varnish cache
     * @param  string $url
     * @return array
     */
    public function purge_varnish($url = ""){
        return array(false, null);
    }

    /**
     * Submit support ticket information
     * @param  array $data
     *                 [email]
     *                 [reason]
     *                 [subject]
     *                 [descr]
     *                 [info]
     * @return array
     */
    public function send_ticket($data){
        $fn_log = get_site_option(SMART_CACHE_PLUGIN_NAME . '-' . MD5(__FUNCTION__), array());
        $logged_trx_time = 0;
        $trx_count = 0;
        $trx_ok = true;
        $retn = null;
        if(! empty($fn_log['logged_trx_time'])){
            $logged_trx_time = (int)$fn_log['logged_trx_time'];
            if($this->base->is_timestamp($logged_trx_time)){
                $trx_count = (int)$fn_log['trx_count'];
                $logged_trx_time = (int)$logged_trx_time;
                $trx_time_diff = time() - $logged_trx_time;
                if($trx_time_diff < 30){
                    $trx_ok = false;
                    $retn = 'Please wait 30 seconds before re-sending this request.';
                }elseif($trx_time_diff < 300 && $trx_count > 3){
                    $trx_ok = false;
                    $retn = 'You have sent more than ' . $trx_count . ' ticket requests in the last 30 minutes.  Please try again soon.';
                }elseif($trx_time_diff > 300){
                    $logged_trx_time = 0;
                    $trx_count = 0;
                }
            }
        }

        if($trx_ok){
            $data['product'] = SMART_CACHE_PLUGIN_NAME_PROPER;
            $data['license_key'] = null;
            $data['license_level'] = 'Free';
            $data['license_valid'] = true;
            $retn = $this->do_simple_curl(SMART_CACHE_HOME_URL . "wp-json/codedragon/v1/tickets/", SMART_CACHE_CURL_METHOD_POST, $data, $headers);
            $fn_log['trx_count'] = $trx_count + 1;
            if($logged_trx_time == 0) $fn_log['logged_trx_time'] = time();
            update_site_option(SMART_CACHE_PLUGIN_NAME . '-' . MD5(__FUNCTION__), $fn_log);
            $retn = json_decode($retn, true);

            // check response for any problems
            if(isset($retn['data']['status']) && $retn['data']['status'] != 200){
                $trx_ok = false;
                $retn = (isset($retn['message'])) ? $retn['message'] : 'Unknown';
            }
        }
        return array($trx_ok, $retn);
    }

    /**
     * Make an asynchronous POST connections
     * @param  string $url
     * @param  array $params
     */
    public function post_async($url, $params){
        $post_string = $params;
        $parts = parse_url($url);
        $fp = fsockopen($parts['host'], isset($parts['port']) ? $parts['port'] : 80, $errno, $errstr, 30);

        $out = "GET " . $parts['path'] . "?$post_string" . " HTTP/1.1\r\n";//you can use POST instead of GET if you like
        $out.= "Host: " . $parts['host'] . "\r\n";
        $out.= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out.= "Content-Length: " . strlen($post_string) . "\r\n";
        $out.= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        fclose($fp);
    }

    /**
     * Parses a user agent string into its important parts
     *
     * @author Jesse G. Donat <donatj@gmail.com>
     * @link https://github.com/donatj/PhpUserAgent
     * @link http://donatstudios.com/PHP-Parser-HTTP_USER_AGENT
     * @param string|null $u_agent User agent string to parse or null. Uses $_SERVER['HTTP_USER_AGENT'] on NULL
     * @throws \InvalidArgumentException on not having a proper user agent to parse.
     * @return string[] an array with browser, version and platform keys
     */
    public function parse_user_agent( $u_agent = null ) {
        if( empty($u_agent) ) {
            if(site_url() . DIRECTORY_SEPARATOR == SMART_CACHE_HOME_URL && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false){
                // plugin is on home site and call is requesting REST API
                $u_agent = false;
            }else{
                if( isset($_SERVER['HTTP_USER_AGENT']) ) {
                    $u_agent = $_SERVER['HTTP_USER_AGENT'];
                } else {
                    // throw new \InvalidArgumentException('parse_user_agent requires a user agent');
                }
            }
        }

        $platform = null;
        $browser  = null;
        $version  = null;

        $empty = array( 'platform' => $platform, 'browser' => $browser, 'version' => $version );

        if( !$u_agent ) return $empty;

        if( preg_match('/\((.*?)\)/im', $u_agent, $parent_matches) ) {
            preg_match_all('/(?P<platform>BB\d+;|Android|CrOS|Tizen|iPhone|iPad|iPod|Linux|(Open|Net|Free)BSD|Macintosh|Windows(\ Phone)?|Silk|linux-gnu|BlackBerry|PlayBook|X11|(New\ )?Nintendo\ (WiiU?|3?DS|Switch)|Xbox(\ One)?)
                    (?:\ [^;]*)?
                    (?:;|$)/imx', $parent_matches[1], $result, PREG_PATTERN_ORDER);

            $priority = array( 'Xbox One', 'Xbox', 'Windows Phone', 'Tizen', 'Android', 'FreeBSD', 'NetBSD', 'OpenBSD', 'CrOS', 'X11' );

            $result['platform'] = array_unique($result['platform']);
            if( count($result['platform']) > 1 ) {
                if( $keys = array_intersect($priority, $result['platform']) ) {
                    $platform = reset($keys);
                } else {
                    $platform = $result['platform'][0];
                }
            } elseif( isset($result['platform'][0]) ) {
                $platform = $result['platform'][0];
            }
        }

        if( $platform == 'linux-gnu' || $platform == 'X11' ) {
            $platform = 'Linux';
        } elseif( $platform == 'CrOS' ) {
            $platform = 'Chrome OS';
        }

        preg_match_all('%(?P<browser>Camino|Kindle(\ Fire)?|Firefox|Iceweasel|IceCat|Safari|MSIE|Trident|AppleWebKit|
                    TizenBrowser|Chrome|Vivaldi|IEMobile|Opera|OPR|Silk|Midori|Edge|CriOS|UCBrowser|Puffin|SamsungBrowser|
                    Baiduspider|Googlebot|YandexBot|bingbot|Lynx|Version|Wget|curl|
                    Valve\ Steam\ Tenfoot|
                    NintendoBrowser|PLAYSTATION\ (\d|Vita)+)
                    (?:\)?;?)
                    (?:(?:[:/ ])(?P<version>[0-9A-Z.]+)|/(?:[A-Z]*))%ix',
            $u_agent, $result, PREG_PATTERN_ORDER);

        // If nothing matched, return null (to avoid undefined index errors)
        if( !isset($result['browser'][0]) || !isset($result['version'][0]) ) {
            if( preg_match('%^(?!Mozilla)(?P<browser>[A-Z0-9\-]+)(/(?P<version>[0-9A-Z.]+))?%ix', $u_agent, $result) ) {
                return array( 'platform' => $platform ?: null, 'browser' => $result['browser'], 'version' => isset($result['version']) ? $result['version'] ?: null : null );
            }

            return $empty;
        }

        if( preg_match('/rv:(?P<version>[0-9A-Z.]+)/si', $u_agent, $rv_result) ) {
            $rv_result = $rv_result['version'];
        }

        $browser = $result['browser'][0];
        $version = $result['version'][0];

        $lowerBrowser = array_map('strtolower', $result['browser']);

        $find = function ( $search, &$key, &$value = null ) use ( $lowerBrowser ) {
            $search = (array)$search;

            foreach( $search as $val ) {
                $xkey = array_search(strtolower($val), $lowerBrowser);
                if( $xkey !== false ) {
                    $value = $val;
                    $key   = $xkey;

                    return true;
                }
            }

            return false;
        };

        $key = 0;
        $val = '';
        if( $browser == 'Iceweasel' || strtolower($browser) == 'icecat' ) {
            $browser = 'Firefox';
        } elseif( $find('Playstation Vita', $key) ) {
            $platform = 'PlayStation Vita';
            $browser  = 'Browser';
        } elseif( $find(array( 'Kindle Fire', 'Silk' ), $key, $val) ) {
            $browser  = $val == 'Silk' ? 'Silk' : 'Kindle';
            $platform = 'Kindle Fire';
            if( !($version = $result['version'][$key]) || !is_numeric($version[0]) ) {
                $version = $result['version'][array_search('Version', $result['browser'])];
            }
        } elseif( $find('NintendoBrowser', $key) || $platform == 'Nintendo 3DS' ) {
            $browser = 'NintendoBrowser';
            $version = $result['version'][$key];
        } elseif( $find('Kindle', $key, $platform) ) {
            $browser = $result['browser'][$key];
            $version = $result['version'][$key];
        } elseif( $find('OPR', $key) ) {
            $browser = 'Opera Next';
            $version = $result['version'][$key];
        } elseif( $find('Opera', $key, $browser) ) {
            $find('Version', $key);
            $version = $result['version'][$key];
        } elseif( $find('Puffin', $key, $browser) ) {
            $version = $result['version'][$key];
            if( strlen($version) > 3 ) {
                $part = substr($version, -2);
                if( ctype_upper($part) ) {
                    $version = substr($version, 0, -2);

                    $flags = array( 'IP' => 'iPhone', 'IT' => 'iPad', 'AP' => 'Android', 'AT' => 'Android', 'WP' => 'Windows Phone', 'WT' => 'Windows' );
                    if( isset($flags[$part]) ) {
                        $platform = $flags[$part];
                    }
                }
            }
        } elseif( $find(array( 'IEMobile', 'Edge', 'Midori', 'Vivaldi', 'SamsungBrowser', 'Valve Steam Tenfoot', 'Chrome' ), $key, $browser) ) {
            $version = $result['version'][$key];
        } elseif( $rv_result && $find('Trident', $key) ) {
            $browser = 'MSIE';
            $version = $rv_result;
        } elseif( $find('UCBrowser', $key) ) {
            $browser = 'UC Browser';
            $version = $result['version'][$key];
        } elseif( $find('CriOS', $key) ) {
            $browser = 'Chrome';
            $version = $result['version'][$key];
        } elseif( $browser == 'AppleWebKit' ) {
            if( $platform == 'Android' && !($key = 0) ) {
                $browser = 'Android Browser';
            } elseif( strpos($platform, 'BB') === 0 ) {
                $browser  = 'BlackBerry Browser';
                $platform = 'BlackBerry';
            } elseif( $platform == 'BlackBerry' || $platform == 'PlayBook' ) {
                $browser = 'BlackBerry Browser';
            } else {
                $find('Safari', $key, $browser) || $find('TizenBrowser', $key, $browser);
            }

            $find('Version', $key);
            $version = $result['version'][$key];
        } elseif( $pKey = preg_grep('/playstation \d/i', array_map('strtolower', $result['browser'])) ) {
            $pKey = reset($pKey);

            $platform = 'PlayStation ' . preg_replace('/[^\d]/i', '', $pKey);
            $browser  = 'NetFront';
        }

        return array( 'platform' => $platform ?: null, 'browser' => $browser ?: null, 'version' => $version ?: null );
    }

    /**
     * Return true if a set of useragent values is in useragent data
     * @param  string $element          platform, browser, version
     * @param  array $ua
     * @param  array $values
     * @return boolean
     */
    public function ua_matches($element, $ua, $values = array()){
        $matched = false;
        return $matched;
    }

    /**
     * Return true if useragent platform is a mobile device
     * @param  array $ua
     * @return boolean
     */
    public function mobile_matches($ua){
        $matched = false;
        return $matched;
    }

    /**
     * Return true if page is in set of page names
     * @param  array $page_url
     * @param  array $values
     * @return boolean
     */
    public function page_matches($page_url, $values){
        $matched = false;
        return $matched;
    }

    /**
     * Return if post type of a page is in set of post types.  Function returns null or page post type
     * @param  array $page_url
     * @param  array $values
     * @return string
     */
    public function post_type_matches($page_url, $values){
        $matched = false;
        return $matched;
    }

    /**
     * Return true if any of the $_CCOKIE are in set of blocked cookies.
     * @param  array $blocked_cookies
     * @return boolean
     */
    public function cookie_matches($blocked_cookies){
        $matched = false;
        return $matched;
    }

    /**
     * Recompose a URL from a parse_url broken one
     * @param  array $parsed        Same array list as parse_url
     * @return string
     */
    public function glue_url($parsed) {
        if (!is_array($parsed)) {
            return false;
        }

        $uri = isset($parsed['scheme']) ? $parsed['scheme'].':'.((strtolower($parsed['scheme']) == 'mailto') ? '' : '//') : '';
        $uri .= isset($parsed['user']) ? $parsed['user'].(isset($parsed['pass']) ? ':'.$parsed['pass'] : '').'@' : '';
        $uri .= isset($parsed['host']) ? $parsed['host'] : '';
        $uri .= isset($parsed['port']) ? ':'.$parsed['port'] : '';

        if (isset($parsed['path'])) {
            $uri .= (substr($parsed['path'], 0, 1) == '/') ?
                $parsed['path'] : ((!empty($uri) ? '/' : '' ) . $parsed['path']);
        }

        $uri .= isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $uri .= isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        return $uri;
    }

    /**
     * Import a .set file
     */
    public function import_settings(){
        $error = null;
        $notice = null;
        return array($error, $notices);
    }
}