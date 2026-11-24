# Installing Zabbix modules

Every module in `modules/` is installed the same way. Run the steps below from
the **`stack/`** folder, where `docker-compose.yaml` lives.

## 1. Mount the modules folder (once)

Add this line to the `zabbix-web` service's `volumes:` in `stack/docker-compose.yaml`:

```yaml
      - ./modules:/usr/share/zabbix/modules
```

Then create the folder and recreate the container:

```bash
mkdir -p modules
sudo docker compose up -d zabbix-web
```

`stack/modules/` is in `.gitignore`. It holds the *installed* copies; the
source stays in the top-level `modules/` folder.

## 2. Copy the module in

Use the folder name shown below. Each module's own guide uses these names in its commands.

| Module | Source | Copy to (inside `stack/`) |
|---|---|---|
| Config Manager | `../modules/config-manager` | `modules/zabbix-config-module-main` |
| Network Device Manager | `../modules/network-device-manager` | `modules/zabbix-network-device-module` |
| IPAM | `../modules/ipam/ipampro` | `modules/ipampro` |
| Log Manager | `../modules/log-manager` | `modules/zabbix-log-manager` |
| Grafana Dashboards | `../modules/grafana-dashboards` | `modules/zabbix-grafana-module-main` |

Example:

```bash
cp -r ../modules/config-manager modules/zabbix-config-module-main
```

## 3. Set permissions

`zabbix-web` runs as UID `1997`:

```bash
sudo chown -R 1997:1995 modules/<folder>
sudo chmod -R 775 modules/<folder>
```

## 4. Module-specific steps

Some modules also need database tables or a background service. Follow the
module's own guide from the step after "copy/place the module":

| Module | Guide | Extra steps |
|---|---|---|
| Config Manager | [config-manager/README.md](../modules/config-manager/README.md) | Schema + migrations, shared backup folder, scheduler container. The stack's `Dockerfile` already includes Python and Netmiko |
| Network Device Manager | [network-device-manager/README.md](../modules/network-device-manager/README.md) | Writable `data/` folder |
| IPAM | [ipam/README.md](../modules/ipam/README.md) | Schema, then the scanner (Linux container or Windows service) |
| Log Manager | [log-manager/INSTALL.md](../modules/log-manager/INSTALL.md) | Schema, syslog receiver daemon, cleanup cron job |
| Grafana Dashboards | [grafana-dashboards/README.md](../modules/grafana-dashboards/README.md) | Turn on embedding in Grafana |

## 5. Enable in Zabbix

1. Go to **Administration → General → Modules**.
2. Click **Scan directory**.
3. Find the module and click **Disabled** to enable it.

The module's menu entry appears after a page refresh.

## Updating a module

```bash
rsync -a --delete ../modules/<source>/ modules/<folder>/
sudo chown -R 1997:1995 modules/<folder>
```

If the module ships new `sql/migrate_*.sql` files (Config Manager does), apply
them in order before using the new version.
