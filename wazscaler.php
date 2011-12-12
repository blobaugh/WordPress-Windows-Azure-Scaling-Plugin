<?php
/*
Plugin Name: Windows Azure Scaling
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
// Create the custom post type
add_action('init', 'wazScale_diagnostics_post_type');

// Add additional time slots to cron
add_filter( 'cron_schedules', 'wazScale_additional_crons' );


// Create a hook for the cron diagnostics transfer trigger
add_action('wazScale_diagnostics_transfer', 'wazScale_diagnostics_transfer');

// Create a hook for the cron performance/scaling check
add_action('wazScale_scale', 'wazScale_scale');

// Add activation hook
register_activation_hook( __FILE__, 'wazScale_activate' );

// Add deactivation hook
register_deactivation_hook( __FILE__, 'wazScale_deactivate' );

/*
 * All the following code should only be run in the backend
 */
if( is_admin() ) {
    // Add admin menu pages
    add_action('admin_menu', 'wazScale_addmenus');

    // Check settings and alert the admin if anything is amiss
    add_action('admin_notices', 'wazScale_check_settings');
}


/* *****************************************************************************
 * ************************** START FUNCTIONS **********************************
 * *****************************************************************************
 */

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
        $schedules['5seconds'] = array(
                'interval' => 5,
                'display' => __('Every 5 Seconds')
        );
        $schedules['15seconds'] = array(
                'interval' => 15,
                'display' => __('Every 15 Seconds')
        );
 	return $schedules;
 }

/**
 * Main menu when clicking on 'WAZ Scaling' in wp-admin
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
        update_option(OP_SETTINGS, array('deployment_slot' => $_POST['deployment_slot'], 'deployment_endpoint' => $_POST['deployment_endpoint'], 'deployment_role_name' => $_POST['deployment_role_name'], 'storage_endpoint' => $_POST['storage_endpoint'], 'storage_key' => $_POST['storage_key'],'subscription_id' => $_POST['subscription_id'], 'certificate' => $_POST['certificate'], 'certificate_thumbprint' => $_POST['certificate_thumbprint']));
        echo 'figure out how to create an alert box with the result wazscaler:wazScale_admin_settings_menu';
    }

    extract(get_option(OP_SETTINGS)); // Creates $subscription, $certificate, $certificate_thumbprint
    require_once('admin_settings_menu.php');
}

/**
 * Logic to run on plugin activation
 */
function wazScale_activate() {
    //wp_schedule_event( time(), '5minutes', 'wazScale_diagnostics_transfer' ); // hourly, daily and twicedaily
    require_once('activate.php');
}

/**
 * Logic to run on plugin deactivation
 */
function wazScale_deactivate() {
    require_once('deactivate.php');
}



/**
 * Adds the main admin menu and sub menu items
 */
function wazScale_addmenus() {
    add_utility_page( "Windows Azure Scaling Statistics", "WAZ Scaling", 'activate_plugins', 'wazScaling', 'wazScale_admin_menu', plugins_url() . '/' . basename(__DIR__) . '/azure-logo-icon.png' );
    add_submenu_page( 'wazScaling', "Windows Azure Scaling Triggers", "Triggers", 'activate_plugins', 'wazScaler_triggers', 'wazScale_admin_triggers_menu' );
    add_submenu_page( 'wazScaling', "Windows Azure Scaling Schedule", "Schedule", 'activate_plugins', 'wazScaler_schedule', 'wazScale_admin_schedule_menu' );
    add_submenu_page( 'wazScaling', "Windows Azure Scaling Settings", "Settings", 'activate_plugins', 'wazScaler_settings', 'wazScale_admin_settings_menu' );
}


/**
 * Checks the triggers to determing scaling in/out needs and performs the operation
 */
