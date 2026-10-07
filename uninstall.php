<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('filawarden_heartbeat');
delete_transient('filawarden_risk_files');
wp_clear_scheduled_hook('filawarden_heartbeat_event');
