# Core Stack

Docker Compose deployment of the Identiqa NMS core: branded **Zabbix 8.0**, the
license proxy and agent, SNMP traps, and weekly database backups.

For how the services fit together, see [../docs/architecture.md](../docs/architecture.md).

## Contents

| Path | Purpose |
|---|---|
| `docker-compose.yaml` | All services |
| `Dockerfile` | `zabbix-web` image: the Zabbix 8.0 frontend plus PHP CLI, SSH tools, Python, Netmiko and the MySQL connector (needed by modules) |
| `license-proxy/` | License gate in front of the web UI (port 8080) |
| `license-agent/` | Periodic license check, plus usage reporting |
| `license/license.lic` | License key and instance ID: see [../docs/licensing.md](../docs/licensing.md) |
| `zabbix-custom-ui/` | Branding (logo, favicon, theme, `brand.conf.php`), `zabbix_server.conf`, `snmptrapd.conf` |
| `modules/` | *(created by you, not in git)* Installed Zabbix modules: see [../docs/modules.md](../docs/modules.md) |
| `backups/` | *(created at runtime, not in git)* Weekly MySQL dumps |

## Requirements

- Linux host with Docker Engine and the Compose plugin
- Outbound HTTPS to the License API
- A license key and instance ID

## Before the first start

1. **Database password:** it is set in `docker-compose.yaml` (`mysql-server`,
   `zabbix-server`, `zabbix-web` and `mysql-backup`). Change it to your own value
   in every place before the first start.
2. **License:** key on line 1, instance ID on line 2, values only:
   ```bash
   printf '%s\n%s\n' "<LICENSE_KEY>" "<INSTANCE_ID>" > license/license.lic
   ```
3. **Known issues:** read the license-agent items (B1, B2) in
   [../docs/known-issues.md](../docs/known-issues.md).

## Standard setup

```bash
cd stack
sudo docker compose up -d --build     # 1. start everything
sudo docker ps                        # 2. check all containers are up

# 3. stop the license agent and make sure zabbix-server is running
sudo docker compose down license-agent
sudo docker compose up -d zabbix-server
```

Open `http://<server-ip>:8080`. The default Zabbix login is `Admin` / `zabbix`;
**change it straight away.**

## OpenStack VMs

On OpenStack, start the services one at a time, so each dependency is ready before the next one starts:

```bash
sudo docker compose up -d mysql-server
sudo docker compose logs -f mysql-server     # wait for "ready for connections"

sudo docker compose up -d zabbix-server
sudo docker compose logs -f zabbix-server    # wait for ~20–30 lines of startup output

sudo docker compose up -d                    # everything else

sudo docker compose down license-agent
sudo docker compose up -d zabbix-server
```

Then add the license (see above) if you haven't already.

## Backups

`mysql-backup` writes a gzipped dump of the `zabbix` database to `./backups/`
every Sunday at 02:00 and keeps the last 4. To restore one:

```bash
gunzip -c backups/<dump-file>.gz | sudo docker exec -i mysql-server mysql -u root -p zabbix
```

## Configuration reference

| Setting | Where |
|---|---|
| Time zone | `TZ` / `PHP_TZ` in `docker-compose.yaml` (default `Asia/Kolkata`) |
| UI branding | `zabbix-custom-ui/` |
| SNMP trap handling | `zabbix-custom-ui/snmptrapd.conf` |
| Backup schedule and retention | `DB_DUMP_CRON`, `DB_DUMP_RETENTION` on `mysql-backup` |

## Useful commands

```bash
sudo docker compose logs -f license-proxy
sudo docker compose restart zabbix-web
sudo docker compose down            # stop; data stays in volumes
```