function wazScale_scale() { 
    $scale_by = wazScale_check_triggers();
    $settings = get_option( OP_SETTINGS );
    $triggers = get_option( OP_TRIGGERS );
   
    

    /*
     * Create the temporary certificate file for the Windows Azure SDK for PHP
     * Service Management API calls. This will use a temp file which places the file
     * in a non-publically accessable location and removes it automagically after
     * use. This adds a touch of security as outside
     * visitors cannot access the filesystem and view the file
     */
    file_put_contents(sys_get_temp_dir() . "/tmpCert.pem", $settings['certificate']);
    if(file_exists(sys_get_temp_dir() . "/tmpCert.pem")) { echo "<b>Cert found</b>"; }
    else { echo "<b>No cert file</b>"; }

    echo "<pre>"; var_dump($settings); echo "</pre>";
    $management_client = new Microsoft_WindowsAzure_Management_Client($settings['subscription_id'], sys_get_temp_dir() . "/tmpCert.pem", $settings['certificate_thumbprint']);

    // Get current number of instances - WARNING this can take some time.
    // Do not do this on a every page load
    $instance_count = wazScale_get_num_instances($management_client->getDeploymentBySlot($settings['deployment_endpoint'], $settings['deployment_slot'])->roleinstancelist, $settings['deployment_role_name']);

    if( $scale_by < 0 && $instance_count > $triggers['min_instances'] ) {
        try {
            $management_client->setInstanceCountBySlot($settings['deployment_endpoint'], $settings['deployment_slot'], $settings['deployment_role_name'], $instance_count - 1);
        } catch ( Exception $e ) {}

    } elseif( $scale_by > 0 && $instance_count < $triggers['max_instances'] ) {
        try {
            $management_client->setInstanceCountBySlot($settings['deployment_endpoint'], $settings['deployment_slot'], $settings['deployment_role_name'], $instance_count + 1);
        } catch ( Exception $e ) {}
    }

    // Delete certificate file
    unlink( sys_get_temp_dir() . "/tmpCert.pem" );
}

/**
 * Returns the number of NAMED instances
 *
 * @param Array $instances
 * @param String $role_name
 * @return Integer
 */
