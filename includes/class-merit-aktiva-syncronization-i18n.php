<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://freelancermartin.ee
 * @since      1.0.0
 *
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 * @author     Freelancer <freelancermartin1@gmail.com>
 */
class Merit_Aktiva_Syncronization_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'merit-aktiva-syncronization',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
