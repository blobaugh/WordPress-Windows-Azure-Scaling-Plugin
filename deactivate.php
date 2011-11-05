<?php
global $wp;
// Security measure to prevent deactivate exploits
if(!isset($wp)) { die('Must be run from a WordPress instance'); }


wp_clear_scheduled_hook('wazScale_diagnostics_transfer');