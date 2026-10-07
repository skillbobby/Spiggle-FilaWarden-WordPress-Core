<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('filawarden_heartbeat');
delete_option('filawarden_license_hash');
delete_option('filawarden_license_last4');
delete_option('filawarden_slack');
delete_option('filawarden_discord');
delete_option('filawarden_webhook');
wp_clear_scheduled_hook('filawarden_heartbeat_event');
