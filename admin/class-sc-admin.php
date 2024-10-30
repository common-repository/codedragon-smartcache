<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Admin interface
 *
 * @package    Smart_Cache
 * @subpackage Smart_Cache/admin
 * @author     Dragon Slayer <info@codedragon.ca>
 */

defined('ABSPATH') or die('Nice try...');

class Smart_Cache_Admin extends Smart_Cache_Base {
    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct($plugin_file_name) {
        parent::__construct($plugin_file_name, true);
        $this->file = $plugin_file_name;

        if(!empty($_REQUEST['page'])){
            $this->page_group = str_replace(SMART_CACHE_PLUGIN_NAME . "-", "", $_REQUEST['page']);
        }else{
            $this->page_group = null;
        }

        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_dashboard_setup', array($this, 'dashboard_widget_setup'));

        if(is_admin()){
            if(get_site_option(SMART_CACHE_PLUGIN_NAME . '-activated', false) == true){
                delete_site_option(SMART_CACHE_PLUGIN_NAME . '-activated');
                $this->show_welcome_message();
            }

            if(!SMART_CACHE_IS_PREMIUM){
                add_action('admin_menu', array($this, 'add_premium_submenu'), 11);
                add_action('admin_notices', array($this, 'show_nag_message'));
            }
        }
    }

    /**
     * Preparation
     */
    public function admin_init(){
        $this->page_url = $this->system->get_current_page_url();
        $this->system->self_check($this->notices, $this->errors);
    }

    /**
     * Process form submissions and query requests
     */
    public function admin_post_init() {
        if(! empty($_REQUEST['page']) && strpos($_REQUEST['page'], 'smart-cache') !== false){
            // save form
            if(!empty($_POST) && check_admin_referer('form-submit', SMART_CACHE_PLUGIN_NAME . '-form-nonce')) {
                if(current_user_can('manage_options')){
                    if(! empty($_FILES['import-settings']['tmp_name']) && $_FILES['import-settings']['error'] != UPLOAD_ERR_NO_FILE){
                        list($error, $notice) = $this->system->import_settings();
                        if(!empty($error)) $this->errors[] = $error;
                        if(!empty($notice)) $this->notices[] = $notice;
                    }else{
                        $this->save_post_data();
                    }
                }
            }
        }

        $r = $this->do_process_request();
        if(!empty($r['notice'])) $this->notices[] = $r['notice'];
        if(!empty($r['error'])) $this->errors[] = $r['error'];
        add_action('admin_notices', array($this, 'show_plugin_notices'));
    }

    /**
     * Save posted data
     */
    public function save_post_data(){
        $key = SMART_CACHE_PLUGIN_NAME . '-settings';
        if(isset($_POST[$key]['data-saved']) || empty($this->page_group)) return false;

        if($this->page_group == 'dashboard'){
            $page_settings = $_POST[$key];
        }elseif(empty($_POST[$key][$this->page_group])){
            $page_settings = array();
        }else{
            $page_settings = $_POST[$key][$this->page_group];
        }

        if(true == $this->save_settings($key, $this->page_group, $page_settings)){
            $this->notices[] = SMART_CACHE_PLUGIN_NAME_PROPER . ' settings have been saved.';
            $this->settings = $this->get_settings($key);

            $_POST[$key]['data-saved'] = true;

            // clear caches
            $core = new Smart_Cache_Core(SMART_CACHE_PLUGIN_NAME);
            $core->clear_cache_folder('cache');

            // prepare to update the mod_rewrites
            if(!empty($_POST[SMART_CACHE_PLUGIN_NAME . '-mod-rewrite-settings-changed'])){
                // flush rewrite rules
                global $wp_rewrite;
                $wp_rewrite->flush_rules(true);

                delete_site_option(SMART_CACHE_PLUGIN_NAME . '-mod-rewrites-saved');
                $this->system->self_check($this->notices, $this->errors);
            }

            // do any post save hooks
            do_action('smart_cache_after_save_post_data', SMART_CACHE_PLUGIN_NAME, $key);
        }else{
            $this->notices[] = 'No settings changes have been made.';
        }
    }

    /**
     * Register the stylesheets for the Dashboard.
     *
     * @since    1.0.0
     */
    public function enqueue_styles($hook) {
        // $uncache = chr(65 + rand(0, 25));
        $uncache = null;
        wp_enqueue_style( SMART_CACHE_PLUGIN_NAME . '-dashicons', plugin_dir_url(__FILE__) . "css/dashicon-styles.css", false, SMART_CACHE_VER . $uncache );
        if(strpos($hook, SMART_CACHE_PLUGIN_NAME) !== false){
            wp_enqueue_style(SMART_CACHE_PLUGIN_NAME, plugin_dir_url(__FILE__) . "css/sc-admin.css", array(), SMART_CACHE_VER . $uncache, "all");
            wp_enqueue_style("bootstrap", plugin_dir_url(__FILE__) . "css/bootstrap.min.css");
            wp_enqueue_style('wpb-fa', '//maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css');
        }elseif($hook == 'index.php'){
            wp_enqueue_style(SMART_CACHE_PLUGIN_NAME, plugin_dir_url(__FILE__) . "css/sc-admin-simplified.css", array(), SMART_CACHE_VER . $uncache, "all");
            wp_enqueue_style("bootstrap", plugin_dir_url(__FILE__) . "css/bootstrap.min.css");
            wp_enqueue_style('wpb-fa', '//maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css');
        }
    }

    /**
     * Register the JavaScript for the dashboard.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts($hook) {
        if(('index.php' == $hook || strpos($hook, SMART_CACHE_PLUGIN_NAME) !== false) && is_admin()){
            // $uncache = chr(65 + rand(0, 25));
            $uncache = null;
            wp_enqueue_script("smart_cache_jquery_ui", ((is_SSL()) ? 'https://' : 'http://') . "code.jquery.com/ui/1.10.3/jquery-ui.js", array("jquery"), SMART_CACHE_VER . $uncache);
            wp_enqueue_script("smart_cache_tether", plugin_dir_url(__FILE__) . "js/tether.min.js", array("jquery"), SMART_CACHE_VER . $uncache);
            // wp_enqueue_script("smart_cache_bootstrap", plugin_dir_url(__FILE__) . "js/bootstrap.js", array("jquery"), SMART_CACHE_VER . $uncache);
            wp_register_script(SMART_CACHE_PLUGIN_NAME, plugin_dir_url(__FILE__) . "js/sc-script-admin.js", array("jquery"), SMART_CACHE_VER . $uncache, false);
            wp_localize_script(SMART_CACHE_PLUGIN_NAME, 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
            wp_enqueue_script(SMART_CACHE_PLUGIN_NAME);
        }
    }

    /**
     * Adds settings link to plugin page
     * @param  array $links
     * @param  string $file
     * @return array $links
     */
    public function plugin_settings_links($links, $file) {
        if(dirname($file) == SMART_CACHE_PLUGIN_NAME || strpos(dirname($file), strtolower(SMART_CACHE_PLUGIN_NAME_PROPER)) !== false){
            $settings_link = '<a href="' . get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-dashboard">' . __('Settings', 'plugin_textdomain') . '</a>';
            array_push($links, $settings_link);
            $settings_link = '<a href="' . get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-help">' . __('Support', 'plugin_textdomain') . '</a>';
            array_push($links, $settings_link);
            if(! SMART_CACHE_IS_PREMIUM) {
                $settings_link = '<a href="' . get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-premium" style="color: green; font-weight: bold">' . __('Get Premium', 'plugin_textdomain') . '</a>';
                array_push($links, $settings_link);
            }
        }
        return $links;
    }

