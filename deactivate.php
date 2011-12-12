<?php
/*
 * This file will be run when the plugin is deactivated
 */

wp_clear_scheduled_hook('wazScale_diagnostics_transfer');
wp_clear_scheduled_hook('wazScale_scale');