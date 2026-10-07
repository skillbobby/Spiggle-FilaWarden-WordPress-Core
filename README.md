# FilaWarden Core for WordPress

Same activities and operations shell as [FilaWarden Core](https://github.com/skillbobby/Spiggle-FilaWarden-Core) for Laravel Filament.

- Executive dashboard with 5-vector health scoring
- 12-point deployment readiness auditor
- Infrastructure telemetry from `/proc` (CPU, RAM, disk)
- Queue / WP-Cron monitor with retry and forget
- Scheduler heartbeat
- Database footprint and top 20 tables
- Error log tail parser
- SSL/TLS sentinel
- Risk file scanner (`.sql`, `.sh`, archives, `.env`)

Pro features live in `Spiggle-FilaWarden-WordPress-Advanced`.

## Install
Copy `spiggle-filawarden` into `wp-content/plugins` and activate. Requires WordPress 6.4+ and PHP 8.1+.