function wazScale_get_num_instances( $instances, $role_name ) {
    $count = 0;
    foreach( $instances as $instance ) {
        if ( $instance['rolename'] == $role_name )
            $count++;
    }
    return $count;
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
 * Pulls the performance metrics from the local WordPress database and builds
 * an array of information that can be used to determine scaling needs
 *
 * @param Boolean $full - Default: false - Set to true for extended performance information. PERFORMANCE WARNING
 * @return Array
 */
function wazScale_retrieve_diagnostics($full = false) {
    //add_filter( 'posts_where', 'wazScale_where_last15' );
    $query = new WP_Query( 'post_type=' . TYPE_DIAGNOSTICS );
    //remove_filter( 'posts_where', 'wazScale_where_last15' );

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
       $CurrentCounterValue =  (!empty($arr[$CounterName]['total']))? $arr[$CounterName]['total']: 0;
       $arr[$CounterName]['total'] = $CounterValue + $CurrentCounterValue;

       /*
        * Start totals
        */
       // Is this value max?
       if(isset($arr[$CounterName]['max']) && !is_null($arr[$CounterName]['max']) && $CounterValue > $arr[$CounterName]['max']) {
           $arr[$CounterName]['max'] = $CounterValue;
       } elseif(!isset($arr[$CounterName]['max'])  || is_null($arr[$CounterName]['max'])) {
           $arr[$CounterName]['max'] = $CounterValue;
       }

       // Is this value min?
       if(isset($arr[$CounterName]['min']) && !is_null($arr[$CounterName]['min']) && $CounterValue < $arr[$CounterName]['min']) {
           $arr[$CounterName]['min'] = $CounterValue;
       } elseif(!isset($arr[$CounterName]['min'])  || is_null($arr[$CounterName]['min'])) {
           $arr[$CounterName]['min'] = $CounterValue;
       }

       // Set count
       $CurrentCounterNameCount =  (isset($arr[$CounterName]['count']) && !is_null($arr[$CounterName]['count']))? $arr[$CounterName]['count']: 0;
       $arr[$CounterName]['count'] = 1 + $CurrentCounterNameCount;

       // Set average
       $arr[$CounterName]['average'] = $arr[$CounterName]['total'] / $arr[$CounterName]['count'];

       /*
        * End totals
        *
        * Start individual instances
        */
       if($full) {
           if(!empty($arr['instances'][$Role][$RoleInstance][$CounterName]['max']) && $CounterValue > $arr['instances'][$Role][$RoleInstance][$CounterName]['max']) {
               $arr['instances'][$Role][$RoleInstance][$CounterName]['max'] = $CounterValue;
           } elseif(empty($arr['instances'][$Role][$RoleInstance][$CounterName]['max'])) {
               $arr['instances'][$Role][$RoleInstance][$CounterName]['max'] = $CounterValue;
           }

           // Is this value min?
           if(!empty($arr['instances'][$Role][$RoleInstance][$CounterName]['min']) && $CounterValue < $arr['instances'][$Role][$RoleInstance][$CounterName]['min']) {
               $arr['instances'][$Role][$RoleInstance][$CounterName]['min'] = $CounterValue;
           } elseif(empty($arr['instances'][$Role][$RoleInstance][$CounterName]['min'])) {
               $arr['instances'][$Role][$RoleInstance][$CounterName]['min'] = $CounterValue;
           }

           // Set count
           $CurrentCounterNameCount =  (!empty($arr['instances'][$Role][$RoleInstance][$CounterName]['count']))? $arr['instances'][$Role][$RoleInstance][$CounterName]['count']: 0;
           $arr['instances'][$Role][$RoleInstance][$CounterName]['count'] = 1 + $CurrentCounterNameCount;

           // Set average
          // $arr['instances'][$Role][$RoleInstance][$CounterName]['average'] = $arr['instances'][$Role][$RoleInstance][$CounterName]['total'] / $arr['instances'][$Role][$RoleInstance][$CounterName]['count'];
       }
       /*
        * End individual instances
        */
   }
   return $arr;
}

/**
 *  Alters the database query string to only retrieve that last 15 minutes of posts
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
 * Retreives the diagnostics from the Windows Azure storage account connected to
 * the running instances. This data is then inserted into the WordPress database
 * as a custom post type for quick calculations and historical purposes.
 */
function wazScale_diagnostics_transfer() { //echo "<b>Not actually transferring diagnostics during debug</b>"; return true;
    $ops = get_option('wazScale_settings');
   

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
 * Creates the custom post type that the diagnostics information will be stored in
 */
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

/**
 * Quick debugging tool
 * @param Mixed $what
 */
function wazScale_debug($what) {
   echo '<pre>';
   var_dump($what);
   echo '</pre>';
}

/**
 * Generic function to show a message to the user using WP's
 * standard CSS classes to make use of the already-defined
 * message colour scheme.
 *
 * @param $message The message you want to tell the user.
 * @param $errormsg If true, the message is an error, so use
 * the red message style. If false, the message is a status
  * message, so use the yellow information message style.
 */
function wazScale_show_message($message, $errormsg = false) {
	if ($errormsg) {
		echo '<div id="message" class="error">';
	}
	else {
		echo '<div id="message" class="updated fade">';
	}

	echo "<p><strong>$message</strong></p></div>";
}

/**
 * Just show our message (with possible checking if we only want
 * to show message to certain users.
 */
function wazScale_check_settings() {

    // Only show to admins
    if (current_user_can('manage_options') && !(get_option(OP_SETTINGS))) {

        wazScale_show_message("Configuration not found for the Windows Azure Scaling plugin! Please configure settings now", true);
    }
}


 /* ****************************************************************************
 * **************************** END FUNCTIONS **********************************
 * *****************************************************************************/

