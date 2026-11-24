# Architecture

## Core stack (`stack/`)

One Docker Compose project runs the platform:

| Service | Image / build | Exposed | Role |
|---|---|---|---|
| `mysql-server` | `mysql:8.4` | internal | Zabbix database; the modules also keep their tables here |
| `zabbix-server` | `zabbix/zabbix-server-mysql:8.0-alpine-latest` | `10051` | Zabbix server |
| `zabbix-web` | built from `stack/Dockerfile` | internal `8080` | Zabbix 8.0 frontend with Identiqa branding, plus PHP CLI, SSH tools, Python, Netmiko and the MySQL connector that the Config Manager and Network Device Manager modules need |
| `zabbix-agent` | `zabbix/zabbix-agent:alpine-8.0-latest` | `10060` → 10050 | Monitors the stack host |
| `zabbix-snmptraps` | `zabbix/zabbix-snmptraps:alpine-8.0-latest` | `162/udp` | Receives SNMP traps |
| `license-proxy` | `stack/license-proxy` | **`8080`** → 3333 | The only way in to the web UI. Checks the license on every request, then proxies to `zabbix-web`; otherwise serves `custom.html` |
| `license-agent` | `stack/license-agent` | — | Every 10 minutes: checks the license, starts or stops `zabbix-server`, reports host count and version |
| `mysql-backup` | `databack/mysql-backup` | — | Gzipped dump of the `zabbix` DB to `stack/backups/` every Sunday at 02:00; keeps 4 |

## Modules (`modules/`)

Zabbix frontend modules (PHP, `manifest_version` 2). Each one is copied into
`stack/modules/<folder>/`, which is mounted at `/usr/share/zabbix/modules`
inside `zabbix-web`. Some also need a database schema or a background process:

| Module | DB tables | Background process |
|---|---|---|
| Config Manager | `sql/schema.sql` (+ migrations) | `scripts/scheduler.py` in its own container (backups on a schedule) |
| Network Device Manager | creates its own `devices` table; also writes `data/devices.json` | `worker/terminal_worker.php` (terminal sessions) |
| IPAM | `ipampro/sql/schema.sql` | `scanner/scan_daemon.py`: a Docker container on Linux, a Scheduled Task on Windows; uses nmap |
| Log Manager | `sql/schema.sql` | `scripts/syslog_receiver.py` (syslog listener) and a daily `log_cleanup.py` cron job |
| Grafana Dashboards | none; stored in `data/dashboards.json` | none; needs a Grafana instance with embedding enabled |

See [modules.md](modules.md) for installation.

## Logging (`logging/loki-stack/`)

A separate host or Compose project. rsyslog listens on `1514` (UDP/TCP) and
writes one file per device. Promtail ships those files to Loki (14-day
retention), and Grafana (`3000`) reads from Loki. The dashboard's Logs page and
the Grafana Dashboards module both use this stack.

> The **Log Manager** module is a separate, Zabbix-native alternative: its own
> syslog receiver listens on UDP `514` and writes to MySQL. The ports don't
> clash, but each device usually sends syslog to just one target, so pick one
> approach per deployment.

## Dashboard (`dashboard/`)

A static React frontend plus a Node.js API gateway (`3001`). The gateway calls
the Zabbix JSON-RPC API, Loki, Zammad (tickets) and Oxidized (config backups),
and serves everything under `/api/*`.

## Licensing (`licensing/`)

- **Backend**: Express on AWS Lambda (Function URL) with MongoDB Atlas. It
  validates keys for `license-proxy` and `license-agent`, and stores the usage
  they report.
- **Frontend**: React admin UI for creating, listing, updating and deleting licenses.

The full flow is in [licensing.md](licensing.md).

## Network ports summary

| Port | Service |
|---|---|
| 8080/tcp | Zabbix UI, through `license-proxy` |
| 10051/tcp | Zabbix server (agents and proxies) |
| 10060/tcp | Stack's own Zabbix agent |
| 162/udp | SNMP traps |
| 1514/udp+tcp | Syslog into the Loki stack |
| 3000/tcp | Grafana (Loki stack) |
| 3100/tcp | Loki API |
| 3001/tcp | Dashboard API gateway |
