# FilaWarden Core for WordPress

- Executive dashboard with 5-vector health scoring
- 12-point deployment readiness auditor
- Infrastructure telemetry (CPU, memory, disk, uptime)
- Queue / WP-Cron monitor with retry and forget
- Scheduler heartbeat
- Database footprint and top 20 tables
- Error log tail parser. The screen reads the log and does not change the file.
- SSL/TLS sentinel
- Risk file scanner (`.sql`, `.sh`, archives, `.env`)

Pro features live in `Spiggle-FilaWarden-WordPress-Advanced`.

## Install
Copy `spiggle-filawarden` into `wp-content/plugins` and activate. Requires WordPress 6.4+ and PHP 8.1+.
