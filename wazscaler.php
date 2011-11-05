<?php
/*
Plugin Name: Windows Azure Scaler
Plugin URI: https://github.com/blobaugh/WordPress-Windows-Azure-Scaling-Plugin
Description: Plugin to easily enable scaling Windows Azure from WordPress
Version: 0.1
Author: Ben Lobaugh
Author URI: http://ben.lobaugh.net
*/

/**
 *
 */
define('DIAGNOSTICS_TABLE', 'WADPerformanceCountersTable'); // (Default
define('DELETE_DIAGNOSTICS_FROM_STORAGE', false); // (Default: true) Delete diagnostic from Azure storage table after entering in database
define('OP_TRIGGERS', 'wazScale_triggers');
define('OP_SETTINGS', 'wazScale_settings');
define('TYPE_DIAGNOSTICS', 'wazScale_diagnostics');

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
//add_action('init', 'wazScale_setup_cron');

/*
 * All the following code should only be run in the backend
 */
if( is_admin() ) {
    

    // Add admin menu pages
    add_action('admin_menu', 'wazScale_addmenus');

    // Add additional time slots to cron
    add_filter( 'cron_schedules', 'wazScale_additional_crons' );

    // Run cron every 5 minutes to pull diagnostics from WAZ tables into the db
//    if ( !wp_next_scheduled('wazScale_diagnostics_transfer') ) {
//        wp_schedule_event( time(), '5minutes', 'wazScale_diagnostics_transfer' ); // hourly, daily and twicedaily
//    }
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
        update_option(OP_TRIGGERS, $_POST);
        echo 'figure out how to create an alert box with the result wazscaler:48';
    }

    extract(get_option(OP_TRIGGERS)); // Creates $subscription, $certificate, $certificate_thumbprint
    require_once('admin_triggers_menu.php');
}
function wazScale_admin_schedule_menu() { echo 'wazScale_admin_schedule_menu'; }

/**
 * Creates the display and logic for the admin settings page
 */
function wazScale_admin_settings_menu() {
    // If the user has input all the values update the plugin options
    if(isset($_POST['submit'])) {
        update_option(OP_SETTINGS, array('storage_endpoint' => $_POST['storage_endpoint'], 'storage_key' => $_POST['storage_key'],'subscription_id' => $_POST['subscription_id'], 'certificate' => $_POST['certificate'], 'certificate_thumbprint' => $_POST['certificate_thumbprint']));
        echo 'figure out how to create an alert box with the result wazscaler:wazScale_admin_settings_menu';
    }

    extract(get_option(OP_SETTINGS)); // Creates $subscription, $certificate, $certificate_thumbprint
    require_once('admin_settings_menu.php');
}

function wazScale_activate() {
    //wp_schedule_event( time(), '5minutes', 'wazScale_diagnostics_transfer' ); // hourly, daily and twicedaily
}
    
function wazScale_deactivate() {
    require_once('deactivate.php');
}

