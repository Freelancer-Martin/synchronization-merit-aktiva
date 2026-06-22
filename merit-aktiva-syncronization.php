<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://freelancermartin.com
 * @since             1.0.0
 * @package           Orders_Synchronization_for_Merit_Aktiva
 *
 * @wordpress-plugin
 * Plugin Name:       Orders Synchronization for Merit Aktiva
 * Plugin URI:        https://freelancermartin.com/et
 * Description:       Wordpressi pistikprogramm e-poe omanikele Merit Aktiva ja Woocommerce andmete sünkroniseerimiseks.
 * Version:           1.2.2
 * Author:            freelancermartin
 * Author URI:        https://freelancermartin.com/et
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       synchronization-merit-aktiva 
 * Domain Path:       /languages
 */


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'MERIT_AKTIVA_SYNCRONIZATION_VERSION', '1.2.2' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-merit-aktiva-syncronization-activator.php
 */
function activate_merit_aktiva_syncronization() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-merit-aktiva-syncronization-activator.php';
	Merit_Aktiva_Syncronization_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-merit-aktiva-syncronization-deactivator.php
 */
function deactivate_merit_aktiva_syncronization() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-merit-aktiva-syncronization-deactivator.php';
	Merit_Aktiva_Syncronization_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_merit_aktiva_syncronization' );
register_deactivation_hook( __FILE__, 'deactivate_merit_aktiva_syncronization' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-merit-aktiva-syncronization.php';

add_filter('cron_schedules', function($schedules) {
    $schedules['every_5_minutes'] = array(
        'interval' => 5 * 60,
        'display'  => 'Iga 5 minuti tagant',
    );
    return $schedules;
});

// Tagab, et cron on alati registreeritud — ka siis kui plugin oli aktiivne enne cron-i lisamist.
add_action('init', function() {
    if (!wp_next_scheduled('merit_aktiva_auto_sync')) {
        wp_schedule_event(time(), 'every_5_minutes', 'merit_aktiva_auto_sync');
    }
});

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_merit_aktiva_syncronization() {

	$plugin = new Merit_Aktiva_Syncronization();
	$plugin->run();

}
run_merit_aktiva_syncronization();
