<?php
/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Defines the internationalization functionality.
 *
 * @since      1.0.0
 * @package    Code Dragon Smart Cache
 * @author     Dragon Slayer <info@codedragon.ca>
 */
class Smart_Cache_i18n {

	/**
	 * The domain specified for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $domain    The domain identifier for this plugin.
	 */
	private $domain;

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			$this->domain,
			false,
			dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
		);

	}

	/**
	 * Set the domain equal to that of the specified domain.
	 *
	 * @since    1.0.0
	 * @param    string    $domain    The domain that represents the locale of this plugin.
	 */
	public function set_domain($domain) {
		$this->domain = $domain;
	}

}