function wazScale_setup_cron() {
    if ( !wp_next_scheduled('wazScale_diagnostics_transfer') ) {
        wp_schedule_event( time(), '5minutes', 'wazScale_diagnostics_transfer' ); // hourly, daily and twicedaily
    }
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

add_action('init', 'wazScale_scale');

function wazScale_scale() {
    $scale_by = wazScale_check_triggers();
    $settings = get_option( OP_SETTINGS );
    $triggers = get_option( OP_TRIGGERS );
  
    // Get current number of instances
    $current_instances = 2;

    if( $scale_by < 0 && $current_instances > $triggers['min_instances'] ) {
        echo "Scaling in";
    } elseif($scale_by > 0 && $current_instances < $triggers['max_instances']) {
        echo "Scaling out";
    }
}

/**
 * Checks the given performance metrics to determine whether or not to scale
 * in or out. Uses the averages of all instances to check against
 *
 * Operates fairly dumbly right now. See BrainDump for future expansions
 */
function wazScale_check_triggers() {
    $metrics = wazScale_retrieve_diagnostics();
    $triggers = get_option(OP_TRIGGERS);
    $scale_by = 0;
    

    if(!$triggers['manual_control']) {        
        if($metrics['TCPv4Connections_Established']['average'] > $triggers['connections_max']) {
            $scale_by = 1;

        } elseif ($metrics['Processor(_Total)%_Processor_Time']['average'] > $triggers['cpu_max']) {
            $scale_by = 1;
        } else if ($metrics['TCPv4Connections_Established']['average'] < $triggers['connections_min'] && $metrics['Processor(_Total)%_Processor_Time']['average'] < $triggers['cpu_min']) {
            $scale_by = -1;
        }
        
    }
    return $scale_by;
}

/**
 *
 * @param Integer $period - Minutes in the past to look for diagnostics
 * @return Array
 */
function wazScale_retrieve_diagnostics() {
    add_filter( 'posts_where', 'wazScale_where_last15' );
    $query = new WP_Query( 'post_type=' . TYPE_DIAGNOSTICS );
    remove_filter( 'posts_where', 'wazScale_where_last15' );

    $arr = array();
   foreach($query->posts as $p) {
       $Role = get_post_meta($p->ID, 'Role', true);
       $RoleInstance = get_post_meta($p->ID, 'RoleInstance', true);
       $CounterName = str_replace(' ', '_', get_post_meta($p->ID, 'CounterName', true));
       $CounterValue = get_post_meta($p->ID, 'CounterValue', true);
       /*
        * Create an array of
        *
        * array (
        *   'cpu' => array('high', 'low', 'average', 'total', 'count'),
        *   'network_connections'' => array('high', 'low', 'average', 'total', 'count'),
        *   'memory' => array('high', 'low', 'average', 'total', 'count'),
        *   'instances' => array (
        *                           'instance_name' => array(
        *                                                     'cpu' => array('high', 'low', 'average', 'total', 'count'),
        *                                                     'network_conections' => array('high', 'low', 'average', 'total', 'count'),
        *                                                     'memory' => array('high', 'low', 'average', 'total', 'count')
        *                                                   )
        *                        )
        * )
        *
        * Make sure it is extensible so future diagnostics can be added
        */

       // Add current value to existing total
       $CurrentCounterValue =  (!is_null($arr[$CounterName]['total']))? $arr[$CounterName]['total']: 0;
       $arr[$CounterName]['total'] = $CounterValue + $CurrentCounterValue;

       /*
        * Start totals
        */
       // Is this value max?
       if(!is_null($arr[$CounterName]['max']) && $CounterValue > $arr[$CounterName]['max']) {
           $arr[$CounterName]['max'] = $CounterValue;
       } elseif(is_null($arr[$CounterName]['max'])) {
           $arr[$CounterName]['max'] = $CounterValue;
       }

       // Is this value min?
       if(!is_null($arr[$CounterName]['min']) && $CounterValue < $arr[$CounterName]['min']) {
           $arr[$CounterName]['min'] = $CounterValue;
       } elseif(is_null($arr[$CounterName]['min'])) {
           $arr[$CounterName]['min'] = $CounterValue;
       } 
       
       // Set count
       $CurrentCounterNameCount =  (!is_null($arr[$CounterName]['count']))? $arr[$CounterName]['count']: 0;
       $arr[$CounterName]['count'] = 1 + $CurrentCounterNameCount;

       // Set average
       $arr[$CounterName]['average'] = $arr[$CounterName]['total'] / $arr[$CounterName]['count'];

       /*
        * End totals
        *
        * Start individual instances
        */
       if(!is_null($arr['instances'][$Role][$RoleInstance][$CounterName]['max']) && $CounterValue > $arr['instances'][$Role][$RoleInstance][$CounterName]['max']) {
           $arr['instances'][$Role][$RoleInstance][$CounterName]['max'] = $CounterValue;
       } elseif(is_null($arr['instances'][$Role][$RoleInstance][$CounterName]['max'])) {
           $arr['instances'][$Role][$RoleInstance][$CounterName]['max'] = $CounterValue;
       }

       // Is this value min?
       if(!is_null($arr['instances'][$Role][$RoleInstance][$CounterName]['min']) && $CounterValue < $arr['instances'][$Role][$RoleInstance][$CounterName]['min']) {
           $arr['instances'][$Role][$RoleInstance][$CounterName]['min'] = $CounterValue;
       } elseif(is_null($arr['instances'][$Role][$RoleInstance][$CounterName]['min'])) {
           $arr['instances'][$Role][$RoleInstance][$CounterName]['min'] = $CounterValue;
       }

       // Set count
       $CurrentCounterNameCount =  (!is_null($arr['instances'][$Role][$RoleInstance][$CounterName]['count']))? $arr['instances'][$Role][$RoleInstance][$CounterName]['count']: 0;
       $arr['instances'][$Role][$RoleInstance][$CounterName]['count'] = 1 + $CurrentCounterNameCount;

       // Set average
       $arr['instances'][$Role][$RoleInstance][$CounterName]['average'] = $arr['instances'][$Role][$RoleInstance][$CounterName]['total'] / $arr['instances'][$Role][$RoleInstance][$CounterName]['count'];
       /*
        * End individual instances
        */
   }
   return $arr;
}

/**
 *  Alters the query string to only retrieve that last 15 minutes of posts
 *
 * @param Sstring $where
 * @return String
 */
function wazScale_where_last15( $where = '' ) {
	// posts in the last 15 minutes
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-15 days')) . "'";
	return $where;
}


/**
 * Code to move diagnostics information from the Windows Azure storage table
 * into the WordPress database
 */
function wazScale_diagnostics_transfer() {
    $ops = get_option('wazScale_settings');
   // var_dump($ops['storage_key']);
    //echo "Connecting with endpoint: {$ops['storage_endpoint']} and key {$ops['storage_key']}";
    //echo '<pre>';

    $table = new Microsoft_WindowsAzure_Storage_Table(
        Microsoft_WindowsAzure_Storage::URL_CLOUD_TABLE,
        $ops['storage_endpoint'],
        $ops['storage_key']
    );
    // Make sure the table exists before attempting to pull data from it
    // NOTE: Table may not exist if no metrics have been written
    if($table->tableExists(DIAGNOSTICS_TABLE)) {
        $entities = $table->retrieveEntities(DIAGNOSTICS_TABLE);

        foreach($entities AS $e) {
            //Create and insert diagnostics data as new post type wazScale_diagnostics
            $post_data = array(
                'post_status' => 'publish',
                'post_type' => 'wazScale_diagnostics',
                'post_title' => $e->CounterName,
                'post_content' => $e->CounterValue,

            );

            $post_id = wp_insert_post($post_data);

            // Create the data for the wp_postsmeta table
            // Contains the additional fields not in the standard posts table
            update_post_meta($post_id, 'Timestamp', $e->Timestamp);
            update_post_meta($post_id, 'EventTickCount', $e->EventTickCount);
            update_post_meta($post_id, 'DeploymentId', $e->DeploymentId);
            update_post_meta($post_id, 'Role', $e->Role);
            update_post_meta($post_id, 'RoleInstance', $e->RoleInstance);
            update_post_meta($post_id, 'CounterName', $e->CounterName); // duped to preserve table field
            update_post_meta($post_id, 'CounterValue', $e->CounterValue);

            if(DELETE_DIAGNOSTICS_FROM_STORAGE) {
                $table->deleteEntity(DIAGNOSTICS_TABLE, $e);
            }
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
  register_post_type(TYPE_DIAGNOSTICS,$args);
}

function wazScale_debug($what) {
   echo '<pre>';
   var_dump($what);
   echo '</pre>';
}

 /* ****************************************************************************
 * **************************** END FUNCTIONS **********************************
 * *****************************************************************************/

