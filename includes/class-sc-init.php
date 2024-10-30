<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Plugin initiator class
 *
 * @since      1.0.0
 * @package    Smart_Cache
 * @subpackage smart-cache/includes
 * @author     Dragon Slayer <info@codedragon.ca>
 */

defined('ABSPATH') or die('Nice try...');

class Smart_Cache {
	protected $loader;
	protected $plugin_name;
	protected $plugin_name_proper;
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the Dashboard and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_core_hooks();
		$this->define_cron_hooks();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		set_error_handler(array($this, "custom_error_handler"));
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Smart_Cache_Loader. Orchestrates the hooks of the plugin.
	 * - Smart_Cache_i18n. Defines internationalization functionality.
	 * - Smart_Cache_Admin. Defines all hooks for the dashboard.
	 * - Smart_Cache_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-loader.php';
		$this->loader = new Smart_Cache_Loader();

		if(SMART_CACHE_IS_PREMIUM){
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-system-p.php';
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-license.php';
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-base-p.php';
		}else{
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-system.php';
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-base.php';
		}

		$this->base = new Smart_Cache_Base(__FILE__, true);

		if(SMART_CACHE_IS_PREMIUM){
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-core-p.php';
		}else{
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-core.php';
		}

		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-cron.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-i18n.php';

		require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-sc-admin.php';
		require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-sc-public.php';

		// Execute any active addon _Run() functions -- thus initiating them as well
		if(SMART_CACHE_IS_PREMIUM) do_action( SMART_CACHE_PLUGIN_NAME . '-addon-init' );
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Plugin_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {
		$plugin_i18n = new Smart_Cache_i18n();
		$plugin_i18n->set_domain(SMART_CACHE_PLUGIN_NAME);

		$this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
	}

	/**
	 * Register core functionality (the minification/combination engine)
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_core_hooks() {
		$plugin_core = new Smart_Cache_Core(__FILE__);

		$this->loader->add_action('save_post', $plugin_core, 'clear_cache_on_post_save', 10, 3);
		$this->loader->add_action('create_category', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('delete_category', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('edit_category', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('create_term', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('edited_terms', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('edited_term_taxonomy', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('deleted_term_taxonomy', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('delete_user', $plugin_core, 'clear_cache_folder', 10);
		$this->loader->add_action('activated_plugin', $plugin_core, 'clear_cache_folder', 10);
	}

	/**
	 * Register all cron functionality
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_cron_hooks() {
		$plugin_cron = new Smart_Cache_Cron(__FILE__);
	}

	/**
	 * Register all of the hooks related to the dashboard functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Smart_Cache_Admin(__FILE__);

		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
		$this->loader->add_action('admin_menu', $plugin_admin, 'admin_pages');
		$this->loader->add_action('admin_init', $plugin_admin, 'admin_post_init', 1);

		$this->loader->add_filter('plugin_action_links', $plugin_admin, 'plugin_settings_links', 10, 2);
		$this->loader->add_action('wp_ajax_' . SMART_CACHE_PLUGIN_NAME_CODE . '_admin_ajax', $plugin_admin, 'admin_ajax_handler');
		$this->loader->add_action('wp_before_admin_bar_render', $plugin_admin, 'admin_toolbar_links', 999);
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
		$plugin_public = new Smart_Cache_Public(__FILE__);

		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
		$this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
		$this->loader->add_action('init', $plugin_public, 'public_post_init', 1);
		$this->loader->add_action('wp_ajax_nopriv_public_ajax_handler', $plugin_public, 'public_ajax_handler');
		$this->loader->add_action('wp_ajax_public_ajax_handler', $plugin_public, 'public_ajax_handler');
		add_shortcode('sc-show-debug', array($this->base, 'show_debug'));
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		if ( defined( 'DOING_AUTOSAVE' ) ) {
			return;
		}

		if ( defined( 'XMLRPC_REQUEST' ) ) {
			return;
		}

		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return SMART_CACHE_PLUGIN_NAME;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Smart_Cache_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return SMART_CACHE_VER;
	}

	/**
	 * Custom error handler
	 * @param integer $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param integer $errline
	 * @return boolean
	 */
	function custom_error_handler($errno, $errstr, $errfile, $errline){
		if (!(error_reporting() & $errno)) {
			// This error code is not included in error_reporting, so let it fall
			// through to the standard PHP error handler
			return false;
		}

		$terminate = false;
		$outp = "";
		switch ($errno) {
			case E_COMPILE_ERROR:
			case E_RECOVERABLE_ERROR:
			case E_USER_ERROR:
			case E_STRICT:
			case E_ERROR:
				$outp .= "<b>CRITICAL ERROR</b> [$errno] $errstr. ";
				$outp .= "  Critical error on line $errline in file $errfile\n";
				$outp .= "Aborting...<br/>\n";
				$terminate = true;
				break;

			case E_USER_WARNING:
			case E_WARNING:
				$outp .= "<b>WARNING</b> [$errno] $errstr. ";
				$outp .= "  Warning on line $errline in file $errfile<br/>\n";
				break;

			case E_USER_NOTICE:
			case E_NOTICE:
				$outp .= "<b>NOTICE</b> [$errno] $errstr. ";
				$outp .= "  Notice on line $errline in file $errfile<br/>\n";
				break;

			default:
				$outp .= "<b>UNKNOWN error type</b>: [$errno] $errstr. ";
				$outp .= "  Error on line $errline in file $errfile<br/>\n";
				break;
		}
		if($outp != ''){
			echo "<div style=\"clear: both; position: relative; border: 1px solid black; background: white; padding: 2px;\">\n";
			echo $outp;
			echo "</div>\n";
		}

		if($terminate){
			exit(1);
		}else{
			/* Don't execute PHP's internal error handler */
			return true;
		}
	}

}