    /**
     * Add menu items to the WP admin bar
     * @param $wp_admin_bar
     */
    public function admin_toolbar_links($wp_admin_bar){
        $page_url = $this->system->get_current_page_url(true, array("process", "_wpnonce"));
        $parent = '<i class="dashicons-before dashicons-smartcache-icon"></i> ' . SMART_CACHE_PLUGIN_NAME_PROPER;
        $this->admin_bar_render_item($parent);
        $this->admin_bar_render_item('Settings', get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-dashboard', $parent);
        $this->admin_bar_render_item('Clear Caches', wp_nonce_url(get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-dashboard&process=clear-caches', SMART_CACHE_PLUGIN_NAME . '-process'), $parent, array('id' => 'cache-clear-toolbar-item'));
        $this->admin_bar_render_item('Scan Site', wp_nonce_url(get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-dashboard&process=scan-site', SMART_CACHE_PLUGIN_NAME . '-process'), $parent, array('id' => 'scan-site-toolbar-item'));
        $this->admin_bar_render_item('Help', get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-help', $parent);
    }

    /**
     * Add premium submenu link(s)
     */
    public function add_premium_submenu(){
        global $submenu;
        $submenu[SMART_CACHE_PLUGIN_NAME . '-dashboard'][] = array(
            '<span style="color: #FF4800">' . __('Get Premium!', SMART_CACHE_PLUGIN_NAME ) . '</span>',
            'manage_options',
            SMART_CACHE_PLUGIN_URL
        );
    }

    /**
     * Prepare the plugin's WP dashboard widget
     */
    public function dashboard_widget_setup(){
        wp_add_dashboard_widget(
            'sc_dashboard_widget',
            '<i class="fa fa-bolt"></i> ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Spotlight',
            array($this, 'dashboard_widget')
        );
    }

    /**
     * Get the HTML for the performance stats shown in the Dashboard widget
     * @return string
     */
    public function get_widget_stats_html($echo = true){
        $stats = $this->get_stats();

        $sr1 = round($stats['scripts']['cached']['size-ratio']);
        $sr2 = round($stats['styles']['cached']['size-ratio']);
        $sc1 = intval($stats['scripts']['cached']['count']);
        $sc2 = intval($stats['styles']['cached']['count']);

        if($sr1 < 0) $sr1 = 0;
        if($sr2 < 0) $sr2 = 0;

        $html = '';
        if($sr1 > 0 && $sr2 > 0) {
            $html .= '
                <div class="col-md-5 round-box">
                    <h2 class="big-text">' . $sr1 . '%</h2>
                    <p>Script Size Reduction</p>
                    <p>Based on ' . $sc1 . ' Files Optimized</p>
                </div>' . PHP_EOL;
            $html .= '
                <div class="col-md-5 round-box col-md-offset-1">
                    <h2 class="big-text">' . $sr2 . '%</h2>
                    <p>Stylesheet Size Reduction</p>
                    <p>Based on ' . $sc2 . ' Files Optimized</p>
                </div>' . PHP_EOL;
        }else{
            $html .= '
                <div class="col-md-12 round-box">
                    <h3>Time to improve your site\'s performance!</h3>
                    <p>' . SMART_CACHE_PLUGIN_NAME_PROPER . ' is like nitro for your site.  Let us show you how it can help...</p>
                </div>
            ';
        }

        if($echo)
            echo $html;
        else
            return $html;
    }

    /**
     * Output contents of the plugin's dashboard widget
     */
    public function dashboard_widget() {
        $slug = get_admin_url() . "admin.php?page=".SMART_CACHE_PLUGIN_NAME;
        $license_info = $this->get_license_info();

        echo '
            <div id="sc-widget-stats" class="box row"></div>
            <hr/>
            <div class="row">
                <div class="col-md-12">
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-minify">Minification</a></a></strong></div>
                        <div class="col-md-5">' . (($this->settings['minify']['active'] == false) ? '<span class="error">Disabled</span>' : 'Enabled') . '</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-combine">Combination</a></strong></div>
                        <div class="col-md-5">' . (($this->settings['combine']['active'] == false) ? '<span class="error">Disabled</span>' : 'Enabled') . '</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-page-caching">Page Caching</a></strong></div>
                        <div class="col-md-5">' . (($this->settings['page-caching']['active'] == false) ? '<span class="error">Disabled</span>' : 'Enabled') . '</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-browser-caching">Browser Caching</a></strong></div>
                        <div class="col-md-5">' . (($this->settings['browser-caching']['active'] == false) ? '<span class="error">Disabled</span>' : 'Enabled') . '</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-cdn">CDN Support</a></strong></div>
                        <div class="col-md-5">' . ((SMART_CACHE_IS_PREMIUM) ? (($this->settings['cdn']['active'] == false) ? '<span class="error">Disabled</span>' : 'Enabled') : '<span class="error">Not Available</span>') . '</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-1"><strong><a href="' . $slug . '-' . ((SMART_CACHE_IS_PREMIUM) ? 'features' : 'dashboard') . '">License</a></strong></div>
                        <div class="col-md-5">' . $license_info['level'] . '<br/>' . (($license_info['valid']) ? 'License activated' : 'Not activated <a href="' . $slug . '-dashboard#status">Activate your license</a>') . '</div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    ';

        if(SMART_CACHE_IS_PREMIUM){
            echo '
                    <div class="sc-cta">
                        <p><strong>Fantastic!  You\'re using the Premium version...</strong></p>
                        <p>If you haven\'t done so already, now is the best time to check out the many <a href="' . $slug . '-addons">add-ons</a> compatible with ' . SMART_CACHE_PLUGIN_NAME_PROPER . ', the <a href="' . $slug . '-help">priority support</a> from CodeDragon, and <a href="' . $slug . '-features">offerings</a> exclusive to our Premium clients.</p>
                    </div>
            ';
        }else{
            echo '
                    <div class="sc-cta">
                        <h3>We hope you are seeing benefits from ' . SMART_CACHE_PLUGIN_NAME_PROPER . '.</h3>
                        <p>Why not take your site\'s performance to a higher level and get the <strong><a href="' . SMART_CACHE_PLUGIN_URL . '" target="_blank">Premium version</a></strong> today!  With ' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Premium you get a wealth of superior site optimization tools, access to compatible add-ons, priority support, and other exclusive offerings not available with the free version.</p>
                    </div>
            ';
        }

        echo '
                </div>
            </div>
        ';

    }

    /**
     * Prepares plugin menus and icons
     */
    public function admin_pages() {
        $title = __(SMART_CACHE_PLUGIN_NAME_PROPER, SMART_CACHE_PLUGIN_NAME."-dashboard");
        add_menu_page(
            $title,
            "SmartCache",
            "manage_options",
            SMART_CACHE_PLUGIN_NAME."-dashboard",
            array($this, "admin_page_dashboard"),
            'dashicons-smartcache-icon',
            50
        );

        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Dashboard", "Dashboard", "manage_options", SMART_CACHE_PLUGIN_NAME . "-dashboard", array($this, 'admin_page_dashboard'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Minify", "Minify", "manage_options", SMART_CACHE_PLUGIN_NAME . "-minify", array($this, 'admin_page_minify'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Combine", "Combine", "manage_options", SMART_CACHE_PLUGIN_NAME . "-combine", array($this, 'admin_page_combine'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Page Caching", "Page Caching", "manage_options", SMART_CACHE_PLUGIN_NAME . "-page-caching", array($this, 'admin_page_page_caching'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Browser Caching", "Browser Caching", "manage_options", SMART_CACHE_PLUGIN_NAME . "-browser-caching", array($this, 'admin_page_browser_caching'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "CDN", "CDN", "manage_options", SMART_CACHE_PLUGIN_NAME . "-cdn", array($this, 'admin_page_cdn'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Tools", "Tools", "manage_options", SMART_CACHE_PLUGIN_NAME . "-tools", array($this, 'admin_page_tools'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Reports", "Reports", "manage_options", SMART_CACHE_PLUGIN_NAME . "-reports", array($this, 'admin_page_reports'));
        if(SMART_CACHE_IS_PREMIUM) {
            add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Add-ons", "<span style=\"color: #FF4800\">Add-ons</a>", "manage_options", SMART_CACHE_PLUGIN_NAME . "-addons", array($this, 'admin_page_addons'));
        }
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Features", "Features", "manage_options", SMART_CACHE_PLUGIN_NAME . "-features", array($this, 'admin_page_features'));
        add_submenu_page(SMART_CACHE_PLUGIN_NAME . '-dashboard', "Help", "Help", "manage_options", SMART_CACHE_PLUGIN_NAME . "-help", array($this, 'admin_page_help'));
    }

    /**
     * Display header section
     */
    public function show_header($title){
        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-header" class="wrap">' . PHP_EOL;
        // screen_icon();
        echo '<h2><img src="' . SMART_CACHE_PLUGIN_ROOT . 'assets/images/product-logo.png" /> <strong>' . __('CodeDragon ' . SMART_CACHE_PLUGIN_NAME_PROPER . ((SMART_CACHE_IS_PREMIUM) ? ' Premium' : '') . ' - ' . $title) . '</strong></h2>'.PHP_EOL;
    }

    /**
     * Display section description
     */
    public function show_description(){
        echo '<div class="row">' . PHP_EOL;
        $descr = '<div id="' . SMART_CACHE_PLUGIN_NAME . '-descr" class="col-md-9">' . SMART_CACHE_PLUGIN_NAME_PROPER . ' is an intelligent site performance and caching optimization facility for Wordpress.  It offers super-fast, adaptive minification, GZIP compression, browser caching, database performance improvements, and more...</div>' . PHP_EOL;
        if(! SMART_CACHE_IS_PREMIUM){
            $msg = $this->get_nag();
            if(!empty($msg)){
                $content = preg_replace('/<\/?a[^>]*>.*<\/a>/i', '', $msg['excerpt']['rendered']);
                $content = strip_tags($msg['excerpt']['rendered']);

                if(isset($msg['_thumbnail_id'])){
                    $descr = '<div id="' . SMART_CACHE_PLUGIN_NAME . '-descr" class="col-md-9 ' . SMART_CACHE_PLUGIN_NAME . '-nag">';
                    $descr.= '<div class="col-md-1"><img src="' . $msg['_thumbnail_id'] . '" /></div>';
                    $descr.= '<div class="col-md-9">' . $content . '</div>';
                    if(isset($msg['link_title'])){
                        $descr.= '<div class="col-md-2 pull-right"><a href="' . $msg['link'] . '" target="_blank" class="btn btn-secondary"><strong>' . $msg['link_title'] . '</strong></a></div>';
                    }
                    $descr.= '</div>' . PHP_EOL;
                }else{
                    $descr = '<div id="' . SMART_CACHE_PLUGIN_NAME . '-descr" class="col-md-9 ' . SMART_CACHE_PLUGIN_NAME . '-nag">';
                    $descr.= '<div class="col-md-10">' . $content . '</div>';
                    if(isset($msg['link_title'])){
                        $descr.= '<div class="col-md-2 pull-right"><a href="' . $msg['link'] . '" target="_blank" class="btn btn-secondary"><strong>' . $msg['link_title'] . '</strong></a></div>';
                    }
                    $descr.= '</div>' . PHP_EOL;
                }
            }
        }

        echo $descr;
        echo '<div class="col-md-3 pull-right text-right cache-clear">' . PHP_EOL;
        echo '<div class="row">';
        $this->show_button('clear_cache', false);
        $this->show_button('scan_site', false);
        echo '</div>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
    }

    /**
     * Display the welcome message
     */
    public function show_welcome_message(){
        echo '
        <div class="notice is-dismissible smart-cache-welcome">
            <h2 style="color: #FF4500"><i class="dashicons-before dashicons-megaphone"></i> ' . SMART_CACHE_PLUGIN_NAME_PROPER . ((SMART_CACHE_IS_PREMIUM) ? ' Premium' : '') . ' is ready to improve your site\'s performance!</h2>
            <p>Welcome to SmartCache and your new, better site.  During activation, key features were prepared to give you and your audience a more optimized experience.</p>
            <p>You are free to fine tune these <a href="' . get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME . '-dashboard">settings</a> anytime to achieve an ideal calibration that\'s right for your needs.
            </p>
        </div>';
    }

    public function show_nag_message(){
        if((isset($_GET['page']) && strpos($_GET['page'], SMART_CACHE_PLUGIN_NAME) === false) || !isset($_GET['page'])){
            echo '
            <div class="notice-success notice is-dismissible smart-cache-reminder">
                <p>
                    With <strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . '</strong> your site is benefitting from better performance, but this is just the start... Upgrade to <a href="' . SMART_CACHE_PLUGIN_URL . '" target="_blank"><strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Premium</strong></a> and take advantage of more than 40 additional features.
                </p>
            </div>';
        }
    }

    /**
     * Display notice panel
     */
    public function show_plugin_notices(){
        global $current_screen;

        $this->notices = array_filter(array_unique($this->notices));
        if(!empty($this->notices) && is_array($this->notices)){
            echo '<div class="notice notice-success is-dismissible">' . PHP_EOL;
            if(strpos($current_screen->parent_base, SMART_CACHE_PLUGIN_NAME) === false)
                echo '<div class="notice-plugin-name"><strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Message:</strong></div>' . PHP_EOL;
            echo '<ul id="' . SMART_CACHE_PLUGIN_NAME . '-notice-panel" class="settings-notice warning">' . PHP_EOL;
            echo '<li>' . join('</li><li>', $this->notices) . '</li>' . PHP_EOL;
            echo '</ul></div>' . PHP_EOL;
        }
        $this->errors = array_filter(array_unique($this->errors));
        if(!empty($this->errors) && is_array($this->errors)){
            echo '<div class="notice notice-error">' . $page . PHP_EOL;
            if(strpos($current_screen->parent_base, SMART_CACHE_PLUGIN_NAME) === false)
                echo '<div class="notice-plugin-name"><strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Error:</strong></div>' . PHP_EOL;
            echo '<ul id="' . SMART_CACHE_PLUGIN_NAME . '-error-panel" class="settings-error error">' . PHP_EOL;
            echo '<li>' . join('</li><li>', $this->errors) . '</li>' . PHP_EOL;
            echo '</ul></div>' . PHP_EOL;
        }
    }

    /**
     * Display the panel menu
     */
    public function show_menu($slug){
        ?>

        <div id="nav">
            <h2 class="themes-php">
                <a class="nav-tab<?php if($slug == "dashboard") echo " nav-tab-active"; ?>" href="?page=smart-cache-dashboard"><i class="fa fa-dashboard"></i> Dashboard</a>
                <a class="nav-tab<?php if($slug == "minify") echo " nav-tab-active"; ?>" href="?page=smart-cache-minify">Minify</a>
                <a class="nav-tab<?php if($slug == "combine") echo " nav-tab-active"; ?>" href="?page=smart-cache-combine">Combine</a>
                <a class="nav-tab<?php if($slug == "page_caching") echo " nav-tab-active"; ?>" href="?page=smart-cache-page-caching">Page Caching</a>
                <a class="nav-tab<?php if($slug == "browser_caching") echo " nav-tab-active"; ?>" href="?page=smart-cache-browser-caching">Browser Caching</a>
                <a class="nav-tab<?php if($slug == "cdn") echo " nav-tab-active"; ?>" href="?page=smart-cache-cdn">CDN</a>
                <a class="nav-tab<?php if($slug == "tools") echo " nav-tab-active"; ?>" href="?page=smart-cache-tools">Tools</a>
                <a class="nav-tab<?php if($slug == "reports") echo " nav-tab-active"; ?>" href="?page=smart-cache-reports">Reports</a>
                <?php if(SMART_CACHE_IS_PREMIUM){ ?>
                <a class="nav-tab<?php if($slug == "addons") echo " nav-tab-active"; ?>" href="?page=smart-cache-addons">Add-ons</a>
                <?php } ?>
                <a class="nav-tab<?php if($slug == "features") echo " nav-tab-active"; ?>" href="?page=smart-cache-features">Features</a>
                <a class="nav-tab<?php if($slug == "help") echo " nav-tab-active"; ?>" href="?page=smart-cache-help">Help</a>
                <?php if(! SMART_CACHE_IS_PREMIUM) { ?>
                <a class="nav-tab nav-tab-highlight<?php if($slug == "premium") echo " nav-tab-active"; ?>" href="<?php echo SMART_CACHE_HOME_URL ?>products/wordpress-plugins/smartcache/" target="_blank">Get Premium!</a>
                <?php } ?>
            </h2>
        </div>
        <?php
    }

    /**
     * Display hidden form fields (ajax url, nonce)
     */
    public function show_hidden_form_fields(){
        $do_first_scan = get_site_option(SMART_CACHE_PLUGIN_NAME . "-do-scan", false);
        $do_first_scan_url = ($do_first_scan == false) ? '' : wp_nonce_url(get_admin_url() . 'admin.php?page=' . SMART_CACHE_PLUGIN_NAME . '-dashboard&process=scan-site', SMART_CACHE_PLUGIN_NAME . '-process');
        delete_site_option(SMART_CACHE_PLUGIN_NAME . "-do-scan");
        echo '<input type="hidden" id="do-scan" value="' . $do_first_scan_url . '" />' . PHP_EOL;
        echo '<input type="hidden" id="ajaxurl" value="' . plugin_dir_url(__FILE__) . '" />' . PHP_EOL;
        echo '<input type="hidden" id="settings-changed" value="" name="' . SMART_CACHE_PLUGIN_NAME . '-settings-changed" />'.PHP_EOL;
        echo '<input type="hidden" id="mod-rewrite-settings-changed" value="" name="' . SMART_CACHE_PLUGIN_NAME . '-mod-rewrite-settings-changed" />'.PHP_EOL;
        wp_nonce_field('form-submit', SMART_CACHE_PLUGIN_NAME . '-form-nonce');
    }

    /**
     * Display buttons
     */
    public function show_button($button, $row = true){
        if($row) echo '<div class="row">'.PHP_EOL;
        $pageurl = $this->system->get_current_page_url(true, array("process", "_wpnonce"));
        switch($button){
            case 'save':
                echo '<p class="col-md-12"><input type="submit" id="' . SMART_CACHE_PLUGIN_NAME . '-save" class="btn btn-save pull-right" value="Save Changes" /></p>' . PHP_EOL;
                break;
            case 'send_ticket':
                echo '<p class="col-md-12"><button id="' . SMART_CACHE_PLUGIN_NAME . '-send-ticket" class="btn btn-save pull-right">Send Ticket</button></p>' . PHP_EOL;
                break;
            case 'clear_cache':
                echo '<a href="' . wp_nonce_url($pageurl . '&process=clear-caches', SMART_CACHE_PLUGIN_NAME . '-process') . '" id="cache-clear-button" class="btn btn-primary"><i class="fa fa-trash-o"></i> Clear</a>' . PHP_EOL;
                break;
            case 'scan_site':
                echo '<a href="' . wp_nonce_url($pageurl . '&process=scan-site', SMART_CACHE_PLUGIN_NAME . '-process') . '" id="scan-site-button" class="btn btn-primary float-left"><i class="fa fa-eye"></i> Scan</a>' . PHP_EOL;
                break;
            case 'get_premium':
                echo '<div class="pull-right"><a href="' . SMART_CACHE_PLUGIN_URL . '" id="get-premium-button" class="btn btn-primary" target="_blank"><i class="fa fa-trophy"></i> Upgrade to Premium Today</a></div>' . PHP_EOL;
                break;
        }
        if($row) echo '</div>' . PHP_EOL;
    }

    /**
     * Display footer section
     */
    public function show_footer(){
        echo '<div id="' . SMART_CACHE_PLUGIN_NAME.'-footer" class="clear row">' . PHP_EOL;
        echo '<p class="col-md-6">' . SMART_CACHE_PLUGIN_NAME_PROPER . ((SMART_CACHE_IS_PREMIUM) ? ' Premium' : '') . ' &copy; ' . date("Y") . '.  Made for you by <a href="http://www.codedragon.ca" target="_blank">CodeDragon Software</a></p>' . PHP_EOL;
        echo '<p class="col-md-6 text-right">Version '.SMART_CACHE_VER . '</p>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
    }

    /**
     * Dashboard Page
     */
    public function admin_page_dashboard(){
        $page_url = $this->system->get_current_page_url(true, array("process", "_wpnonce", "key"));
        $this->show_header("Dashboard");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("dashboard");

        $stats = $this->get_stats();
        $warnings = array();
        if(defined('DISABLE_WP_CRON')) $warnings[] = "DISABLE_WP_CRON is set";
        $license_info = $this->get_license_info();

        $news = $this->get_codedragon_posts(SMART_CACHE_DASH_NEWS_CAT);
        $news_html = '';
        if(empty($news)){
            $news_html = '<div class="' . SMART_CACHE_PLUGIN_NAME . '-news"><p>Nothing new today...</p><p>We\'re building something great for you!</p></div>';
        }else{
            foreach($news as $article){
                $title = strip_tags($article['title']['rendered']);
                $news_html .= '<div class="' . SMART_CACHE_PLUGIN_NAME . '-news"><h3>' . $title . '</h3>' . $article['excerpt']['rendered'] . '</div>';
            }
        }

        $script_size_ratio = round($stats['scripts']['cached']['size-ratio']);
        if($script_size_ratio <= 0)
            $script_size_ratio = '--';
        else
            $script_size_ratio .= '%';

        $styles_size_ratio = round($stats['styles']['cached']['size-ratio']);
        if($styles_size_ratio <= 0)
            $styles_size_ratio = '--';
        else
            $styles_size_ratio .= '%';

        $fieldset = array(
            "stats" => array(
                "type" => "cells", "cells" => array(
                    '<div class="col-md-4">
                        <h2><i class="fa fa-bolt fa-lg"></i> Performance</h2>
                        <div id="sc-dashboard-stats-js" class="box row no-gutter">
                            <div class="col-md-5 round-box">
                                <p class="big-text">' . $script_size_ratio . '</p>
                                <p>Script Size Reduction</p>
                                ' . (($script_size_ratio > 0) ? '<h5>' . intval($stats['scripts']['unoptimized']['source-size'] / 1024) . ' to ' . intval($stats['scripts']['cached']['cached-size'] / 1024) . 'Kb</h5>' : '') .'
                            </div>
                            <div class="col-md-5 round-box col-md-offset-1">
                                <p class="big-text">' . $styles_size_ratio . '</p>
                                <p>Stylesheet Size Reduction</p>
                                ' . (($styles_size_ratio > 0) ? '<h5>' . intval($stats['styles']['unoptimized']['source-size'] / 1024) . ' to ' . intval($stats['styles']['cached']['cached-size'] / 1024) . 'Kb</h5>' : '') .'
                            </div>
                        </div>
                    </div>',
                    '<div class="col-md-4">
                        <h2><i class="fa fa-bar-chart fa-lg"></i> Stats</h2>
                        <div id="sc-dashboard-stats-css" class="box row no-gutter">
                            <div class="col-md-5 round-box">
                                <p class="big-text">' . $stats['scripts']['cached']['count'] . '</p>
                                <p>Scripts Optimized</p>
                            </div>
                            <div class="col-md-5 round-box col-md-offset-1">
                                <p class="big-text">' . $stats['styles']['cached']['count'] . '</p>
                                <p>Stylesheets Optimized</p>
                            </div>
                        </div>
                    </div>',
                    '<div class="col-md-4 no-gutter">
                        <h2><i class="fa fa-commenting fa-lg"></i> News</h2>
                        <div class="' . SMART_CACHE_PLUGIN_NAME . '-news-panel">
                        ' . $news_html . '
                        </div>
                    </div>'
                ),
                "display" => true
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-forward\"></i> Quick Info"
            ),
            "minify_active" => array(
                "type" => "checkbox", "name" => "minify|active", "label" => "Minification", "label_for" => "active", "label_tip" => "Minification is the process of making text files smaller and load faster by removing unnecessary spaces and line-returns.  We recommend turning minification on as part of the first steps to improving your site's performance.", "field_text" => "Enabled", "display" => true, "post_text" => (($this->settings['minify']['active'] == false) ? '<span class="error">Minification is currently off.</span>' : 'Minification is on and ready for <a href="'.$slug.'-minify'.'">customization</a>.')
            ),
            "combine_active" => array(
                "type" => "checkbox", "name" => "combine|active", "label" => "Combination", "label_for" => "active", "label_tip" => "Minification is only the start.  For added performance, " . SMART_CACHE_PLUGIN_NAME_PROPER . " can also combine the many scripts and stylesheet files into fewer larger files.  Doing this results in a reduced number of total resources for your browser to request.", "field_text" => "Enabled", "display" => true, "post_text" => (($this->settings['combine']['active'] == false) ? '<span class="error">Combination is currently off.</span>' : 'Combination is on and ready to be <a href="'.$slug.'-combine'.'">set-up</a>')
            ),
            "page_caching_active" => array(
                "type" => "checkbox", "name" => "page-caching|active", "label" => "Page Caching", "label_for" => "active", "label_tip" => "By activating page caching " . SMART_CACHE_PLUGIN_NAME_PROPER . " saves a flattened version of the site's pages.  Cached pages load faster than processed pages, which will improve performance.", "field_text" => "Enabled", "display" => true, "post_text" => (($this->settings['page-caching']['active'] == false) ? '<span class="error">Page caching is currently off.</span>' : 'Page caching is on and ready to be <a href="'.$slug.'-page-caching'.'">configured</a>')
            ),
            "browser_caching_active" => array(
                "type" => "checkbox", "name" => "browser-caching|active", "label" => "Browser Caching", "label_for" => "active", "label_tip" => SMART_CACHE_PLUGIN_NAME_PROPER . " includes measures to improve how browsers load your site's content.", "field_text" => "Enabled", "display" => true, "post_text" => (($this->settings['browser-caching']['active'] == false) ? '<span class="error">Browser caching is currently off.</span>' : 'Browser caching is on and ready for <a href="'.$slug.'-browser-caching">customization</a>')
            ),
            "cdn_active" => array(
                "type" => "checkbox", "name" => "cdn|active", "label" => "CDN Support", "label_for" => "active", "label_tip" => SMART_CACHE_PLUGIN_NAME_PROPER . " includes measures to improve how browsers load your site's content.", "field_text" => "Enabled", "display" => true, "post_text" => (($this->settings['cdn']['active'] == false) ? '<span class="error">CDN support is currently off.</span>' : 'CDN support is on and ready to be <a href="' . $slug . '-cdn">prepared</a>'), "premium" => true
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "h2_2" => array(
                "type" => "h2", "label" => "<a name=\"status\"></a><i class=\"fa fa-asterisk\"></i> Status"
            ),
            "license" => array(
                "type" => "info", "label" => "License", "field_text" => $license_info['level'] . ((SMART_CACHE_IS_PREMIUM) ? '' : '<br/><a href="' . SMART_CACHE_PLUGIN_URL . '" target="_blank">Now is a great time to get the Premium version</a>.  <a href="' . $slug . '-features">See what you can get</a>.'), "display" => true
            ),
            "key" => array(
                "type" => "text", "label" => "Activation State", "name" => "license_key", "value" => $license_info['key'], "pre_text" => (($license_info['valid']) ? "You are activated. " :
                "You haven't activated the plugin.  Please enter your license key."), "post_text" => "<a href=\"" . $slug . "-dashboard\" class=\"btn btn-secondary\" id=\"activate-btn\" data-href=\"" . wp_nonce_url($this->append_query_var($page_url, 'process=activate'), SMART_CACHE_PLUGIN_NAME . '-process') . "\">" . (($license_info['valid']) ? "Re-" : "") . "Activate</a>", "class" => "col-md-6", "value" => $license_info['key'], "display" => true
            ),
            "bar_3" => array(
                "type" => "bar"
            ),
            "h2_3" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-exclamation-circle\"></i> Warnings"
            ),
            "warnings" => array(
                "type" => "info", "label" => "Warnings", "field_text" => join("<br/>", $warnings), "display" => true
            ),
        );

        if(!SMART_CACHE_IS_PREMIUM) unset($fieldset['key']);

        $fieldset = $this->augment_fieldset($fieldset, "dashboard");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-dashboard" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Minification Page
     *
     * minify on/off
     * clear minify cache
     * minify level
     *      css
     *      js
     * set minification expiry
     * minify files for logged-in users on/off
     *      minify for specific users
     * remove query strings
     * render-blocking
     *      load all css files asyncronously
     *      defer all js files
     * expand css @import - premium -
     * tuning - premium -
     *      exclude jquery-migrate
     *      use Google closure
     *      select JS files to minify/defer
     *      select CSS files to minify/async
     */
    public function admin_page_minify(){
        $this->show_header("Minification");
        $slug = get_admin_url() . "admin.php?page=".SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("minify");
        $site_url = site_url();

        $user_roles = $this->get_wp_roles();
        $user_roles_default = array_fill_keys(array_keys($user_roles), 0);
        $user_roles_selected = $this->get_val($this->settings['minify']['admin-users'], $user_roles_default);
        $user_roles_selected = array_merge($user_roles_default, $user_roles_selected);

        $cacheable_files_tuning = $this->system->get_cacheable_files_tuning();
        $cacheable_files = $this->system->get_cacheable_files();

        $tuning = array();
        foreach($cacheable_files_tuning as $group => $fileset){
            $tuning[$group] = array();
            foreach($fileset as $handle => $atts){
                if(strpos($atts['source'], '.php') !== false) continue;

                $url = $full_url = str_replace($site_url, '', $atts['source']);
                if(strlen($url) > 80) $url = substr(dirname($url), 0, 60) . ' .. ' . basename($url);
                $path = str_replace($site_url . DIRECTORY_SEPARATOR, ABSPATH, $atts['source']);
                if(file_exists($path))
                    $file_size = filesize($path);
                else
                    $file_size = '--';
                $tuning[$group][$handle] = array(
                    '_data' => $full_url,
                    'file' => $url,
                    'size' => $file_size,
                    'minify' => $atts['minify'],
                    'async' => $atts['async']
                );
                if($group == 'scripts') $tuning[$group][$handle]['defer'] = $atts['defer'];
            }
        }

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-sort-amount-down fa-sort-amount-desc fa-lg\"></i> Minify - Make Your Files Smaller"
            ),
            "minify_active" => array(
                "type" => "checkbox", "name" => "minify|active", "label" => "Enable Minification?", "label_for" => "active", "label_tip" => "Minification is the process of making text files smaller and load faster by removing unnecessary spaces and line-returns.  We recommend turning minification on as part of the first steps to improving your site's performance.", "field_text" => "Yes", "display" => true, "post_text" => (($this->settings['minify']['active'] == false) ? '<span class="error">Minification is currently off.  We advise turning it on to benefit from the features below.</span>' : 'Minification is on and ready!')
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "h3_1" => array(
                "type" => "h3", "label" => "<i class=\"fa fa-thumbs-up\"></i> Initial Settings"
            ),
            "minify_css" => array(
                "type" => "checkbox", "name" => "minify|minify-css", "label" => "Minify CSS Stylesheets?", "label_for" => "minify-css", "label_tip" => "CSS stylesheets describe how the browser should layout the page content.", "field_text" => "Yes", "post_text" => "<i class='fa fa-warning fa-lg'></i> Once enabled, check your site for display issues." . ((SMART_CACHE_IS_PREMIUM) ? "  This setting is required for Javascript Tuning" : ""), "display" => true, "depends_on" => "minify|active"
            ),
            "minify_js" => array(
                "type" => "checkbox", "name" => "minify|minify-js", "label" => "Minify Javascript Files?", "label_for" => "minify-js", "label_tip" => "A Javascript file contains instructions used by browsers to allow interaction with page elements or provide additional functionality.", "field_text" => "Yes", "post_text" => "<i class='fa fa-warning fa-lg'></i> Once enabled, check your site for operational and usability issues." . ((SMART_CACHE_IS_PREMIUM) ? "  This setting is required for Stylesheet Tuning" : ""), "display" => true, "depends_on" => "minify|active"
            ),
            "minify_expiry" => array(
                "type" => "select", "name" => "minify|minify-expiry", "label" => "Minimum Cache Lifespan", "label_for" => "minify-expiry", "label_tip" => "How long will minified files last before they are updated?", "options" => array(3600 => "1 Hour", 7200 => "2 Hours", 18000 => "5 Hours", 43200 => "12 Hours", 86400 => "1 Day", 604800 => "1 Week", 2592000 => "1 Month", 7776000 => "3 Months", 15552000 => "6 Months"), "post_text" => "Choose an cache lifespan based on how frequently your site's content changes.  If set too low, the act of refreshing the cache might negatively impact performance.  Too long and the public may not see recent changes until the cache clears.", "display" => true, "depends_on" => "minify|active"
            ),
             "bar_2" => array(
                "type" => "bar"
            ),
            "h3_2" => array(
                "type" => "h3", "label" => "<i class=\"fa fa-plus-square\"></i> More..."
            ),
            "minify_logged_in_users" => array(
                "type" => "checkbox", "name" => "minify|minify-logged-in-users", "label" => "Enable Minification for Logged-in Users?", "label_for" => "minify-logged-in-users", "field_text" => "Yes", "post_text" => "This allows you to control whether or not logged-in users experience caching.<br/><i class='fa fa-warning fa-lg'></i> Careful, this may adversely affect some admin styles or functions that cannot be minified.", "display" => true, "depends_on" => "minify|active"
            ),
            "admin_users" => array(
                "type" => "checkboxes", "name" => "minify|admin-users", "label" => "", "label_for" => "admin-users", "field_text" => $user_roles, "values" => $user_roles_selected, "pre_text" => "Select the user role(s) that will experience minification.", "display" => true, "style" => "vertical-scroll", "depends_on" => "minify|minify-logged-in-users"
            ),
            "query_strings" => array(
                "type" => "group", "name" => "minify|query-strings", "label" => "Remove Query Strings?", "label_for" => "remove-query-strings", "label_tip" => "A query string is typically added by software to indicate that the file is part of a version", "class" => "no-padding", "sub_fieldset_labels" => false, "sub_fieldset_border" => false, "sub_fieldset" => array(
                        "remove_query_strings" => array("type" => "checkbox", "name" => "minify|remove-query-strings", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> Instruct ".SMART_CACHE_PLUGIN_NAME_PROPER." to remove query strings (ie: ?ver=nnn) from resource requests.  Note: combining automatically removes all query strings.", "display" => true, "depends_on" => "minify|active"),
                        "only_css_query_strings" => array("type" => "checkbox", "name" => "minify|only-css-query-strings", "field_text" => "Only remove ?ver=nnn query strings (leave strings that might be needed for other plugins)", "display" => true, "depends_on" => "minify|active"),
                )
            ),
            "load_js_defer" => array(
                "type" => "checkbox", "name" => "minify|load-js-defer", "label" => "Defer Loading of Javascript Files?", "label_for" => "load-js-defer", "label_tip" => "Load scripts after the page starts to display.", "field_text" => "Yes", "post_text" => "<i class='fa fa-warning fa-lg'></i> Force all Javascript files to be requested after content is loaded -- this helps prevent render-blocking (check your site for operational and usability issues).", "display" => true, "depends_on" => "minify|active"
            ),
            "load_js_async" => array(
                "type" => "checkbox", "name" => "minify|load-js-async", "label" => "Asyncronously Load Javascript Files?", "label_for" => "load-js-async", "label_tip" => "Enable to load scripts while other elements are loading", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-warning fa-lg\"></i> <i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> Instruct browsers to load Javascript files at the same time as other content (check your site for functionality issues as this option may cause scripts to fail).", "display" => true, "depends_on" => "minify|active"
            ),
            "load_css_async" => array(
                "type" => "checkbox", "name" => "minify|load-css-async", "label" => "Asyncronously Load CSS Stylesheets?", "label_for" => "load-css-async", "label_tip" => "Enable to load stylesheets while other elements are being loaded", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-warning fa-lg\"></i> <i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> Instruct browsers to load CSS stylesheets at the same time as other content (check your site for display issues as this option may cause FOUC or a Flash of Unformatted Content, where you will see unformatted styling until the CSS has loaded).", "display" => true, "depends_on" => "minify|active"
            ),
            "minify_html" => array(
                "type" => "checkbox", "name" => "minify|minify-html", "label" => "Minify HTML Content?", "label_for" => "minify-html", "label_tip" => "By minifying page HTML contents, even minimal shirnkage will positively improve load times.", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> Enable this setting and the <strong>'Serve Static Content' setting on the <a href=\"" . $slug . "-page-caching\">Page Caching</a> tab</strong>, and experience a greater performance boost -- especially if you are on an NGINX server.<br/><i class='fa fa-warning fa-lg'></i> Once enabled, check your site for display issues.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "bar_4" => array(
                "type" => "bar"
            ),
            "h3_4" => array(
                "type" => "h3", "label" => "<i class=\"fa fa-rocket\"></i> Advanced"
            ),
            "include_wp_core_files" => array(
                "type" => "checkbox", "name" => "minify|include-wp-core-files", "label" => "Minify Core Wordpress Files?", "label_for" => "include-wp-core-files", "field_text" => "Yes", "post_text" => "<i class='fa fa-warning fa-lg'></i> You may also include files in the Wordpress wp-admin and wp-includes folders.  Disable this option should you encounter adverse Javascript functionality or style issues related to the fundemental operation of Wordpress.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "exclude_jquery_migrate" => array(
                "type" => "checkbox", "name" => "minify|exclude-jquery-migrate", "label" => "Do Not Load JQuery Migrate?", "label_for" => "exclude-jquery-migrate", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-warning fa-lg\"></i> <i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> JQuery Migrate is a set of Javascript functions that provides backward compatibility for programs that reference an older version of JQuery.  If you are using the latest plugin updates and are not referencing old JQuery functions in your code, enabling this option will tell browsers not to load this extra file.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "extract_@import" => array(
                "type" => "checkbox", "name" => "minify|extract-@import", "label" => "Extract @Import References?", "label_for" => "extract-@import", "label_tip" => "Process imported CSS files as separate requests", "field_text" => "Yes", "post_text" => "Although an efficient technique for developers, stylesheet files that include the @import directive actually take longer to load.  This is because the main file must wait until each @import included file is loaded before completing, thus resulting in delays.  By extracting the imported files each CSS file will be loaded separately while maintaining order.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "use_google_closure" => array(
                "type" => "checkbox", "name" => "minify|use-google-closure", "label" => "Use Google Closure?", "label_for" => "use-google-closure", "field_text" => "Yes", "post_text" => "Use the Google Closure mechanism instead of jShrink to minify Javascript files.  <i class='fa fa-warning fa-lg'></i> If you encounter problems using Google Closure, such as empty files, disable this feature and the minifier will automatically switch to jShrink.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "bar_5" => array(
                "type" => "bar"
            ),
            "h3_5" => array(
                "type" => "h3", "label" => "<i class=\"fa fa-wrench\"></i> File Tuning"
            ),
            "js_files" => array(
                "type" => "table", "name" => "minify|scripts", "label" => "Javascript Tuning", "label_for" => "scripts", "label_tip" => "Customize the loading of each script file", "table_fields" => array("file" => "info", "size" => "info", "minify" => "checkbox", "defer" => "checkbox", "async" => "checkbox"), "table_field_cols" => array("file" => "8", "size" => "1", "minify" => "1", "defer" => "1", "async" => "1"), "table_field_titles" => array("file" => count($tuning['scripts']) . " File(s) <div class=\"pull-right\"><i class=\"fa fa-search\"></i>&nbsp;<input type=\"text\" id=\"js_files_search\" value=\"\" placeholder=\"Search for files\" data-depends-on=\"minify-active\"" . (($this->settings['minify']['active']) ? "" : " disabled=\"disabled\"") . " /><select id=\"js_files_filter\"><option value=\"\">Any state</option><option value=\"unchecked\">Unchecked</option><option value=\"checked\">Checked</option></select></div>", "size" => "Size", "minify" => "Minify", "defer" => "Defer", "async" => "Async"), "values" => $tuning['scripts'], "max_height" => 400, "pre_text" => "Choose which Javascript files are minified and/or deferred (requested after content is loaded).  You can search for the file and, if not in the list, enter it in the \"Exclude Custom Javascript Files\" box below.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
            "bar_6" => array(
                "type" => "bar"
            ),
            "css_files" => array(
                "type" => "table", "name" => "minify|styles", "label" => "Stylesheet Tuning", "label_for" => "styles", "label_tip" => "Customize the requesting of each CSS stylesheet", "table_fields" => array("file" => "info", "size" => "info", "minify" => "checkbox", "async" => "checkbox"), "table_field_cols" => array("file" => "8", "size" => "1", "minify" => "1", "async" => "2"), "table_field_titles" => array("file" => count($tuning['styles']) . " File(s) <div class=\"pull-right\"><i class=\"fa fa-search\"></i>&nbsp;<input type=\"text\" id=\"css_files_search\" value=\"\" placeholder=\"Search for files\"data-depends-on=\"minify-active\"" . (($this->settings['minify']['active']) ? "" : " disabled=\"disabled\"") . " /><select id=\"css_files_filter\"><option value=\"\">Any state</option><option value=\"unchecked\">Unchecked</option><option value=\"checked\">Checked</option></select></div>", "size" => "Size", "minify" => "Minify", "async" => "Async"), "values" => $tuning['styles'], "max_height" => 400, "pre_text" => "Choose which CSS stylesheet files are minified and/or loaded asyncronously (while other items are loaded).  You can search for the file and, if not in the list, enter it in the \"Exclude Custom Stylesheet Files\" box below.", "display" => true, "depends_on" => "minify|active", "premium" => true
            ),
        );

        $fieldset = $this->augment_fieldset($fieldset, "minify");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-minify" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Combine Page
     *
     * combine on/off
     * combine files for logged-in users on/off
     *      combine for specific users
     * combine
     *      JS files
     *      CSS files
     *      Google fonts
     */
    public function admin_page_combine(){
        $this->show_header("Combine");
        $slug = get_admin_url() . "admin.php?page=".SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("combine");

        $user_roles = $this->get_wp_roles();
        $user_roles_default = array_fill_keys(array_keys($user_roles), 0);
        $user_roles_selected = $this->get_val($this->settings['combine']['admin-users'], $user_roles_default);
        $user_roles_selected = array_merge($user_roles_default, $user_roles_selected);

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-compress fa-lg\"></i> Combine - Fewer Files Means Faster Loading"
            ),
            "combine_active" => array(
                "type" => "checkbox", "name" => "combine|active", "label" => "Enable Combination?", "label_for" => "active", "label_tip" => "Minification is only the start.  For added performance, " . SMART_CACHE_PLUGIN_NAME_PROPER . " can also combine the many scripts and stylesheet files into fewer larger files.  Doing this results in a reduced number of total resources for your browser to request.", "field_text" => "Yes", "display" => true, "post_text" => (($this->settings['combine']['active'] == false) ? '<span class="error">Combination is currently off.  We advise turning it on to benefit from the features below.</span>' : 'Combination is on and ready!')
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "combine_logged_in_users" => array(
                "type" => "checkbox", "name" => "combine|combine-logged-in-users", "label" => "Enable Combination for Logged-in Users?", "label_for" => "combine-logged-in-users", "field_text" => "Yes", "post_text" => "This allows you to control whether or not files are combined for logged-in users.<br/><i class='fa fa-warning fa-lg'></i> Careful, this may adversely affect some admin styles or functions that cannot be combined.", "display" => true, "depends_on" => "combine|active"
            ),
            "admin_users" => array(
                "type" => "checkboxes", "name" => "combine|admin-users", "label" => "", "label_for" => "admin-users", "field_text" => $user_roles, "values" => $user_roles_selected, "pre_text" => "Select the user role(s) that will experience combination.", "display" => true, "style" => "vertical-scroll", "depends_on" => "combine|combine-logged-in-users"
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "combine_js" => array(
                "type" => "checkbox", "name" => "combine|combine-js", "label" => "Combine Javascript Files?", "label_for" => "combine-js", "label_tip" => "A Javascript file contains instructions used by browsers to allow interaction with page elements or provide additional functionality.", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> By combining scripts, fewer requests will be made to the server, thus reducing load times.<br/><i class='fa fa-warning fa-lg'></i> Once enabled, check your site for operational and usability issues.", "display" => true, "depends_on" => "combine|active"
            ),
            "combine_css" => array(
                "type" => "checkbox", "name" => "combine|combine-css", "label" => "Combine CSS Stylesheets?", "label_for" => "combine-css", "label_tip" => "CSS stylesheets describe how the browser should layout the page content.", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> By combining stylesheets, fewer requests will be made to the server, thus reducing load times.<br/><i class='fa fa-warning fa-lg'></i> Once enabled, check your site for display issues.", "display" => true, "depends_on" => "combine|active"
            ),
            "combine_fonts" => array(
                "type" => "checkbox", "name" => "combine|combine-fonts", "label" => "Combine Google Fonts?", "label_for" => "combine-fonts", "label_tip" => "Join multiple font in a single file", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star fa-lg\" title=\"Recommended\"></i> Similar to other CSS stylesheets, requests for multiple Google fonts can be combined into one call.<br/><i class='fa fa-warning fa-lg'></i> Once enabled, check your site for any missing fonts.", "display" => true, "depends_on" => "combine|active", "premium" => true
            ),
        );

        $fieldset = $this->augment_fieldset($fieldset, "compress");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-combine" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Page Caching Page
     *
     * page caching on/off
     * cache pages for logged-in users on/off
     *      cache for specific users
     * gzip compression on/off
     * do not cache front page
     * do not cache page urls - premium -
     * do not cache post types - premium -
     * cache HTTPS pages - premium -
     */
    public function admin_page_page_caching(){
        $this->show_header("Page Caching");
        $slug = get_admin_url() . "admin.php?page=".SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("page_caching");

        $gzip_active = $this->system->is_gzip_active();

        $user_roles = $this->get_wp_roles();
        $user_roles_default = array_fill_keys(array_keys($user_roles), 0);
        $user_roles_selected = $this->get_val($this->settings['minify']['admin-users'], $user_roles_default);
        $user_roles_selected = array_merge($user_roles_default, $user_roles_selected);
        $cdn_active = ($this->settings['cdn']['active']);

        $arry = get_pages();
        $pages = array();
        foreach($arry as $item){
            $pages[$item->ID] = $item->post_title;
        }

        $arry = get_post_types('', 'names');
        $post_types = array();
        foreach($arry as $item){
            $post_types[$item] = $item;
        }

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-file fa-lg\"></i> Page Caching - Do Less Work"
            ),
            "page_caching_active" => array(
                "type" => "checkbox", "name" => "page-caching|active", "label" => "Enable Page Caching?", "label_for" => "active", "label_tip" => "By activating page caching " . SMART_CACHE_PLUGIN_NAME_PROPER . " saves a flattened version of the site's pages.  Cached pages load faster than processed pages, which will improve performance.", "field_text" => "Yes", "display" => true, "post_text" => (($this->settings['page-caching']['active'] == false) ? '<span class="error">Page caching is currently off.  We advise turning it on to benefit from the features below.</span>' : 'Page caching is on and ready!')
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "page_caching_logged_in_users" => array(
                "type" => "checkbox", "name" => "page-caching|page-caching-logged-in-users", "label" => "Enable Page Caching for Logged-in Users?", "label_for" => "page-caching-logged-in-users", "field_text" => "Yes", "post_text" => "This allows you to control whether or not pages are cached for logged-in users.<br/><i class='fa fa-warning fa-lg'></i> Careful, this may adversely affect some admin pages that cannot be cached.", "display" => true, "depends_on" => "page-caching|active"
            ),
            "admin_users" => array(
                "type" => "checkboxes", "name" => "page-caching|admin-users", "label" => "", "label_for" => "admin-users", "field_text" => $user_roles, "values" => $user_roles_selected, "pre_text" => "Select the user role(s) that will experience page caching.", "display" => true, "style" => "vertical-scroll", "depends_on" => "page-caching|page-caching-logged-in-users"
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "page_caching_gzip_compression" => array(
                "type" => "checkbox", "name" => "page-caching|gzip-compression", "label" => "Activate GZip Compression?", "label_for" => "gzip-compression", "label_tip" => "If your server is setup for it, " . SMART_CACHE_PLUGIN_NAME_PROPER . " can prepare your site files to be further compressed using GZip compression techniques.", "field_text" => "Yes", "post_text" => (($cdn_active) ? "<i class='fa fa-warning fa-lg'></i> Since you have activated the CDN support, check to ensure that your CDN edge is setup to compress files/objects<br/>" : "") . (($gzip_active) ? "<i class='fa fa-warning fa-lg'></i> Once enabled, check your site for operational issues." : "<i class='fa fa-warning fa-lg'></i> Unfortunately, a GZip compression module is not setup on your server.  Please contact your hosting provider and request that a GZip compression module be enabled (either mod_gzip.c or mod_deflate.c).  Once setup, you can active GZip Compression features here."), "class" => "mod-rewrite-check", "display" => true, "depends_on" => "page-caching|active"
            ),
            "exclude_front_page" => array(
                "type" => "checkbox", "name" => "page-caching|exclude-front-page", "label" => "Do Not Cache Front Page?", "label_for" => "exclude-front-page", "label_tip" => "If you have a frontpage slider or other specialized UX plugin that does not work well cached, enable this option.", "field_text" => "Yes", "post_text" => "If enabled, the front page will <strong>NOT</strong> be flattened and cached.", "display" => true, "depends_on" => "page-caching|active"
            ),
            "cache_https" => array(
                "type" => "checkbox", "name" => "page-caching|https", "label" => "Cache HTTPS pages?", "label_for" => "https", "label_tip" => "If checked, " . SMART_CACHE_PLUGIN_NAME_PROPER . " will process resources sent via the encrypted protocol as well.", "field_text" => "Yes", "post_text" => "If enabled, requests via HTTPS will be cached.", "display" => true, "depends_on" => "page-caching|active"
            ),
            "static_cache" => array(
                "type" => "checkbox", "name" => "page-caching|static-cache", "label" => "Serve Static Content?", "label_for" => "static-cache", "label_tip" => "This feature saves the page content to the cache and instructs the server to refer to it instead of allowing PHP to regenerate the content.", "field_text" => "Yes", "post_text" => "<i class=\"fa fa-star\" title=\"recommended\"></i> This feature will provide the system with cached page content, saving precious seconds, rather than having Wordpress process expensive database queries and potentially heavy PHP function calls.<br/><i class='fa fa-warning fa-lg'></i> If you encounter issues or require certain URLs to be generated dynamically, either disable this setting or exclude those URLs.", "display" => true, "depends_on" => "page-caching|active", "premium" => true
            ),
            "exclude_by_page" => array(
                "type" => "select", "name" => "page-caching|exclude-by-page", "label" => "Do Not Cache Specific Pages", "label_for" => "exclude-by-page", "label_tip" => "You can exclude specific pages from caching -- this is important if you are using an ecommerce plugin.", "options" => $pages, "add_blank_option" => true, "multiple" => true, "pre_text" => "Select which page or pages are <strong>NOT</strong> to be cached.<br/><i class=\"fa fa-star\" title=\"recommended\"></i> This is important if you are using an ecommerce plugin such as WooCommerce.", "display" => true, "depends_on" => "page-caching|active", "premium" => true
            ),
            "exclude_by_post_type" => array(
                "type" => "select", "name" => "page-caching|exclude-by-post-type", "label" => "Do Not Cache Specific Post Types", "label_for" => "exclude-by-post-type", "label_tip" => "You can select specific post types to exclude from caching.", "options" => $post_types, "add_blank_option" => true, "multiple" => true, "pre_text" => "Select which post type or post types are <strong>NOT</strong> to be cached.  For instance, you can exclude all blog posts but allow events details.", "display" => true, "depends_on" => "page-caching|active", "premium" => true
            ),
        );

        if(!$gzip_active) $fieldset['page_caching_gzip_compression']['readonly'] = true;

        $fieldset = $this->augment_fieldset($fieldset, "page_cache");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-page-caching" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>'.PHP_EOL;
        echo '</div>'.PHP_EOL;
        $this->show_footer();
    }

    /**
     * Browser Caching Page
     *
     * browser caching on/off
     * cookie select - premium -
     * do not cache specific user agents - premium -
     * keep a separate mobile cache
     * do not mobile devices - premium -
     * set last-modified header - premium -
     * set expires header (leverages browser caching) - premium -
     *      set js/xml expiry max age (in seconds)
     *      set css expiry max age (in seconds)
     *      set jpg/gif/png expiry max age (in seconds)
     *      set pdf expiry max age (in seconds)
     * set Add Vary: Accept-Encoding header - premium -
     * set entity tag - premium -
     */
    public function admin_page_browser_caching(){
        $this->show_header("Browser Caching");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("browser_caching");

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-globe fa-lg\"></i> Browser Caching - Help Your Browser Remember"
            ),
            "browser_caching_active" => array(
                "type" => "checkbox", "name" => "browser-caching|active", "label" => "Enable Browser Caching?", "label_for" => "active", "label_tip" => SMART_CACHE_PLUGIN_NAME_PROPER . " includes measures to improve how browsers load your site's content.", "field_text" => "Yes", "display" => true, "post_text" => (($this->settings['browser-caching']['active'] == false) ? '<span class="error">Browser caching is currently off.  We advise turning it on to benefit from the features below.</span>' : 'Browser caching is on and ready!')
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "exclude_user_agents" => array(
                "type" => "select", "name" => "browser-caching|exclude-user-agents", "label" => "Do Not Cache User Agents", "label_for" => "exclude-user-agents", "options" => array("Android Browser", "BlackBerry Browser", "Camino", "Chrome", "Curl", "Firefox", "IceCat", "IceWeasel", "IEMobile", "Internet Explorer", "Lynx", "Midori", "Opera", "Puffin", "Safari", "SamsungBrowser", "Silk", "TizenBrowser", "UCBrowser", "Vivaldi", "Wget"), "add_blank_option" => true, "multiple" => true, "pre_text" => "Select the user agents that you <strong>DO NOT</strong> want to benefit from caching.", "display" => true, "depends_on" => "browser-caching|active", "multiple" => true, "options_values_as_key" => true, "premium" => true
            ),
            "isolate_mobile_cache" => array(
                "type" => "checkbox", "name" => "browser-caching|isolate-mobile-cache", "label" => "Mobile Cache", "label_for" => "isolate-mobile-cache", "field_text" => "Yes, keep a separate mobile cache", "post_text" => "This option allows you to control mobile caching separately from desktop caching.", "display" => true, "depends_on" => "browser-caching|active"
            ),
            "exclude_mobile_devices" => array(
                "type" => "select", "name" => "browser-caching|exclude-mobile-devices", "label" => "Do Not Cache Mobile Devices", "label_for" => "exclude-mobile-devices", "label_tip" => "Select which mobile devices will see the unmodified site", "options" => array("- Any Mobile Device -", "- Any Tablet -", "Android", "BlackBerry", "iPhone", "iPad / iPod Touch", "Kindle", "Kindle Fire", "Playbook", "Tizen", "Windows Phone OS"), "add_blank_option" => true, "multiple" => true, "pre_text" => "Select the mobile devices that you <strong>DO NOT</strong> want to benefit from caching.", "display" => true, "depends_on" => "browser-caching|active", "multiple" => true, "options_values_as_key" => true, "premium" => true
            ),
            "cookie_block" => array(
                "type" => "textarea", "name" => "browser-caching|cookie-block", "label" => "Disable Caching Where Cookies Present?", "label_for" => "cookie-block", "post_text" => "Specify which cookies that if set will prevent the page from being cached (one cookie name per line).  This is useful to prevent cache-sensitive features such as geolocation from being cached.", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "set_last_modified_header" => array(
                "type" => "checkbox", "name" => "browser-caching|set-last-modified-header", "label" => "Last-Modified Header", "label_for" => "set-last-modified-header", "label_tip" => "Tell browsers when files were last changed", "field_text" => "Yes, add it", "post_text" => "If enabled, a \"last-modified\" HTTP header directive will be added to content requests, which causes the server to inform browsers when a file was last modified.  If file resource has not been modified, broswers will continue to use the cached version.", "class" => "mod-rewrite-check", "display" => true, "depends_on" => "browser-caching|active", "options_values_as_key" => true, "premium" => true
            ),
            "set_expires_header_js" => array(
                "type" => "select", "name" => "browser-caching|set-expires-header-js", "label" => "Leverage Browser Caching", "label_for" => "set-expires-header-js", "label_tip" => "Notify to browsers how long files will last", "pre_text" => "<i class=\"fa fa-file-text-o fa-lg\"></i> JS/XML Expiry Max Age", "options" => array(0 => "Cached in-session", 3600 => "1 Hour", 7200 => "2 Hours", 18000 => "5 Hours", 43200 => "12 Hours", 86400 => "1 Day", 604800 => "1 Week", 2592000 => "1 Month", 7776000 => "3 Months", 15552000 => "6 Months", 31536000 => "1 year"), "post_text" => "Choose how long script files should be cached by browsers.", "display" => true, "depends_on" => "browser-caching|active", "class" => "mod-rewrite-select", "premium" => true
            ),
            "set_expires_header_css" => array(
                "type" => "select", "name" => "browser-caching|set-expires-header-css", "label" => "", "label_for" => "set-expires-header-css", "pre_text" => "<i class=\"fa fa-file-code-o fa-lg\"></i> CSS Expiry Max Age", "options" => array(0 => "Cached in-session", 3600 => "1 Hour", 7200 => "2 Hours", 18000 => "5 Hours", 43200 => "12 Hours", 86400 => "1 Day", 604800 => "1 Week", 2592000 => "1 Month", 7776000 => "3 Months", 15552000 => "6 Months", 31536000 => "1 year"), "post_text" => "Choose how long stylesheet files should be cached by browsers.", "class" => "mod-rewrite-select", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
            "set_expires_header_imgs" => array(
                "type" => "select", "name" => "browser-caching|set-expires-header-imgs", "label" => "", "label_for" => "set-expires-header-imgs", "pre_text" => "<i class=\"fa fa-file-image-o fa-lg\"></i> JPEG/GIF/PNG/Audio/Video Expiry Max Age", "options" => array(0 => "Cached in-session", 3600 => "1 Hour", 7200 => "2 Hours", 18000 => "5 Hours", 43200 => "12 Hours", 86400 => "1 Day", 604800 => "1 Week", 2592000 => "1 Month", 7776000 => "3 Months", 15552000 => "6 Months", 31536000 => "1 year"), "post_text" => "Choose how long image, audio and media files should be cached by browsers.", "class" => "mod-rewrite-select", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
            "set_expires_header_pdf" => array(
                "type" => "select", "name" => "browser-caching|set-expires-header-pdf", "label" => "", "label_for" => "set-expires-header-pdf", "pre_text" => "<i class=\"fa fa-file-pdf-o fa-lg\"></i> Document/Feed Expiry Max Age", "options" => array(0 => "Cached in-session", 3600 => "1 Hour", 7200 => "2 Hours", 18000 => "5 Hours", 43200 => "12 Hours", 86400 => "1 Day", 604800 => "1 Week", 2592000 => "1 Month", 7776000 => "3 Months", 15552000 => "6 Months", 31536000 => "1 year"), "post_text" => "Choose how long document and feed files should be cached by browsers.", "class" => "mod-rewrite-select", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
            "set_accept_encoding_header" => array(
                "type" => "checkbox", "name" => "browser-caching|set-accept-encoding-header", "label" => "Vary: Accept Encoding Header", "label_for" => "set-accept-encoding-header", "label_tip" => "Tell servers to store uncompressed and compressed files", "field_text" => "Yes, add it", "post_text" => "Errors in some public servers, such as CDNs, may lead to compressed versions of your resources being served to users that don't support compression. Specifying the Vary: Accept-Encoding header instructs servers to store both a compressed and uncompressed version of the resource.", "class" => "mod-rewrite-check", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
            "set_etag_header" => array(
                "type" => "checkbox", "name" => "browser-caching|set-etag-header", "label" => "Entity Tag Header", "label_for" => "set-etag-header", "label_tip" => "Have the server add a unique timestamp to files", "field_text" => "Yes, add it", "post_text" => "An ETag or Entity Tag is an identifier, assigned by a web server, that represents a unique timestamp or version number of a resource.  Clients are able to compare the resource's ETags to determine if a cached copy is still usable, thus minimizing load times.", "class" => "mod-rewrite-check", "display" => true, "depends_on" => "browser-caching|active", "premium" => true
            ),
        );

        $fieldset = $this->augment_fieldset($fieldset, "browser_cache");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-browser-caching" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * CDN - premium -
     *
     * enable cdn on/off
     * include Attachments
     *      attachment file type(s) to include
     * include theme files
     *      theme file type(s) to include
     * include minified CSS/JS files
     * include custom files
     *      custom file(s) to include
     * exclude files
     *      file(s) to exclude
     * MaxCDN setup
     * Amazon CloudFront setup
     * CloudFlare setup
     */
    public function admin_page_cdn(){
        $this->show_header("Content Delivery Networks");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("cdn");

        $filtered_aws_s3_fieldset = apply_filters( SMART_CACHE_PLUGIN_NAME_CODE . '_admin_aws_s3_fieldset', null );
        if(! empty($filtered_aws_s3_fieldset) && is_array($filtered_aws_s3_fieldset))
            $aws_s3_fieldset = $filtered_aws_s3_fieldset;
        else
            $aws_s3_fieldset = array(
                "type" => "info", "label" => "Setup", "field_text" => "Download and install the <a href=\"" . $slug . "-addons\">CodeDragon Amazon S3 addon</a> today and connect your site to Amazon S3 and Amazon CloudFront", "premium" => true
            );

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-cloud-upload fa-lg\"></i> CDN - Service From the Cloud"
            ),
            "cdn_validated" => array(
                "type" => "hidden", "name" => "cdn|cdn-validated"
            ),
            "cdn_active" => array(
                "type" => "checkbox", "name" => "cdn|active", "label" => "Enable CDN Support?", "label_for" => "active", "label_tip" => SMART_CACHE_PLUGIN_NAME_PROPER . " includes measures to improve how browsers load your site's content.", "field_text" => "Yes", "display" => true, "post_text" => (($this->settings['cdn']['active'] == false) ? '<span class="error">CDN support is currently off.  We advise turning it on to benefit from the features below.</span>' : 'CDN support is on and ready!  Make sure you have setup a CDN service below.'), "premium" => true
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "include_theme_files" => array(
                "type" => "select", "name" => "cdn|include-theme-files", "label" => "Theme Files to Include", "label_for" => "include-theme-files", "label_tip" => "Choose which theme file types will be uploaded to and served from the CDN", "options" => array("css" => "Theme Stylesheets", "js" => "Theme Scripts", "xml" => "Theme XML Files"), "add_blank_option" => true, "post_text" => "Choose one or more theme file types to send to the CDN.", "display" => true, "multiple" => true, "depends_on" => "cdn|active", "premium" => true
            ),
            "include_minified_files" => array(
                "type" => "checkbox", "name" => "cdn|include-minified-files", "label" => "Include Optimized Files Outside the Theme?", "label_for" => "include-minified-files", "label_tip" => "Sync minified non-theme files with the CDN", "field_text" => "Yes", "post_text" => "Enabling this setting will upload and serve minified <strong>non-theme files</strong>, such as third-party plugin scripts, stylesheets and XML files, to and from the CDN.  Since the compressed script and stylesheet files created by " . SMART_CACHE_PLUGIN_NAME_PROPER . " may include files from anywhere in Wordpress, it will always be coordinated with the CDN.", "display" => true, "depends_on" => "cdn|active", "premium" => true
            ),
            "include_custom_files" => array(
                "type" => "textarea", "name" => "cdn|include-custom-files", "label" => "Include Custom Files", "label_for" => "include-custom-files", "label_tip" => "Provide a list of other text file paths you want to include", "class" => "col-md-12", "post_text" => "Provide list of relative file paths to <strong>include</strong> (one per line, no domain names, original file paths).  This is a good way to selectively include files or even whole file folders.", "display" => true, "depends_on" => "cdn|active", "premium" => true
            ),
            "exclude_files" => array(
                "type" => "textarea", "name" => "cdn|exclude-files", "label" => "Exclude Files", "label_for" => "exclude-custom-files", "label_tip" => "Provide a list of other text file paths you want to exclude", "class" => "col-md-12", "post_text" => "Provide a list of relative file paths to <strong>exclude</strong> (one per line, no domain names, original file paths).  This list will override the above settings.", "display" => true, "depends_on" => "cdn|active", "premium" => true
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "aws_h2" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-amazon\"></i> Amazon S3 CDN"
            ),
            "aws_s3_cdn" => $aws_s3_fieldset,
        );

        $fieldset = $this->augment_fieldset($fieldset, "cdn");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-cdn" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Tools
     *
     * Test preformance
     *      Google Pagespeed test
     *      GTMetrix test
     *      Pingdom test
     * Database optimization - premium -
     *      clear revisions
     *      clear transients
     *      clear auto-drafts
     *      clear trashed posts
     *      clear spam comments
     *      clear trashed comments
     * External Caches - premium -
     *      Clear Opcache
     *      Clear Varnish Cache
     * Location of cache folder
     * Settings
     *      Debug mode
     *      Import
     *      Export
     * Rollback upgrades
     */
    public function admin_page_tools(){
        $this->show_header("Tools &amp; More");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("tools");
        $homepage = str_replace(array("http://", "https://"), "", get_bloginfo('url'));
        $pageurl = $this->system->get_current_page_url(true, array("process", "_wpnonce"));

        $arry = get_post_types('', 'names');
        $post_types = array();
        foreach($arry as $item){
            $post_types[$item] = $item;
        }

        $gtmetrix_fieldset = array(
            "type" => "link", "name" => "tools|gtmetrix-test-start", "label" => "Run GTMetrix Test", "field_text" => "Run Test", "class" => "btn btn-secondary", "link" => "https://gtmetrix.com/", "post_text" => ((SMART_CACHE_IS_PREMIUM) ? "For more GTMetrix reporting, download the <a href=\"" . $slug . "-addons\">SmartCache GTMetrix addon</a>" : ""), "target" => "_blank", "field_text" => "Run Test"
        );
        $filtered_gtmetrix_fieldset = apply_filters( SMART_CACHE_PLUGIN_NAME_CODE . '_admin_gtmetrix_fieldset', null );
        if(! empty($filtered_gtmetrix_fieldset) && is_array($filtered_gtmetrix_fieldset))
            $gtmetrix_fieldset = $filtered_gtmetrix_fieldset;

        $pingdom_fieldset = array(
            "type" => "link", "name" => "tools|pingdom-test-start", "label" => "Run Pingdom Test", "field_text" => "Run Test", "class" => "btn btn-secondary", "link" => "https://tools.pingdom.com/", "post_text" => ((SMART_CACHE_IS_PREMIUM) ? "For more Pingdom reporting, download the <a href=\"" . $slug . "-addons\">SmartCache Pingdom addon</a>" : ""), "target" => "_blank", "field_text" => "Run Test"
        );
        $filtered_pingdom_fieldset = apply_filters( SMART_CACHE_PLUGIN_NAME_CODE . '_admin_pingdom_fieldset', null );
        if(! empty($filtered_pingdom_fieldset) && is_array($filtered_pingdom_fieldset))
            $pingdom_fieldset = $filtered_pingdom_fieldset;

        $pagespeed_fieldset = array(
            "type" => "link", "name" => "tools|pagespeed-test-start", "label" => "Run Pagespeed Test", "field_text" => "Run Test", "class" => "btn btn-secondary", "link" => "https://developers.google.com/speed/pagespeed/insights/", "post_text" => ((SMART_CACHE_IS_PREMIUM) ? "For more Pagespeed reporting, download the <a href=\"" . $slug . "-addons\">SmartCache Pagespeed addon</a>" : ""), "target" => "_blank", "field_text" => "Run Test"
        );
        $filtered_pagespeed_fieldset = apply_filters( SMART_CACHE_PLUGIN_NAME_CODE . '_admin_pagespeed_fieldset', null );
        if(! empty($filtered_pagespeed_fieldset) && is_array($filtered_pagespeed_fieldset))
            $pagespeed_fieldset = $filtered_pagespeed_fieldset;

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-line-chart\"></i> Test Performance"
            ),
            "p_1" => array(
                "type" => "p", "label" => "Test your site using these analysis tools to see how well " . SMART_CACHE_PLUGIN_NAME_PROPER . " has worked for you.", "post_text" => ((SMART_CACHE_IS_PREMIUM) ? '' : '  Hint: <strong>' . SMART_CACHE_PLUGIN_NAME_PROPER . ' Premium</strong> offers enhanced integration with CodeDragon addons that provide this kind of analysis without leaving this screen!')
            ),
            "gtmetrix_test" => $gtmetrix_fieldset,
            "pingdom_test" => $pingdom_fieldset,
            "google_pagespeed_test" => $pagespeed_fieldset,
            "bar_1" => array(
                "type" => "bar"
            ),
            "h2_2" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-database\"></i> Database Optimization"
            ),
            "p_2" => array(
                "type" => "p", "label" => "Even a well-performing site could still have 'moths' in the closet.  The following operations are designed to clean up weighty junk content in your Wordpress database.<br/><i class=\"fa fa-warning\"></i> Warning: These operations will delete records from your database.  <strong>Please make a backup copy of your database before starting!</strong>"
            ),
            "clean_revisions" => array(
                "type" => "link", "name" => "tools|clear-revisions", "label" => "Clean Revisions", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "While it's a good idea to keep a couple of revisions, more than that bloats the database.  You can clear them here.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "clean_transients" => array(
                "type" => "link", "name" => "tools|clear-transients", "label" => "Clean Transient Data", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "Transient data is data that is cached by Wordpress for quicker retrieval later.  They can be deleted safely.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "clean_auto_drafts" => array(
                "type" => "link", "name" => "tools|clear-auto-drafts", "label" => "Clean Auto-Drafts", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "An auto-draft is a periodic copy of a post that is created while that post is being edited.  While useful as a running backup, they are not automatically erased once the post has been saved.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "clean_trashed_posts" => array(
                "type" => "link", "name" => "tools|clear-trashed-posts", "label" => "Clean Trashed Posts", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "Trashed posts (blog or custom) are unnneded clutter in the database.  If you have no need of them, delete them here.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "clean_spam_comments" => array(
                "type" => "link", "name" => "tools|clear-spam-comments", "label" => "Clean Spam Comments", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "Use this tool to remove comments marked as spam.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "clean_trashed_comments" => array(
                "type" => "link", "name" => "tools|clear-trashed-comments", "label" => "Clean Trashed Comments", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Clean", "label_tip" => "This erases comments that are in the trash.", "premium" => true, "class" => "btn btn-secondary", "link" => "#", "working_hidden" => true
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "h2_3" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-file-zip-o\"></i> Caches"
            ),
            "reset_tuning" => array(
                "type" => "link", "name" => "tools|reset-tuning", "label" => "Reset File Tuning Cache", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Reset", "label_tip" => "Click to clear all tuning settings for Javascript and CSS files set in the Minify tab", "premium" => true, "class" => "btn btn-secondary", "working_hidden" => true
            ),
            "purge_opcache" => array(
                "type" => "link", "name" => "tools|purge-opcache", "label" => "Purge OPCache", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Purge", "label_tip" => "Click to flush the contents of Memcached memory (if installed)", "premium" => true, "class" => "btn btn-secondary", "working_hidden" => true
            ),
            "purge_varnish" => array(
                "type" => "link", "name" => "tools|purge-varnish", "label" => "Purge Varnish Cache", "pre_text" => "URL you want to purge: " . site_url() . " <input type=\"text\" value=\"\" id=\"purge_varnish_url\" />", "field_text" => "<i class=\"fa fa-trash-alt\"></i> Purge", "label_tip" => "Click to clear Varnish cache", "premium" => true, "class" => "btn btn-secondary", "working_hidden" => true
            ),
            "bar_3" => array(
                "type" => "bar"
            ),
            "h2_4" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-cogs\"></i> Operations"
            ),
            "clear_post_type_saving" => array(
                "type" => "select", "name" => "tools|clear-post-type-saving", "label" => "Clear Caches When Saving Post Types", "label_for" => "clear-post-type-saving", "options" => $post_types, "add_blank_option" => true, "multiple" => true, "pre_text" => "Select which post types, when saved, will trigger the caches to be cleared.", "display" => true, "premium" => true
            ),
            "cache_folder" => array(
                "type" => "text", "name" => "tools|custom-cache-folder", "label" => "Alternate Location of Cache Folder", "post_text" => "The default location is <em>" . SMART_CACHE_CACHE_FOLDER . "</em>. If specifying a new location DO NOT start with the domain name or only a " . DIRECTORY_SEPARATOR . ")", "class" => "wide-6", "placeholder" => SMART_CACHE_CACHE_FOLDER, "premium" => true
            ),
            "debug_mode" => array(
                "type" => "checkbox", "name" => "tools|debug-mode", "label" => "Enable debug mode?", "field_text" => "Yes", "post_text" => "Enable debug mode and place the shortcode <code>[sc-show-debug]</code> in any page content, or call <code>echo do_shortcode('[sc-show-debug]');</code> in PHP, to display <strong>" . SMART_CACHE_PLUGIN_NAME_PROPER . "</strong> debug information to logged in administrators.  When turned off the shortcode will display nothing.", "premium" => true
            ),
            "import" => array(
                "type" => "file", "name" => "tools|import-settings", "label" => "Import Settings", "class" => "btn btn-secondary", "post_text" => "Select a previously saved " . SMART_CACHE_PLUGIN_NAME . ".set file and click 'Save Changes'.", "premium" => true
            ),
            "export" => array(
                "type" => "link", "name" => "tools|export-settings", "label" => "Export Settings", "field_text" => "Export", "link" => wp_nonce_url($pageurl . '&process=export', SMART_CACHE_PLUGIN_NAME . '-process'),  "class" => "btn btn-secondary", "post_text" => ((SMART_CACHE_IS_PREMIUM) ? '  Note: Script/Stylesheet tunings are not saved.' : '')
            ),
            "deactivate_tasks" => array(
                "type" => "select", "name" => "tools|deactivate-tasks", "label" => "When Deactivating Plugin", "label_for" => "deactivate-tasks", "options" => array("clear-caches" => "Clear Caches", "delete-settings" => "Delete Settings"), "add_blank_option" => true, "multiple" => true, "pre_text" => "Select the tasks that are to be done when the plugin is deactivated.", "display" => true
            ),
        );

        $fieldset = $this->augment_fieldset($fieldset, "tools");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-tools" method="POST" enctype="multipart/form-data">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('save');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Reports
     *
     * Minification results
     * Combination results
     * Page Caching results
     * Performance test results
     * Database size
     * Attachments size
     * TTFB time
     */
    public function admin_page_reports(){
        global $wpdb;

        $this->show_header("Performance Reports");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("reports");

        $fieldset = $this->get_system_stats("reports");

        $fieldset = $this->augment_fieldset($fieldset, "reports");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-reports" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Help/support page
     */
    public function admin_page_help(){
        $this->show_header("Documentation &amp; Support");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("help");

        $documents = $this->get_codedragon_documents(SMART_CACHE_PRODUCT_DOCUMENT_CAT);
        $documents_html = '';
        if(empty($documents)){
            $documents_html = '<div class="' . SMART_CACHE_PLUGIN_NAME . '-doc"><p>No documents found...</p></div>';
        }else{
            foreach($documents as $article){
                $title = strip_tags($article['title']['rendered']);
                $documents_html .= '<div class="' . SMART_CACHE_PLUGIN_NAME . '-doc"><strong>' . $title . '</strong>' . $article['excerpt']['rendered'] . '<a href="' .$article['link']. '" target="_blank" class="btn btn-secondary">Read More &gt;</a></div>';
            }
        }

        $support = $this->get_codedragon_posts(SMART_CACHE_PRODUCT_SUPPORT_CAT);
        $support_html = '';
        if(empty($support)){
            $support_html = '<div class="' . SMART_CACHE_PLUGIN_NAME . '-support"><p>No support articles found...</p></div>';
        }else{
            foreach($support as $article){
                $title = strip_tags($article['title']['rendered']);
                $documents_html .= '<div class="' . SMART_CACHE_PLUGIN_NAME . '-support"><strong>' . $title . '</strong>' . $article['excerpt']['rendered'] . '</div>';
            }
        }

        $report = $this->get_system_stats("help");

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-question-circle fa-lg\"></i> Get the Support You Need"
            ),
            "p_1" => array(
                "type" => "p", "label" => "At CodeDragon we offer a variety of ways you can learn more about " . SMART_CACHE_PLUGIN_NAME_PROPER . ", tips to improve performance, and channels where we can help you to fix problems with its use."
            ),
            "documents" => array(
                "type" => "info", "label" => "<h3><i class=\"fa fa-book\"></i> Product Documents</h3>", "field_text" => $documents_html
            ),
            "bar_1" => array(
                "type" => "bar"
            ),
            "h3_2" => array(
                "type" => "h3", "label" => ""
            ),
            "support_articles" => array(
                "type" => "info", "label" => "<h3><i class=\"fa fa-file-text-o\"></i> Support Articles</h3>", "field_text" => $support_html
            ),
            "bar_2" => array(
                "type" => "bar"
            ),
            "h3_3" => array(
                "type" => "h3", "label" => "<i class=\"fa fa-ticket\"></i> Submit Support Ticket"
            ),
            "support_email" => array(
                "type" => "text", "name" => "help|support-email", "label" => "Email", "class" => "col-md-6", "reqd" => true
            ),
            "support_reason" => array(
                "type" => "select", "name" => "help|support-reason", "label" => "Help you need", "options" => (SMART_CACHE_IS_PREMIUM) ? array('- Select the type of support -', 'Usage issues', 'Errors encountered', 'Feature not working', 'Site compatibility problems', 'Programming/development question', 'Licensing problem', 'Billing inquiry', 'General feedback') : array('- Select the type of support -', 'General feedback'), "post_text" => ((SMART_CACHE_IS_PREMIUM) ? "" : "<i class=\"fa fa-star recommended\"></i> More support options are available in the <a href=\"" . SMART_CACHE_PLUGIN_URL . "\" target=\"_blank\">Premium version</a>..."), "reqd" => true, "options_values_as_key" => true
            ),
            "support_subject" => array(
                "type" => "text", "name" => "help|support-subject", "label" => "Subject", "class" => "col-md-12", "reqd" => true
            ),
            "support_descr" => array(
                "type" => "textarea", "name" => "help|support-descr", "label" => "Brief Description", "class" => "col-md-12", "reqd" => true
            ),
            "support_debug_page" => array(
                "type" => "text", "label" => "Page Info", "class" => "col-md-12", "value" => site_url(), "placeholder" => "Enter the URL from which you want debug info", "pre_text" => "If you're having trouble with a specific page, enter the URL below and click 'Grab Page Debug Info'.  The debug data from the page will be added to your support ticket.", "premium" => true
            ),
            "support_debug_button" => array(
                "type" => "link", "field_text" => "Grab Page Debug Info", "class" => "btn btn-secondary", "link" => '?sc-debug=' . md5('sc-debug-' . date("Ymdh")), "target" => "_blank", "working_hidden" => true, "premium" => true
            ),
            "support_info" => array(
                "type" => "textarea", "label" => "Report", "value" => $report, "pre_text" => "Also, the following non-private information will be sent with your ticket (you can click on the box below if you want to copy the contents).", "class" => "col-md-12", "premium" => true
            ),
            "support_note" => array(
                "type" => "info", "label" => "", "field_text" => "You can view your support tickets at <a href=\"" . SMART_CACHE_HOME_URL . "support/\" target=\"_blank\">" . SMART_CACHE_HOME_URL . "support/</a>"
            )
        );

        $fieldset = $this->augment_fieldset($fieldset, "help");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-help" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        $this->show_button('send_ticket');
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Return system statistics
     * @param  string $page 'reports' or 'help'
     * @return string
     */
    public function get_system_stats($page){
        global $wpdb, $wp_version, $wp_db_version, $required_php_version, $required_mysql_version;

        $server_type = $this->system->get_server_type();
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $arry = get_plugins();
        $plugins = array();
        foreach($arry as $item){
            $plugins[] = array('name' => $item['Name'], 'version' => $item['Version']);
        }

        $arry = get_post_types('', 'names');
        $post_types = array();
        foreach($arry as $item){
            if($item == "page") continue;
            $posts = $wpdb->get_results("SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_type` = '" . $item . "'");
            $count = 0;
            if(!empty($posts))
                $count = $posts[0]->count;
            $post_types[$item] = array("type" => $item, "count" => $count);
        }

        $arry = get_pages();
        $pages = array();
        foreach($arry as $item){
            $pages[$item->ID] = array("title" => $item->post_title);
        }

        $scripts = array();
        $this->cacheable_files_tuning = $this->system->get_cacheable_files_tuning();
        if(isset($this->cacheable_files_tuning['scripts']) && is_array($this->cacheable_files_tuning['scripts'])){
            foreach($this->cacheable_files_tuning['scripts'] as $file) $scripts[] = array("file" => $file['source'], "minified" => (($file['minify'] == 1) ? 'Yes' : 'No'), "deferred" => (($file['defer'] == 1) ? 'Yes' : 'No'), "async" => (($file['async'] == 1) ? 'Yes' : 'No'));
        }
        $styles = array();
        if(isset($this->cacheable_files_tuning['styles']) && is_array($this->cacheable_files_tuning['styles'])){
            foreach($this->cacheable_files_tuning['styles'] as $file) $styles[] = array("file" => $file['source'], "minified" => (($file['minify'] == 1) ? 'Yes' : 'No'), "async" => (($file['async'] == 1) ? 'Yes' : 'No'));
        }

        $cur_theme = wp_get_theme();
        $theme_parent = $cur_theme->parent();

        $media = array();
        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_parent` > 0 AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Attached", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_parent` = 0 AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Unattached", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_mime_type` LIKE 'image/%' AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Images", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_mime_type` LIKE 'audio/%' AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Audio", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_mime_type` LIKE 'video/%' AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Video", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_mime_type` = 'application/pdf' AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "PDFs", "count" => $media_results[0]->count);
        unset($media_results);

        $sql = "SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_mime_type` NOT LIKE 'image/%' AND `post_mime_type` NOT LIKE 'audio/%' AND `post_mime_type` NOT LIKE 'video/%' AND `post_mime_type` != 'application/pdf' AND `post_type` LIKE 'attachment'";
        $media_results = $wpdb->get_results($sql);
        $media[] = array("type" => "Others", "count" => $media_results[0]->count);
        unset($media_results);

        $revisions = $wpdb->get_results("SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_type` = 'revision'");
        $trash = $wpdb->get_results("SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_type` = 'trash'");
        $auto_drafts = $wpdb->get_results("SELECT COUNT(`ID`) AS `count` FROM `{$wpdb->posts}` WHERE `post_type` = 'auto-draft'");
        $transients = $wpdb->get_results("SELECT COUNT(`option_id`) AS `count` FROM `{$wpdb->options}` WHERE `option_name` LIKE '%\_transient\_%'");
        $cleanup = array(
            "revisions" => array("item" => "Revisions", "count" => $revisions[0]->count),
            "trash" => array("item" => "Trashed Posts", "count" => $trash[0]->count),
            "auto-drafts" => array("item" => "Auto-drafts", "count" => $auto_drafts[0]->count),
            "transients" => array("item" => "Transients", "count" => $transients[0]->count),
        );

        $self_info = $this->get_settings_summary();

        if($page == 'reports'){
            if($this->system->is_server_nginx()){
                $mod_rewrites = array(
                    "type" => "info", "label" => strtoupper(SMART_CACHE_NGINX_CONF) . " Writable?", "field_text" => (($this->system->conf_is_writable()) ? "Yes" : (($this->system->mod_rewrites_need_saving()) ? "<span class=\"error\">No (However, there are updates to it that " . SMART_CACHE_PLUGIN_NAME_PROPER . " is waiting to perform that can improve your site's performance)</span>" : "No")), "post_text" => "Normally, the file, <em>" . ABSPATH . SMART_CACHE_NGINX_CONF . "</em>, should not be writable to prevent malicious access to it, but when " . SMART_CACHE_PLUGIN_NAME_PROPER . "'s settings are changed, having the this file writable will allow " . SMART_CACHE_PLUGIN_NAME_PROPER . " to update it automatically.", "post_text" => "Note: You, or your server administrator, will also need to connect this file to the site by adding the following line to the the domain's config file stored in <em>/etc/nginx/sites-available:</em><p><code>include " . ABSPATH . "smartcacheopt.conf;</code></p><p>Updates to this config file will necessitate a server restart to effect the changes.</p>");
                $mod_rewrites_pending = array(
                    "type" => "info", "label" => "Pending Changes to Config?", "field_text" => (($this->system->mod_rewrites_need_saving()) ? "<span class=\"error\">Yes</span>" : "No"), "post_text" => (($this->system->mod_rewrites_need_saving()) ? "The following content needs to be added to the file, <em>" . ABSPATH . SMART_CACHE_NGINX_CONF . "</em>:<br/><textarea id=\"htaccess-mod\" class=\"col-md-11\">" . $this->system->prepare_conf() . "</textarea>" : ""));
            }else{
                $mod_rewrites = array(
                    "type" => "info", "label" => ".htaccess Writable?", "field_text" => (($this->system->htaccess_is_writable()) ? "Yes" : (($this->system->mod_rewrites_need_saving()) ? "No (However, there are updates to it that " . SMART_CACHE_PLUGIN_NAME_PROPER . " is waiting to perform that can improve your site's performance)" : "No")), "post_text" => "Normally, the .htaccess file should not be writable to prevent malicious access to it, but when " . SMART_CACHE_PLUGIN_NAME_PROPER . "'s settings are changed, having the .htaccess file writable will allow " . SMART_CACHE_PLUGIN_NAME_PROPER . " to update it automatically.");
                $mod_rewrites_pending = array(
                    "type" => "info", "label" => "Pending Changes to .htaccess?", "field_text" => (($this->system->mod_rewrites_need_saving()) ? "<span class=\"error\">Yes</span>" : "No"), "post_text" => (($this->system->mod_rewrites_need_saving()) ? "Open the " . ABSPATH . ".htaccess file in a text editor and place the following content before '# BEGIN WordPress':<br/><textarea id=\"htaccess-mod\" class=\"col-md-11\">" . $this->system->prepare_conf() . "</textarea>" : ""));
            }

            $info = array(
                "h2_1" => array(
                    "type" => "h2", "label" => "<i class=\"fa fa-server fa-lg\"></i> Server Status"
                ),
                "domain" => array(
                    "type" => "info", "label" => "Domain", "field_text" => site_url()
                ),
                "server_platform" => array(
                    "type" => "info", "label" => "Server Platform", "field_text" => $server_type
                ),
                "php_version" => array(
                    "type" => "info", "label" => "PHP Version", "field_text" => phpversion() . " (minimum required by Wordpress: " . $required_php_version . ")"
                ),
                "mysql_version" => array(
                    "type" => "info", "label" => "Database Version", "field_text" => ((function_exists('mysqli_get_client_version')) ? "MySQLi " . mysqli_get_client_version() . " (minimum required by Wordpress: " . $required_mysql_version . ")" : '--')
                ),
                "memory" => array(
                    "type" => "info", "label" => "PHP Memory", "field_text" => ini_get('memory_limit')
                ),
                "errors" => array(
                    "type" => "info", "label" => "Display Errors", "field_text" => ((ini_get('display_errors') == 1) ? 'Yes' : 'No')
                ),
                "mod_rewrites" => $mod_rewrites,
                "mod_rewrites_pending" => $mod_rewrites_pending,
                "h2_2" => array(
                    "type" => "h2", "label" => "<i class=\"fa fa-cogs fa-lg\"></i> " . SMART_CACHE_PLUGIN_NAME_PROPER
                ),
                "settings" => array(
                    "type" => "table", "label" => "Settings", "table_fields" => array("item" => "info", "value" => "info"), "table_field_cols" => array("item" => "6", "value" => "6"), "values" => $self_info, "max_height" => 200, "class" => "col-md-7"
                ),
                "h2_3" => array(
                    "type" => "h2", "label" => "<i class=\"fa fa-wordpress fa-lg\"></i> Site Info"
                ),
                "wp_version" => array(
                    "type" => "info", "label" => "Wordpress Version", "field_text" => $wp_version . ((is_multisite()) ? ' Multisite' : '')
                ),
                "wp_config" => array(
                    "type" => "info", "label" => "Configuration", "field_text" => "WP_DEBUG: " . ((WP_DEBUG) ? 'on' : 'off') . "<br/>ABSPATH: " . ABSPATH . "<br/>Site URL: " . site_url() . "<br/>Home URL: " . home_url()
                ),
                "javascripts" => array(
                    "type" => "table", "label" => "Javascript Files Tuning", "table_fields" => array("file" => "info", "minified" => "info", "deferred" => "info"), "table_field_cols" => array("file" => "8", "minified" => "2", "deferred" => "2"), "values" => $scripts, "max_height" => 200, "class" => "col-md-7"
                ),
                "stylesheets" => array(
                    "type" => "table", "label" => "CSS Stylesheets Tuning", "table_fields" => array("file" => "info", "minified" => "info", "async" => "info"), "table_field_cols" => array("file" => "8", "minified" => "2", "async" => "2"), "values" => $styles, "max_height" => 200, "class" => "col-md-7"
                ),
                "plugins" => array(
                    "type" => "table", "label" => "Plugins", "table_fields" => array("name" => "info", "version" => "info"), "table_field_cols" => array("name" => "8", "version" => "4"), "values" => $plugins, "max_height" => 200, "class" => "col-md-7"
                ),
                "themes" => array(
                    "type" => "info", "label" => "Current Theme", "field_text" => $cur_theme->get('Name') . " v. " . $cur_theme->get('Version') . (($cur_theme->update) ? ' (Update Available)' : '') . (($theme_parent !== false) ? '<br/>Theme Parent: ' . $theme_parent->get('Name') . " v. " . $theme_parent->get('Version') . (($theme_parent->update) ? ' (Update Available)' : '') : '')
                ),
                "media" => array(
                    "type" => "table", "label" => "Media Library", "table_fields" => array("type" => "info", "count" => "info"), "table_field_cols" => array("type" => "8", "count" => "4"), "values" => $media, "max_height" => 200, "class" => "col-md-7"
                ),
                "posts" => array(
                    "type" => "table", "label" => "Post Types", "table_fields" => array("type" => "info", "count" => "info"), "table_field_cols" => array("type" => "8", "count" =>"4"), "values" => $post_types, "max_height" => 200, "class" => "col-md-7"
                ),
                "pages" => array(
                    "type" => "table", "label" => "Pages", "table_fields" => array("title" => "info"), "table_field_cols" => array("title" => "12"), "values" => $pages, "max_height" => 200, "class" => "col-md-7"
                ),
                "h2_4" => array(
                    "type" => "h2", "label" => "<i class=\"fa fa-eraser fa-lg\"></i> Cleanup"
                ),
                "cleanup" => array(
                    "type" => "table", "label" => "Unneeded Data", "table_fields" => array("item" => "info", "count" => "info"), "table_field_cols" => array("item" => "8", "count" => "4"), "values" => $cleanup, "max_height" => 200, "class" => "col-md-7", "post_text" => "This data can be quickly removed in the <a href=\"" . $slug . "-tools\">Tools section</a>."
                ),
            );
        }elseif($page == 'help'){
            if($this->system->is_server_nginx()){
                $mod_rewrites = strtoupper(SMART_CACHE_NGINX_CONF) . " Writable: " . (($this->system->conf_is_writable()) ? "Yes" : (($this->system->mod_rewrites_need_saving()) ? "No (However, there are updates to it that " . SMART_CACHE_PLUGIN_NAME_PROPER . " is waiting to perform that are needed improve the site's performance)" : "No"));
                $mod_rewrites_pending = "Pending Changes to Config?: " . (($this->system->mod_rewrites_need_saving()) ? "Yes" : "No");
            }else{
                $mod_rewrites = ".htaccess Writable: " . (($this->system->htaccess_is_writable()) ? "Yes" : (($this->system->mod_rewrites_need_saving()) ? "No (However, there are updates to it that " . SMART_CACHE_PLUGIN_NAME_PROPER . " is waiting to perform that are needed improve the site's performance)" : "No"));
                $mod_rewrites_pending = "Pending Changes to Mod_rewrites?: " . (($this->system->mod_rewrites_need_saving()) ? "Yes" : "No");
            }

            $info = array();
            $info[] = "# SERVER STATUS #";
            $info[] = "";
            $info[] = "Domain: " . site_url();
            $info[] = "Server Platform: " . $server_type;
            $info[] = "PHP Version: " . phpversion() . " (minimum required by Wordpress: " . $required_php_version . ")";
            $info[] = "Database Version: " . ((function_exists('mysqli_get_client_version')) ? "MySQLi " . mysqli_get_client_version() . " (minimum required by Wordpress: " . $required_mysql_version . ")" : 'unknown');
            $info[] = "PHP Memory: " . ini_get('memory_limit');
            $info[] = "Display Errors: " . ((ini_get('display_errors') == 1) ? 'Yes' : 'No');
            $info[] = $mod_rewrites;
            $info[] = $mod_rewrites_pending;
            $info[] = "";
            $info[] = "# " . strtoupper(SMART_CACHE_PLUGIN_NAME_PROPER) . " #";
            $info[] = "";

            $info[] = "Settings: ";
            $settingss_info = "";
            foreach($self_info as $item) {
                if(strpos($item['item'], 'Secret Key') === false){
                    $settingss_info .= " - " . $item['item'] . " -- " . (is_array($item['value']) ? join(", ", $item['value']) : $item['value']) . PHP_EOL;
                }
            }
            $info[] = $settingss_info;

            $info[] = "";
            $info[] = "# SITE INFO #";
            $info[] = "";
            $info[] = "Wordpress Version: " . $wp_version . ((is_multisite()) ? ' Multisite' : '');
            $info[] = "Configuration: WP_DEBUG: " . ((WP_DEBUG) ? 'on' : 'off') . ", ABSPATH: " . ABSPATH . ", Site URL: " . site_url() . ", Home URL: " . home_url() . PHP_EOL;

            $info[] = "Javascript Files Tuning: ";
            $files_info = "";
            if(isset($this->cacheable_files_tuning['scripts']) && is_array($this->cacheable_files_tuning['scripts'])){
                foreach($this->cacheable_files_tuning['scripts'] as $file) $files_info .= " - " . $file['source'] . " -- " . (($file['minify'] == 1) ? 'Minified' : 'Natural') . ", " . (($file['defer'] == 1) ? 'Deferred' : 'As enqueued') . ", " . (($file['async'] == 1) ? 'Async' : 'As enqueued') . PHP_EOL;
            }
            $info[] = $files_info;

            $info[] = "CSS Stylesheets Tuning: ";
            $files_info = "";
            if(isset($this->cacheable_files_tuning['styles']) && is_array($this->cacheable_files_tuning['styles'])){
                foreach($this->cacheable_files_tuning['styles'] as $file) $files_info .= " - " . $file['source'] . " -- " . (($file['minify'] == 1) ? 'Minified' : 'Natural') . ", " . (($file['async'] == 1) ? 'Async' : 'As enqueued') . PHP_EOL;
            }
            $info[] = $files_info;

            $info[] = "Plugins: ";
            $plugins_info = "";
            foreach($plugins as $plugin) $plugins_info .= " - " . $plugin['name'] . " v." . $plugin['version'] . PHP_EOL;
            $info[] = $plugins_info;

            $info[] = "Current Theme: " . $cur_theme->get('Name') . " v. " . $cur_theme->get('Version') . (($cur_theme->update) ? ' (Update Available)' : '') . (($theme_parent !== false) ? ' -- Theme Parent: ' . $theme_parent->get('Name') . " v. " . $theme_parent->get('Version') . (($theme_parent->update) ? ' (Update Available)' : '') : '') . PHP_EOL;

            $info[] = "Media Library: ";
            $media_info = "";
            foreach($media as $medium) $media_info .= " - " . $medium['type'] . ": " . $medium['count'] . " item(s)" . PHP_EOL;
            $info[] = $media_info;

            $info[] = "Posts: ";
            $posts_info = "";
            foreach($post_types as $post_type) $posts_info .= " - " . $post_type['type'] . ": " . $post_type['count'] . " post(s)" . PHP_EOL;
            $info[] = $posts_info;

            $info[] = "Pages: ";
            $pages_info = "";
            foreach($pages as $page) $pages_info .= " - " . $page['title'] . PHP_EOL;
            $info[] = $pages_info;

            $info[] = "Cleanup: ";
            $cleanup_info = "";
            foreach($cleanup as $clean) $cleanup_info .= " - " . $clean['item'] . ": " . $clean['count'] . " item(s)" . PHP_EOL;
            $info[] = $cleanup_info;

            $info = join(PHP_EOL, $info);
        }

        return $info;
    }

    /**
     * Additional extensions
     */
    public function admin_page_addons(){
        $this->show_header(SMART_CACHE_PLUGIN_NAME_PROPER . " Add-ons");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("addons");

        $addons_html = $this->get_addons_html($this->get_addons_list());

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-plug fa-lg\"></i> " . SMART_CACHE_PLUGIN_NAME_PROPER . " Premium Add-ons"
            ),
            "p_1" => array(
                "type" => "p", "label" => "The following add-ons extend the native functionality of " . SMART_CACHE_PLUGIN_NAME_PROPER . " Premium."
            ),
            "html" => array(
                "type" => "raw", "field_text" => $addons_html
            )
        );

        $fieldset = $this->augment_fieldset($fieldset, "addons");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-addons" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Return the HTML containing addon products
     * @param  array $data
     * @return string
     */
    public function get_addons_html($data){
        $html = null;
        if(!empty($data)){
            $html = '<div class="row no-gutter">' . PHP_EOL;
            foreach($data as $item){
                $price = intval($item['edd_price']);
                $button_label = (($price > 0) ? 'Purchase' : 'Download');
                $requirements = str_replace(PHP_EOL, "</br>", $item['requirements_summary']);
                $content = preg_replace('/<\/?a[^>]*>.*<\/a>/i', '', $item['excerpt']['rendered']);

                $html2 = <<<EOT
<article id="post-{$item['id']}" class="col-md-4 addon-card">
    <div class="row">
        <div class="col-sm-12">
            <h3>{$item['title']['rendered']}</h3>
            <p>
                Version {$item['version']}
            </p>
            <p>
                {$content}
            </p>
            <div class="row">
                <div class="col-md-4"><label>Requirements:</label></div>
                <div class="col-md-8">{$requirements}</div>
            </div>
            <p class="pull-right">
                <a href="{$item['link']}" class="btn btn-secondary" target="_blank">{$button_label} &gt;</a>
            </p>
        </div>
    </div>
</article>
EOT;
                $html .= $html2;
            }
            $html.= '</div>' . PHP_EOL;
        }
        return $html;
    }

    /**
     * Plugin features
     */
    public function admin_page_features(){
        $this->show_header("Features");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("features");
        $plugin_name_proper = SMART_CACHE_PLUGIN_NAME_PROPER;
        $active_tab = array(
            "free" => (SMART_CACHE_IS_PREMIUM) ? '' : ' active',
            "premium" => (SMART_CACHE_IS_PREMIUM) ? ' active' : ''
        );

        $content = <<<EOT
    <p>{$plugin_name_proper} includes a ton of features not normally offered by its competition.  Even our free version is loaded</p>
    <table class="table-bordered table-striped feature-table col-md-12">
        <tr>
            <th>
                <p></p>
            </th>
            <th class="text-center{$active_tab['free']}">
                <p>Free Version</p>
            </th>
            <th class="text-center{$active_tab['premium']}">
                <p>Premium Version</p>
            </th>
        </tr>
        <tr>
            <td>Ready to go after activation with the most optimal setup</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Easy to configure without using techical jargon or confusing settings</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Minify Javascript and CSS stylesheet</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control whether logged-in users see minified content</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Removal of query-strings from requests</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Removal of only "ver=nnn" query-strings from requests</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Defer loading of render-blocking script files</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Asynchronous loading of stylesheets</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Combine script and stylesheet files</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control whether scripts and stylesheets are combined for logged-in users</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Full page caching</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Turn page caching on for logged-in users</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>GZip compression</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Enable/disable frontpage caching</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Provide a separate cache for mobile devices</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Browser caching</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Performance test links to GTMetrix, Pingdom and PageSpeed</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Performance reports</td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>

        <tr>
            <td>Minify HTML content</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Serve static HTML versions of pages</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Include Wordpress core files in minification</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Use of Google&reg; Closure as the minifier</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Prevent loading of jQuery Migrate</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Script and Stylesheet File Tuning. Control the minification of each file separetely</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Specify which script files to defer and/or load asynchronously</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Specify which stylesheets to load asynchronously</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Combine font requests in stylesheets</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>@import expansion in stylesheets</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Select which page or pages are not to be cached</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Select which post types are not to be cached</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear cache when specific post types are saved</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Select which cookies that, if set in the user's browser, will cause page to not be cached</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Page caching for HTTPS pages</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Select which user-agents will not receive cached files</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control the Last-Modified Header</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control Javascript/XML file Expiry Max Age</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control CSS stylesheet Expiry Max Age</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control JPEG, GIF, and PNG image file Expiry Max Age</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control PDF file Expiry Max Age</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control the Vary: Accept Encoding Header</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Control the Entity Tag (ETag) Header</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Amazon CloudFront CDN support</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Determine which theme files to include in CDN fileset</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Include minified Javascript/CSS files in CDN fileset</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Specify files to exclude from CDN fileset</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear revision records</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear transient data</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear auto-drafts</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear trashed posts</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear SPAM and trashed comments</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Clear OPCache and Varnish</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Woocommerce compatibility</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Developer friendly with hooks and filters</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Export and import plugin settings</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Customize the location of the cache folder</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Activate the plugin's debug mode and see what is processed on each page</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Available addons</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Premium support</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Send debug info from a specific site URL with your support tickets</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Choose what post-deactivation tasks are performed: clear caches and/or delete settings</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
        <tr>
            <td>Participate in our Premium Community forum</td>
            <td class="text-center"><i class="fa fa-times not-included"></i></td>
            <td class="text-center"><i class="fa fa-check included"></i></td>
        </tr>
    </table>
EOT;

        if(SMART_CACHE_IS_PREMIUM){
        }

        $fieldset = array(
            "h2_1" => array(
                "type" => "h2", "label" => "<i class=\"fa fa-magic\"></i>Everything ... and the Kitchen Sink"
            ),
            "contents" => array(
                "type" => "div", "label" => $content, "display" => true
            )
        );

        $fieldset = $this->augment_fieldset($fieldset, "features");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-features" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        if(! SMART_CACHE_IS_PREMIUM) {
            $this->show_button('get_premium');
        }
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Premium upgrades
     */
    public function admin_page_premium(){
        $this->show_header(SMART_CACHE_PLUGIN_NAME_PROPER . " Premium");
        $slug = get_admin_url() . "admin.php?page=" . SMART_CACHE_PLUGIN_NAME;
        $this->show_description();
        $this->show_menu("premium");

        $fieldset = array(
        );

        $fieldset = $this->augment_fieldset($fieldset, "premium");

        echo '<div id="' . SMART_CACHE_PLUGIN_NAME . '-body" class="clear">' . PHP_EOL;
        echo '<form id="' . SMART_CACHE_PLUGIN_NAME . '-form" action="' . $slug . '-premium" method="POST">' . PHP_EOL;
        $this->show_hidden_form_fields();
        $this->show_fields($fieldset);
        echo '</form>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        $this->show_footer();
    }

    /**
     * Execute admin AJAX calls
     */
    public function admin_ajax_handler(){
        global $wpdb;

        if(isset($_POST['get-hash']) && !empty($_POST['get-hash'])){
            echo md5($_POST['task']);
            die;
        }

        if(empty($_POST['task']) || empty($_POST['key']) || $_POST['key'] != md5($_POST['task'])) {
            echo json_encode(array('success' => false, 'msg' => 'AJAX Task key invalid'));
        }else{
            $success = false;
            $msg = null;
            switch(strtolower($_POST['task'])){
                case 'get-widget-stats':
                    $msg = $this->get_widget_stats_html(false);
                    $success = true;
                    break;
                case 'start-revisions-clean':
                    list($success, $msg) = $this->system->clean_revisions();
                    break;
                case 'start-transients-clean':
                    list($success, $msg) = $this->system->clean_transients();
                    break;
                case 'start-auto-drafts-clean':
                    list($success, $msg) = $this->system->clean_auto_drafts();
                    break;
                case 'start-trashed-posts-clean':
                    list($success, $msg) = $this->system->clean_trashed_posts();
                    break;
                case 'start-spam-comments-clean':
                    list($success, $msg) = $this->system->clean_spam_comments();
                    break;
                case 'start-trashed-comments-clean':
                    list($success, $msg) = $this->system->clean_trashed_comments();
                    break;
                case 'clear-opcache':
                    list($success, $msg) = $this->system->purge_opcache();
                    break;
                case 'clear-varnish':
                    list($success, $msg) = $this->system->purge_varnish();
                    break;
                case 'clear-tuning':
                    list($success, $msg) = $this->system->reset_tuning();
                    break;
                case 'get-debug-info':
                    list($success, $msg) = $this->system->get_page_contents($_POST['data'], 'SmartCache Debug Mode(.*)<\/div>');
                    if($success) $msg = '# PAGE DEBUG INFO #' . $msg;
                    break;
                case 'send-ticket':
                    list($success, $msg) = $this->system->send_ticket($_POST['data']);
                    break;
                case 'continue-scan-site':
                    list($success, $msg) = $this->system->do_scan_file();
                    break;
                default:
                    $filtered_retn = apply_filters( 'smart_cache_admin_ajax_handler', array('task' => $_POST['task'], 'success' => $success, 'msg' => $msg) );
                    break;
            }

            if(! empty($filtered_retn) && is_array($filtered_retn))
                echo json_encode($filtered_retn);
            else
                echo json_encode(array('success' => $success, 'msg' => $msg));
        }

        wp_die();
    }

    /**
     * Display fields on settings form
     *
     * @param array $fieldset       An array of arguments to populate the settings fields.
     * @param boolean $show_label
     */
    public function show_fields($fieldset, $show_label = true){
        if(!is_array($fieldset)) return false;

        $field_name_root = SMART_CACHE_PLUGIN_NAME.'-settings';

        foreach($fieldset as $id => $field){
            if(!is_array($field)) continue;

            if(isset($field['display']) && !$field['display']) continue;
            if(empty($id)) continue;
            if(empty($field['type'])) continue;

            $_id                    = ' id="' . $id . '_' . $field['type'] . '"';
            $field['label_for']     = isset($field['label_for']) ? ' for="' . $field['label_for'] . '_' . $field['type'] . '"' : ' for="' . $id . '_' . $field['type'] . '"';
            $field['name']          = isset($field['name']) ? $field['name'] : $field['label_for'];
            $parent                 = isset($field['parent']) ? 'data-parent="' . sanitize_html_class($field['parent']) . '"' : null;
            $reqd                   = isset($field['reqd']);
            $class                  = sanitize_html_class($field['name']) . (isset($field['class']) ? ' ' . $field['class'] : '') . (($parent) ? ' has-parent' : null);
            if($field['type'] == 'table')
                $class .= ' table table-striped table-condensed';
            $_class                 = ' class="' . trim($class) . '"';

            $label                  = (isset($field['label']) && $show_label) ? $field['label'] . (($reqd) ? '<abbr class="required" title="required"> *</abbr>' : '') : '';
            $label_screen           = isset($field['label_screen']) ? $field['label_screen'] : $label;
            $label_tip              = isset($field['label_tip']) ? '<i class="fa fa-info-circle" data-toggle="tooltip" title="' . $field['label_tip'] . '"></i>' : null;

            $field_text             = isset($field['field_text']) ? $field['field_text'] : null;
            $default                = isset($field['default']) ? $field['default'] : '';
            $readonly               = isset($field['readonly']) && $field['readonly'] ? ' readonly="readonly" disabled="disabled"' : '';
            $disabled               = !empty($field['disabled']) && $field['disabled'] ? ' disabled="disabled"' : '';
            $placeholder            = isset($field['placeholder']) ? 'placeholder="' . $field['placeholder'] . '" ' : '';
            $depends_on             = isset($field['depends_on']) ? $field['depends_on'] : '';

            $cells                  = (isset($field['cells']) && is_array($field['cells'])) ? $field['cells'] : array();
            $sub_fieldset           = isset($field['sub_fieldset']) ? $field['sub_fieldset'] : null;
            $sub_fieldset_labels    = isset($field['sub_fieldset_labels']) ? (boolean)$field['sub_fieldset_labels'] : true;
            $sub_fieldset_border    = isset($field['sub_fieldset_border']) ? (boolean)$field['sub_fieldset_border'] : true;
            $cols                   = isset($field['cols']) ? (int) $field['cols'] : 50;
            $rows                   = isset($field['rows']) ? (int) $field['rows'] : 5;
            $label_cols             = isset($field['label_cols']) ? $field['label_cols'] : 'col-md-3';
            $value_cols             = isset($field['value_cols']) ? $field['value_cols'] : 'col-md-' . (($show_label) ? '9' : '12');
            $label_grid_cols        = ' class="' . $label_cols . '"';
            if(empty($sub_fieldset)){
                $value_grid_cols    = ' class="' . $value_cols . '"';
            }else{
                $value_grid_cols    = ' class="' . $value_cols . ((!empty($sub_fieldset && $sub_fieldset_border)) ? ' sub_fieldset' : '') . ' ' . $class . '"';
            }
            $multiple               = isset($field['multiple']) ? ' multiple="multiple"' : '';
            $target                 = isset($field['target']) ? ' target="' . $field['target'] . '"' : '';
            $link                   = isset($field['link']) ? ' href="' . $field['link'] . '"' : '';

            $premium_feature        = (isset($field['premium']) && $field['premium'] && !SMART_CACHE_IS_PREMIUM) ? ' premium-feature' : '';
            $accordion              = isset($field['accordion']) && $field['accordion'] ? ' accordion' : '';

            $group                  = '';
            $value                  = isset($field['value']) ? $field['value'] : '';
            $values                 = isset($field['values']) ? $field['values'] : array();
            $table_fields           = isset($field['table_fields']) ? $field['table_fields'] : array();
            $table_field_cols       = isset($field['table_field_cols']) ? $field['table_field_cols'] : array();
            $table_field_titles     = isset($field['table_field_titles']) ? $field['table_field_titles'] : array();
            $rel_key                = '';

            $max_height             = isset($field['max_height']) ? $field['max_height'] : '';
            $style                  = isset($field['style']) ? $field['style'] : '';

            if(empty($value)){
                if(strpos($field['name'], '|') !== false){
                    $split = explode('|', $field['name']);
                    $group = $split[0];
                    $field['name'] = $split[1];
                    if(isset($this->settings[$group][$field['name']])){
                        $value = $this->settings[$group][$field['name']];
                    }
                    $field_name_html = $field_name_root.'['.$group.']['.$field['name'].']';
                    $rel_key .= ' data-control="' . $group . '-' . $field['name'] . '"';
                }else{
                    if(isset($this->settings[$field['name']])){
                        $value = $this->settings[$field['name']];
                    }
                    $field_name_html = $field_name_root.'['.$field['name'].']';
                    $rel_key .= ' data-control="' . $field['name'] . '"';
                }
            }
            if(empty($value)) $value = $default;
            if(!is_array($value) && strtolower($value) == 'array') $value = array();

            $group = '';
            if(!empty($depends_on)){
                if(strpos($depends_on, '|') !== false){
                    $split = explode('|', $depends_on);
                    $group = $split[0];
                    $depends_on = $split[1];
                    $rel_key .= ' data-depends-on="' . $group . '-' . $depends_on.'"';
                    if(empty($this->settings[$group][$depends_on])){
                        $disabled = ' disabled="disabled"';
                    }
                }else{
                    $rel_key .= ' data-depends-on="' . $depends_on.'"';
                    if(empty($this->settings[$depends_on])){
                        $disabled = ' disabled="disabled"';
                    }
                }
            }

            echo '<div class="row' . $premium_feature . $accordion . '">'.PHP_EOL;
            if(!empty($premium_feature)) echo '<div class="premium-feature-text">Premium Feature</div>';

            if (isset($field['fieldset']) && $field['fieldset']) {
                printf('<fieldset class="fieldname-%1$s fieldtype-%2$s %3$s">',
                    sanitize_html_class($field['name']),
                    sanitize_html_class($field['type']),
                    isset($field['parent']) ? 'fieldparent-' . sanitize_html_class($field['parent']) : ''
                );
            }

            $pre_text = '';
            if (isset($field['pre_text'])){
                $pre_text = '<p class="' . $id . '-pre-text">' . $field['pre_text'] . '</p>' . PHP_EOL;
            }
            $post_text = '';
            if (isset($field['post_text'])){
                $post_text = '<p class="' . $id . '-post-text">' . $field['post_text'] . '</p>' . PHP_EOL;
            }
            $post_field = '';
            if (isset($field['post_field'])){
                $post_field = '<span class="' . $id . '-post-field">' . $field['post_field'] . '</span>' . PHP_EOL;
            }
            $field_working_icon = '';
            if (isset($field['working']) && $field['working'] == true){
                $field_working_icon = '<i id="' . $id . '_' . $field['type'] . '-working" class="fa fa-spinner fa-spin fa-lg"></i>';
            }
            if (isset($field['working_hidden']) && $field['working_hidden'] == true){
                $field_working_icon = '<i id="' . $id . '_' . $field['type'] . '-working" class="fa fa-spinner fa-spin fa-lg hidden"></i>';
            }

            switch($field['type']){
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'p':
                case 'div':
                    ?>
                    <div class="col-md-12">
                        <?php echo $pre_text ?>
                        <<?php echo $field['type']?>><?php echo $label?></<?php echo $field['type']?>>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'hidden':
                    ?>
                    <input<?php echo $_id ?> type="hidden" name="<?php echo $field_name_html ?>" value="<?php echo $value; ?>" />
                    <?php
                    break;
                case 'cells':
                    foreach($cells as $cell){
                        echo $cell;
                    }
                    break;
                case 'a':
                case 'anchor':
                case 'link':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <a<?php echo $_id . $link . $target . $_class?>><?php echo $field['field_text'] . $field_working_icon?></a>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'bar':
                    ?>
                    <div class="<?php echo $field_name_root ?>-bar"></div>
                    <?php
                    break;
                case 'info':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <p<?php echo $_id . $_class; ?>><?php echo $field_text ?></p>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'html':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <div<?php echo $_id . $_class; ?>><?php echo $field_text ?></div>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'editor':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <?php wp_editor(
                            $value,
                            $id,
                            array(
                                "textare_name" => $field_name_html
                            )
                        ); ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'raw':
                    echo $field_text;
                    break;
                case 'text':
                case 'number':
                case 'email':
                    $number_options = 'number' === $field['type'] ? ' min="0" class="small-text"' : '';
                    $autocomplete   = in_array($field['name'], array('consumer_key', 'consumer_email'), true) ? ' autocomplete="off"' : '';
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <input<?php echo $_id . $_class . $autocomplete . $rel_key . $disabled; ?> type="<?php echo $field['type']; ?>"<?php echo $number_options; ?> name="<?php echo $field_name_html ?>" value="<?php echo $value; ?>" <?php echo $placeholder; ?><?php echo $readonly; ?> />
                        <?php echo $post_field ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'password':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <input<?php echo $_id . $_class . $rel_key . $disabled; ?> type="password" name="<?php echo $field_name_html ?>" value="<?php echo $value; ?>" <?php echo $readonly; ?> />
                        <?php echo $post_field ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'textarea':
                    if(is_array($value)) $value = join(PHP_EOL, $value);
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <textarea<?php echo $_id . $_class . $rel_key . $disabled; ?> name="<?php echo $field_name_html ?>" cols="<?php echo $cols; ?>" rows="<?php echo $rows; ?>"<?php echo $readonly; echo $placeholder; ?>><?php echo esc_html($value); ?></textarea>
                        <?php echo $post_field ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'file':
                    if ($show_label){
                        if (isset($field['label_screen'])) { ?>
                        <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                        <?php } ?>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <input type="file"<?php echo $_id . $_class . $rel_key . $disabled; ?> name="<?php echo $field['name'] ?>" />
                        <?php echo $post_field ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'checkbox':
                    if ($show_label){
                        if (isset($field['label_screen'])) { ?>
                        <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                        <?php } ?>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <input type="checkbox"<?php echo $_id . $_class . $rel_key . $disabled; ?> name="<?php echo $field_name_html ?>" value="1"<?php echo $readonly; ?> <?php checked($value, 1); ?> <?php echo $parent; ?> /> <?php echo $field_text ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'checkboxgroup':
                case 'checkboxes':
                    if ($show_label){
                        if (isset($field['label_screen'])) { ?>
                        <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                        <?php } ?>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php
                        echo $pre_text;
                        if($style == 'vertical-scroll'){
                            echo '<ul' . $_id . ' class="scrollbox">' . PHP_EOL;
                        }
                        if(is_array($values) && is_array($field_text)){
                            foreach($field_text as $key => $title){
                                if(!isset($field_text[$key])) $field_text[$key] = null;
                                if(!isset($value[$key])) $value[$key] = false;

                                if($style == 'vertical-scroll') echo '<li class="scrollitem">';
                        ?>
                        <input type="checkbox"<?php echo $_id . $_class . $rel_key . $disabled; ?> name="<?php echo $field_name_html ?>[<?php echo $key ?>]" value="1"<?php echo $readonly; ?> <?php checked($value[$key], 1); ?> <?php echo $parent; ?> /> <?php echo $field_text[$key] . '&nbsp;&nbsp;';
                                if($style == 'vertical-scroll') echo '</li>' . PHP_EOL;
                            }
                        }
                        if($style == 'vertical-scroll'){
                            echo '</ul>' . PHP_EOL;
                        }
                        echo $field_working_icon;
                        echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'list':
                case 'menu':
                case 'select':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text; ?>
                        <select<?php echo $_id . $_class . $rel_key . $disabled . $multiple; ?> name="<?php echo $field_name_html . ((! empty($multiple)) ? '[]' : '') ?>"<?php echo $readonly; ?> <?php echo $parent; ?>>

                            <?php
                            if(is_array($value)) $value = array_filter($value);
                            if(is_array($field['options'])){
                                if(isset($field['add_blank_option'])) $field['options'] = array('' => '- Select -') + $field['options'];
                                if(isset($field['options_values_as_key']) && true == $field['options_values_as_key']){
                                    foreach ($field['options'] as $val) { ?>
                                        <option value="<?php echo $val; ?>"<?php
                                        if(is_array($value)){
                                            if(in_array($val, $value)) echo ' selected="selected"';
                                        }else{
                                            if(strtolower($val) == strtolower($value)) echo ' selected="selected"';
                                        }
                                        ?>><?php echo $val; ?></option>
                                    <?php }
                                }else{
                                    foreach ($field['options'] as $val => $title) { ?>
                                        <option value="<?php echo $val; ?>"<?php
                                        if(is_array($value)){
                                            if(in_array($val, $value)) echo ' selected="selected"';
                                        }else{
                                            if(strtolower($val) == strtolower($value)) echo ' selected="selected"';
                                        }
                                        ?>><?php echo $title; ?></option>
                                    <?php }
                                }
                            }
                            ?>
                        </select>
                        <?php echo $post_field ?>
                        <?php if(! empty($multiple)) echo ' <i class="fa fa-info-circle" data-toggle="tooltip" title="CTRL/CMD+click to select more than one"></i>'; ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'radio':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $field['label_screen']; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <?php foreach ($field['options'] as $val => $title) { ?>
                            <input type="radio"<?php echo $_id . $_class . $rel_key . $disabled; ?> name="<?php echo $field_name_html ?>[]" value="<?php echo $val ?>"<?php echo $readonly; ?> <?php checked($value, 1); ?> <?php echo $parent; ?> /><?php echo $title; ?>&nbsp;
                        <?php } ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'button':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <button<?php echo $_id . $_class . $disabled; ?>><?php echo $field['field_text']?></button>
                        <?php echo $post_field ?>
                        <?php echo $field_working_icon ?>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'table':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols ?>>
                        <?php echo $pre_text ?>
                        <?php
                        $tbody_style = '';
                        $thead_style = '';
                        $tr_style = '';
                        $th_style = '';
                        $td_style = '';
                        if(! empty($max_height)){
                            $tbody_style = ' style="max-height: ' . $max_height . 'px; overflow-y: auto; width: 100%; display: block;"';
                            $thead_style = ' style="display: block;"';
                            $tr_style = ' style="display: block;"';
                            $th_style = ' style="float: left; display: block;"';
                            $td_style = ' style="float: left; display: block;"';
                        }
                        ?>
                        <div>
                            <table<?php echo $_id . $_class; ?> style="width: 100%">
                                <thead<?php echo $thead_style ?>>
                                    <tr<?php echo $tr_style ?>>
                                    <?php foreach($table_fields as $header => $type){
                                        $th = ((empty($table_field_titles[$header])) ? ucwords($header) : $table_field_titles[$header]);
                                        $col_class = ((isset($table_field_cols[$header])) ? ' class="col-md-' . $table_field_cols[$header] . '"' : '');
                                        ?>
                                        <th<?php echo $th_style . $col_class ?>><?php echo $th ?></th>
                                    <?php } ?>
                                    </tr>
                                </thead>
                                <tbody<?php echo $tbody_style ?>>
                                    <?php foreach($values as $row_id => $row){ ?>
                                    <tr<?php echo $tr_style ?>>
                                        <?php foreach($table_fields as $header => $type){
                                            $col_class = ((isset($table_field_cols[$header])) ? ' class="col-md-' . $table_field_cols[$header] . '"' : '');
                                            $data = ((!empty($row['_data'])) ? ' data-value="' . $row['_data'] . '" title="' . $row['_data'] . '"' : '');
                                            echo '<td' . $td_style . $col_class . '>';
                                            switch($type){
                                                case 'info':
                                                    $tag = $row[$header];
                                                    if(is_array($tag)){
                                                        $tag = join(PHP_EOL, $tag);
                                                    }
                                                    if(empty($tag)) $tag = '--';
                                                    echo '<span class="' . $id . '-' . $header . '"' . $data . '>' . $tag . '</span>';
                                                    break;
                                                case 'checkbox':
                                                    echo '<input type="checkbox" id="' . $id . '-' . $row_id . '-' . $header . '" name="' . $field_name_html . '[' . $row_id . '][' . $header . ']" value="1"' . ((boolval($row[$header]) !== false) ? ' checked="checked"' : '') . $rel_key . $disabled . ' />';
                                                    break;
                                            }
                                            echo '</td>';
                                        } ?>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php echo $post_text ?>
                    </div>
                    <?php
                    break;
                case 'group':
                    if ($show_label){
                    ?>
                    <legend class="screen-reader-text"><span><?php echo $label_screen; ?></span></legend>
                    <label<?php echo $field['label_for'] . $label_grid_cols ?>><?php echo $label . $label_tip ?></label>
                    <?php } ?>
                    <div<?php echo $value_grid_cols . $_class ?>>
                        <?php
                        echo $pre_text;
                        if(!empty($sub_fieldset))
                            $this->show_fields($sub_fieldset, $sub_fieldset_labels);
                        else
                            echo 'Element \'sub_fieldset\' is missing';
                        echo $post_text;
                        ?>
                    </div>
                    <?php
                    break;
                case 'modal':
                case 'dialog':
                    if(!empty($field['modal_button'])){
                        $btn_class = ((empty($field['modal_button_class'])) ? 'btn btn-secondary' : $field['modal_button_class']);
                    ?>
                    <button type="button" class="<?php echo $btn_class ?>" data-toggle="modal" data-target="#<?php echo $id ?>"><?php echo $field['modal_button'] ?></button>
                    <?php
                    }

                    $header = ((empty($field['modal_header'])) ? '' : $field['modal_header']);
                    $body   = ((empty($field['modal_body'])) ? '' : $field['modal_body']);
                    $footer = ((empty($field['modal_footer'])) ? '' : $field['modal_footer']);
                    ?>

                    <div id="<?php echo $id ?>" class="modal <?php echo $class ?>" role="dialog">
                        <div class="modal-dialog">
                            <!-- Modal content-->
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h3 class="modal-title"><strong><?php echo $header; ?></strong></h3>
                                </div>
                                <div class="modal-body">
                                    <?php echo $body ?>
                                </div>
                                <div class="modal-footer">
                                    <?php echo $footer ?>
                                </div>
                            </div>

                        </div>
                    </div>
                    <?php
                    break;
            }

            if (isset($field['fieldset']) && $field['fieldset']) {
                echo '</fieldset>';
            }

            echo '</div>'.PHP_EOL;
        }
    }

    /**
     * Adds menu parent or submenu item.
     * @param string $name the label of the menu item
     * @param string $href the link to the item (settings page or ext site)
     * @param string $parent Parent label (if creating a submenu item)
     *
     * @return void
     * */
    public function admin_bar_render_item($name, $href = '', $parent = '', $custom_meta = array()) {
        global $wp_admin_bar;

        if (!is_super_admin() || !is_object($wp_admin_bar)) {
            return;
        }

        // Generate ID based on the plugin name and the name supplied.
        $id = sanitize_key(SMART_CACHE_PLUGIN_NAME . '-' . $name);

        // Generate the ID of the parent.
        $parent = empty($parent) ? false : sanitize_key(SMART_CACHE_PLUGIN_NAME . '-' . $parent);

        // links from the current host will open in the current window
        $meta = strpos($href, site_url()) !== false || $href == '#' ? array() : array('target' => '_blank'); // external links open in new tab/window
        $meta = array_merge($meta, $custom_meta);

        $args = array(
            'parent' => $parent,
            'id' => $id,
            'title' => $name,
            'href' => $href,
            'meta' => $meta,
        );
        $wp_admin_bar->add_node($args);
    }

    /**
     * Allow external systems to add/insert a fieldset-structured array into the page fieldset before displaying
     * @param  array $fieldset
     * @param  string $page
     * @return array
     */
    private function augment_fieldset($fieldset, $page){
        $add_fieldset = null;
        $add_fieldset = apply_filters( SMART_CACHE_PLUGIN_NAME_CODE . '_add_' . $page . '_fieldset', $add_fieldset );
        if(is_array($add_fieldset)){
            if(!empty($add_fieldset['key']) && !empty($add_fieldset['field'])){
                $key = $add_fieldset['key'];
                $field = $add_fieldset['field'];
                if(is_string($key) && is_array($field)){
                    $field_inserted = false;
                    if(!empty($add_fieldset['after'])){
                        $after = $add_fieldset['after'];
                        if(array_key_exists($after, $fieldset)){
                            $offset = array_search($after, array_keys($fieldset)) + 1;
                            $array_before = array_slice($fieldset, 0, $offset);
                            $array_after  = array_slice($fieldset, $offset);
                            $fieldset = array_merge($array_before, array($key => $field), $array_after);
                            $field_inserted = true;
                        }
                    }

                    if(!$field_inserted){
                        $fieldset = array_merge($fieldset, array($key => $field));
                    }
                }
            }
        }
        return $fieldset;
    }
}
?>