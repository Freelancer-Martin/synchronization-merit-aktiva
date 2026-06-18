<?php

/**
 * Fired during plugin activation
 *
 * @link       https://freelancermartin.ee
 * @since      1.0.0
 *
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Merit_Aktiva_Syncronization
 * @subpackage Merit_Aktiva_Syncronization/includes
 * @author     Freelancer <freelancermartin1@gmail.com>
 */
class Merit_Aktiva_Syncronization_Activator {

	/**
	 * Registreerib WP croni sündmuse automaatseks sünkroniseerimiseks iga 5 minuti tagant.
	 *
	 * 'every_5_minutes' intervall defineeritakse merit-aktiva-syncronization.php-s
	 * cron_schedules filteri kaudu — see ei ole WordPressi sisseehitatud intervall.
	 * wp_next_scheduled kontroll väldib dubleerivate cron kirjete tekkimist.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		if (!wp_next_scheduled('merit_aktiva_auto_sync')) {
			wp_schedule_event(time(), 'every_5_minutes', 'merit_aktiva_auto_sync');
		}
	}

}
