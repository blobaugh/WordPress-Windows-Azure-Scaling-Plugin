<?php
/*
Plugin Name: Windows Azure Scaler
Plugin URI: https://github.com/blobaugh/WordPress-Windows-Azure-Scaling-Plugin
Description: Plugin to easily enable scaling Windows Azure from WordPress
Version: 0.1
Author: Ben Lobaugh
Author URI: http://ben.lobaugh.net
*/

/*
 * Ensure the plugin has access to the required Windows Azure PHP SDK objects
 *
 * NOTE: The Windows Azure Storage plugin includes it's own WAZ PHP SDK. Make
 *       sure that the objects are not included twice or there will be big
 *       bad fatal exceptions
 */
if(!class_exists('Microsoft_WindowsAzure_Diagnostics_Manager')) {
    require_once(__DIR__ . '/Microsoft/AutoLoader.php');
}

/*
 * Global Setup
 */
add_action('init', 'wazScale_diagnostics_post_type');

/*
 * All the following code should only be run in the backend
 */
if( is_admin() ) {
    

    // Add admin menu pages
    add_action('admin_menu', 'wazScale_addmenus');

    // Add additional time slots to cron
    add_filter( 'cron_schedules', 'wazScale_additional_crons' );

    // Run cron every 5 minutes to pull diagnostics from WAZ tables into the db
    if ( !wp_next_scheduled('wazScale_diagnostics_transfer') ) {
        wp_schedule_event( time(), '5minutes', 'wazScale_diagnostics_transfer' ); // hourly, daily and twicedaily
    }
    add_action('wazScale_diagnostics_transfer', 'wazScale_diagnostics_transfer'); // Adds a hook to the function that will be run
    // Uncomment the following line to remove the diagnostics transfer cron
    //wp_clear_scheduled_hook('wazScale_diagnostics_transfer');


    // Add activation hook
    register_activation_hook( __FILE__, 'wazScale_activate' );

    // Add deactivation hook
    register_deactivation_hook( __FILE__, 'wazScale_deactivate' );

}


/* *****************************************************************************
 * ************************** START FUNCTIONS **********************************
 * *****************************************************************************
 */


function wazScale_admin_menu() { echo 'wazScale_admin_menu'; }

/**
 * Creates the display and logic for the admin triggers page
 */
function wazScale_admin_triggers_menu() { 
    // If the user has input all the values update the plugin options
    if(!empty($_POST)) {

        // Force minimum instance count requirements of SLA
        if(!isset($_POST['min_instances']) || !is_numeric($_POST['min_instances']) || $_POST['min_instances'] < 2) {
            $_POST['min_instances'] = 2;
        }
        update_option('wazScale_triggers', $_POST);
        echo 'figure out how to create an alert box with the result wazscaler:48';
    }

    extract(get_option('wazScale_triggers')); // Creates $subscription, $certificate, $certificate_thumbprint
    require_once('admin_triggers_menu.php');
}
function wazScale_admin_schedule_menu() { echo 'wazScale_admin_schedule_menu'; }

/**
 * Creates the display and logic for the admin settings page
 */
function wazScale_admin_settings_menu() {
    // If the user has input all the values update the plugin options
    if(isset($_POST['submit'])) {
        update_option('wazScale_settings', array('storage_endpoint' => $_POST['storage_endpoint'], 'storage_key' => $_POST['storage_key'],'subscription_id' => $_POST['subscription_id'], 'certificate' => $_POST['certificate'], 'certificate_thumbprint' => $_POST['certificate_thumbprint']));
        echo 'figure out how to create an alert box with the result wazscaler:wazScale_admin_settings_menu';
    }

    extract(get_option('wazScale_settings')); // Creates $subscription, $certificate, $certificate_thumbprint
    require_once('admin_settings_menu.php');
}

function wazScale_activate() {
    // STUB
}
    
function wazScale_deactivate() {
    require_once('deactivate.php');
}


/**
 * Adds the main admin menu and sub menu items
 */
function wazScale_addmenus() {
    add_utility_page( "Windows Azure Scaler Statistics", "WAZ Scaler", 'activate_plugins', 'wazScaler', 'wazScale_admin_menu', plugins_url() . '/' . basename(__DIR__) . '/azure-logo-icon.png' );
    //add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function );
    add_submenu_page( 'wazScaler', "Windows Azure Scaler Triggers", "Triggers", 'activate_plugins', 'wazScaler_triggers', 'wazScale_admin_triggers_menu' );
    add_submenu_page( 'wazScaler', "Windows Azure Scaler Schedule", "Schedule", 'activate_plugins', 'wazScaler_schedule', 'wazScale_admin_schedule_menu' );
    add_submenu_page( 'wazScaler', "Windows Azure Scaler Settings", "Settings", 'activate_plugins', 'wazScaler_settings', 'wazScale_admin_settings_menu' );
}

wazScale_diagnostics_transfer();
/**
 * Code to move diagnostics information from the Windows Azure storage table
 * into the WordPress database
 */
function wazScale_diagnostics_transfer() {
    $ops = get_option('wazScale_settings');
    var_dump($ops['storage_key']);
    echo "Connecting with endpoint: {$ops['storage_endpoint']} and key {$ops['storage_key']}";
    echo '<pre>';

    $table = new Microsoft_WindowsAzure_Storage_Table(
        Microsoft_WindowsAzure_Storage::URL_CLOUD_TABLE,
        $ops['storage_endpoint'],
        $ops['storage_key']
    );
    // Make sure the table exists before attempting to pull data from it
    // NOTE: Table may not exist if no metrics have been written
    if($table->tableExists('WADPerformanceCountersTable')) {
        $entities = $table->retrieveEntities('WADPerformanceCountersTable');

        foreach($entities AS $e) {
            echo "<br/>" . $e->CounterName;
        }
    }
}

/**
 * Used to add the additional schedules for WordPress cron
 * @param Array $schedules
 * @return Array
 */
function wazScale_additional_crons( $schedules ) {
 	// Adds once weekly to the existing schedules.
 	$schedules['weekly'] = array(
 		'interval' => 604800,
 		'display' => __( 'Once Weekly' )
 	);
        $schedules['15minutes'] = array(
                'interval' => 900,
                'display' => __('Every 15 Minutes')
        );
        $schedules['5minutes'] = array(
                'interval' => 300,
                'display' => __('Every 5 Minutes')
        );
 	return $schedules;
 }

function wazScale_diagnostics_post_type() {
  $labels = array(
    'name' => __('Windows Azure Diagnostic Information (Move into WAZ Scaler Menu)'),
    'menu_name' => 'WAZ Diagnostics'

  );
  $args = array(
    'labels' => $labels,
    'public' => true, // Show on front end?
    'publicly_queryable' => false, // Can this be used in search?
    'show_in_menu' => true, // Show in the wp-admin menu?
    'rewrite' => false, // What path to use in the URL on the front end
    'has_archive' => false,
    'capability_type' => 'post',
    'hierarchical' => false, // Does this post type have parents?
    'supports' => array('title', 'editor')
  );
  register_post_type('wazScale_diagnostics',$args);
}


 /* ****************************************************************************
 * **************************** END FUNCTIONS **********************************
 * *****************************************************************************

