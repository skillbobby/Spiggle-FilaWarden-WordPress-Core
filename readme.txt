=== FilaWarden Core ===
Contributors: spiggle
Tags: security, monitoring, operations, auditor
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Operations intelligence, 12-point deployment auditor, and production sentinel for WordPress.

== Description ==

FilaWarden Core is an operations screen for WordPress administrators. It reads this server and this database. It does not phone home, and it does not add anything to the public site.

The Core screens are:

* Executive dashboard with a five-part health score.
* Deployment auditor with 12 readiness checks, including debug state, salts, file editing, object cache, OPcache, permalinks, cron, and HTTPS.
* Infrastructure telemetry for CPU load, memory, disk space, PHP version, and host uptime.
* WP-Cron queue monitor with retry and forget for a scheduled event.
* Scheduler heartbeat recorded by WP-Cron, not by opening an admin page.
* Database size and the 20 largest tables.
* Error log tail. The screen reads the file and does not modify it. The default file is `wp-content/debug.log`.
* SSL/TLS certificate check for the site host. Local and development environments are reported as local.
* Risk file scan of the document root for database dumps (`.sql`), shell scripts (`.sh`), archives (`.zip`, `.tar`, `.gz`), and files named `.env`.

A commercial add-on, sold separately, can add security scans, APM, incidents, and webhooks. Core does not require that add-on, and Core does not lock any of its own screens behind a license.

Administrators with the `manage_options` capability can open FilaWarden from the WordPress admin menu. Each FilaWarden screen has a WordPress menu link that returns to the normal dashboard.

== Installation ==

1. Upload the plugin zip through Plugins, Add New, Upload Plugin, or copy the `spiggle-filawarden` folder into `wp-content/plugins/`.
2. Activate FilaWarden Core.
3. Open FilaWarden in the admin menu.
4. Allow WP-Cron to run, or call `wp-cron.php` from system cron, so the scheduler heartbeat stays current.

Optional settings use a `wp-config.php` constant or an environment variable of the same name. Examples:

* `FILAWARDEN_ENABLED` set to `0` turns the admin screens off.
* `FILAWARDEN_CAPABILITY` changes the capability required to open the screens. The default is `manage_options`.
* `FILAWARDEN_LOG_PATH` sets the error log file to read. When it is empty, FilaWarden uses `WP_DEBUG_LOG`, then `wp-content/debug.log`.
* `FILAWARDEN_PRO_UPGRADE_URL` sets the optional upgrade link. Only an `https` URL is shown, and only when the commercial add-on is not active.

You can also change the configuration array with the `filawarden_config` filter.

== Frequently Asked Questions ==

= Does FilaWarden Core track visitors or send data to Spiggle? =

No. Telemetry is read on this server. Styles, scripts, and the logo are files inside the plugin. Core does not embed a public credit link.

= Does Core need a license key? =

No. Every Core screen works without a key. License checks belong to the separate commercial add-on.

= What does Failed mean on Risk Files? =

The scan finished. Failed is the high-risk mark for a `.sql` file, a `.sh` file, or a file named `.env` inside the document root. Warning is the mark for a `.zip`, `.tar`, or `.gz` archive. The scan skips `wp-admin`, `wp-includes`, `vendor`, `node_modules`, and `.git`.

= Why are memory or uptime reported as n/a? =

Some hosts hide `/proc`. FilaWarden then reads memory from the kernel node counters and uptime from a new process start clock. If those are blocked as well, the screen shows n/a instead of a made-up number.

= How do I get back to the normal WordPress menu? =

Use the WordPress menu link at the bottom of the FilaWarden sidebar or at the top right of the screen.

= What does uninstall remove? =

Deleting the plugin removes the `filawarden_heartbeat` option, the risk-file scan transient, and the `filawarden_heartbeat_event` cron hook. It does not delete posts, tables created by another plugin, or files outside this plugin.

== Changelog ==

= 1.0.3 =
* Admin screen styles load with `wp_enqueue_style()`. The dark-mode class is set from a head script loaded with `wp_enqueue_script()`.
* The error log screen only reads the log. It no longer clears the file.

= 1.0.2 =
* Initial public release.
* Operations dashboard, 12-point deployment auditor, and host telemetry.
* WP-Cron queue actions, scheduler heartbeat, database footprint, error log tail, SSL check, and risk-file scan.
* Settings come from constants, environment variables, or the `filawarden_config` filter.

== Upgrade Notice ==

= 1.0.3 =
Loads the admin shell through the script and style APIs, and stops writing to the error log.

= 1.0.2 =
Initial public release of the operations dashboard, deployment auditor, and host sentinel.
