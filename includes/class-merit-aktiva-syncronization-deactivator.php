<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://freelancermartin.ee
 * @since      1.0.0
 *
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 * @author     Freelancer <freelancermartin1@gmail.com>
 */
class Merit_Aktiva_Syncronization_Deactivator {

	/**
	 * Eemaldab WP croni sündmuse plugina deaktiveerimisel.
	 *
	 * Ilma selleta jääks cron kirje andmebaasi isegi pärast deaktiveerimist
	 * ja tekitaks vigu, kuna hook-i käitleja enam ei eksisteeri.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook('merit_aktiva_auto_sync');
	}

}
