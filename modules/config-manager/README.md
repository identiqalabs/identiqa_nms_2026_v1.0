# Config Manager – Zabbix Module

Enterprise network configuration management for Zabbix — backup, change detection, diff viewer, reusable templates, config push, and restore for Cisco, Fortinet, MikroTik, and Juniper devices.

This version includes fixes for the scheduler, timezone, backup-count, and backup-persistence bugs found during setup. See [Changelog / Fixes Applied](#changelog--fixes-applied-in-this-build) at the bottom for exactly what changed and why.

---

## Features

- **Device Inventory** — Cisco IOS/NX-OS, Fortinet, MikroTik, Juniper, Netgear (ProSafe)
- **Configuration Backup** — pulled over SSH or Telnet via Netmiko, stored in `/opt/config-backups/`
- **Background Scheduler** — automatic backups on a per-device interval (hourly/6h/12h/daily/weekly), independent of the web UI
- **Change Detection** — SHA-256 hash comparison, line-level diff
- **Diff Viewer** — side-by-side GitHub-style diff with search
- **Config Templates** — reusable, categorized command sets
- **Config Push** — push a template or ad-hoc commands to one device or in bulk, over SSH or Telnet, with dry-run support
- **Restore** — roll a device back to a previous backup
- **Dashboard** — KPI cards, activity timeline, change summary

---

## Requirements

| Component | Minimum              |
|-----------|-----------------------|
| Zabbix    | 7.0+                  |
| PHP       | 8.0+                  |
| Python    | 3.8+                  |
| Netmiko   | 4.0+                  |
| MySQL     | 5.7+ / MariaDB 10.3+  |

---

## Repository Layout

```
zabbix-config-module-main/
├── Module.php                          # Registers the menu entry
├── manifest.json                       # Zabbix module manifest
├── actions/
│   └── CControllerConfigManager.php    # Main controller (all actions)
├── views/
│   └── configmanager.view.php          # UI
├── includes/ConfigManager/
│   └── Repository.php                  # DB access layer
├── assets/
│   ├── css/configmanager.css
│   └── js/configmanager.js
├── scripts/
│   ├── backup.py                       # Netmiko-based config pull
│   ├── push_config.py                  # Netmiko-based config push
│   ├── scheduler.py                    # Cron-driven automatic backups
│   ├── Dockerfile                      # Builds the scheduler sidecar image (netmiko baked in)
│   ├── install_cron.sh
│   └── run_scheduler.sh
└── sql/
    ├── schema.sql                      # Base tables (v1.0)
    ├── migrate_v1_1.sql
    └── migrate_v1_2.sql                # Templates + push history
```

---

## How the pieces fit together

There are **three independent things** that all have to be wired correctly, or you get exactly the symptoms this build fixes (scheduler silently not firing, wrong times, wrong backup counts, backups disappearing):

1. **The Zabbix module itself** (this repo) — runs inside the `zabbix-web` container. Renders the UI, and handles manual "Backup Now" / Push / Restore clicks.
2. **The background scheduler** (`scripts/scheduler.py`) — a *separate process* that must run continuously (every 60s) outside the browser, so backups happen on schedule even when nobody has the page open. This needs its own container.
3. **The backup storage folder** (`/opt/config-backups`) — must be the **same host folder, mounted into both** the `zabbix-web` container and the scheduler container. If it's only mounted into one, the other can't see backups made by the other, and anything not mounted to the host is wiped every time that container restarts.

The docker-compose block below wires all three correctly.

---

## Installation (Docker)

These steps assume a `zabbix-web` container you build yourself from a `Dockerfile` (Alpine-based, e.g. `zabbix/zabbix-web-nginx-mysql:7.4-alpine-latest`) and a separate `mysql-server` container — matching your existing stack.

### 1. Place the module

Unzip this module so the folder ends up here on your Docker host:

```
zabbix-docker/
├── docker-compose.yml
├── modules/
│   └── zabbix-config-module-main/      ← this repo goes here
├── config-backups/                     ← create this empty folder (see step 2)
└── ...
```

Your `zabbix-web` service already mounts `./modules:/usr/share/zabbix/modules`, so nothing more to do here — Zabbix will find it automatically.

### 2. Create the shared backup folder on the host

```bash
mkdir -p ./config-backups
```

This folder will be mounted into **both** `zabbix-web` and the scheduler container (see docker-compose section below) so backups persist across restarts and both containers see the same files.

**Permissions:** both containers write to this folder as the `zabbix` user (uid `1997`, gid `1995` in the official Alpine images), so the host folder needs to be owned by/writable by that uid:gid:

```bash
sudo chown -R 1997:1995 ./config-backups
sudo chmod -R 775 ./config-backups
```

### 3. Add the docker-compose wiring

This is the part that's usually missed — two edits to `zabbix-web`, one edit to `mysql-server`, and one new service for the scheduler. See the full section below — **Docker Compose: what to add**. Do this *before* the next step, since the build/restart in step 4 needs the volumes and environment variables from this wiring already in place.

### 4. Bake Python + Netmiko into your `zabbix-web` image (don't `docker exec` it in)

Installing packages with `docker exec ... pip3 install` only lives in that container's writable layer — it's **wiped every time the container is recreated**, and along with it goes anything your PHP code wrote to a path that wasn't mounted to the host. Add this to your `zabbix-web` `Dockerfile` instead, so it's permanent:

```dockerfile
FROM zabbix/zabbix-web-nginx-mysql:7.4-alpine-latest

RUN apk add --no-cache python3 py3-pip && \
    pip3 install --break-system-packages netmiko mysql-connector-python cryptography

# ... rest of your existing Dockerfile (favicon, branding, etc.)
```

Then rebuild:

```bash
docker-compose build zabbix-web
docker-compose up -d zabbix-web
```

Verify it stuck:

```bash
docker exec zabbix-web python3 -c "import netmiko; print('Netmiko OK:', netmiko.__version__)"
```

### 5. Create the database tables
 >>  cd zabbix-config-module-main

```bash
docker exec -i mysql-server mysql -uzabbix -p<db_password> zabbix < sql/schema.sql
docker exec -i mysql-server mysql -uzabbix -p<db_password> zabbix < sql/migrate_v1_1.sql
docker exec -i mysql-server mysql -uzabbix -p<db_password> zabbix < sql/migrate_v1_2.sql
docker exec -i mysql-server mysql -uzabbix -p<db_password> zabbix < sql/migrate_v1_3.sql
```

> Already have this module installed and just upgrading? Run `sql/migrate_v1_3.sql` only — it adds `netgear` to the vendor list without touching existing data:
> ```bash
> docker exec -i mysql-server mysql -uzabbix -p<db_password> zabbix < sql/migrate_v1_3.sql
> ```

### 6. Enable the module in Zabbix

1. Log in as **Super Admin**
2. Go to **Administration → General → Modules**
3. Click **Scan directory**
4. Find **Config Manager** and click **Enable**

### 7. Open it

Go to **Configuration → Config Manager** in the left sidebar.

Note: as of this build, only **Zabbix Admin** and **Super Admin** accounts can open Config Manager — regular/read-only users are blocked (previously any authenticated user could trigger backups and push config to live devices).

---

## Docker Compose: what to add

Below is exactly what to change in your `docker-compose.yml`, relative to a typical setup. Two edits to `zabbix-web`, one edit to `mysql-server`, and one new service.

### Edit 1 — `zabbix-web`: mount the shared backup folder + point at a real timezone

```yaml
  zabbix-web:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: zabbix-web
    depends_on:
      - zabbix-server
      - mysql-server
    environment:
      TZ: Asia/Kolkata
      DB_SERVER_HOST: mysql-server
      MYSQL_DATABASE: zabbix
      MYSQL_USER: zabbix
      MYSQL_PASSWORD: <MYSQL_PASSWORD>
      ZBX_SERVER_HOST: zabbix-server
      ZBX_SERVER_NAME: "Identiqa NMS"
      PHP_TZ: Asia/Kolkata
      CM_EXTERNAL_SCHEDULER: "1"          # NEW — see explanation below
    expose:
      - "8080"
    volumes:
      - /etc/localtime:/etc/localtime:ro
      - /etc/timezone:/etc/timezone:ro
      - ./modules:/usr/share/zabbix/modules
      - ./config-backups:/opt/config-backups   # NEW — must match the scheduler's mount below
      - ./zabbix-custom-ui/favicon.ico:/usr/share/zabbix/favicon.ico
      - ./zabbix-custom-ui/brand.conf.php:/usr/share/zabbix/local/conf/brand.conf.php:ro
      - ./zabbix-custom-ui/rebranding/identiqa.png:/usr/share/zabbix/rebranding/identiqa.png:ro
    restart: unless-stopped
```

**What `CM_EXTERNAL_SCHEDULER: "1"` does:** the module can trigger due backups two ways — automatically every time someone loads the Config Manager page in a browser, or via the dedicated scheduler container below. Running both at once can very rarely double-fire a backup if a page load and a cron tick land in the same few seconds. Setting this env var tells the module "the scheduler container is handling this, don't also do it on page load." Manual "Backup Now" / Push clicks still work exactly the same either way — this only affects the automatic per-interval checks.

### Edit 2 — `mysql-server`: fix the timezone mismatch

MySQL's `NOW()` is what the scheduler and the UI both use to decide "is this backup due yet" and "what time is it now." If MySQL's clock doesn't match your local timezone, due-times and displayed times will be hours off from what you expect.

```yaml
  mysql-server:
    image: mysql:8.0
    container_name: mysql-server
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_bin --log-bin-trust-function-creators=1 --default-time-zone=+05:30
    environment:
      MYSQL_ROOT_PASSWORD: <MYSQL_PASSWORD>
      MYSQL_DATABASE: zabbix
      MYSQL_USER: zabbix
      MYSQL_PASSWORD: <MYSQL_PASSWORD>
      TZ: Asia/Kolkata
    ports:
      - "127.0.0.1:3306:3306"
    volumes:
      - mysql-data:/var/lib/mysql
    restart: unless-stopped
```

(If you're not in India, change `Asia/Kolkata` / `+05:30` to your own timezone everywhere in this file.)

### New service — the background scheduler

This is the piece that was completely missing before: nothing was actually checking "is a backup due" outside of someone having the page open. This runs it every 60 seconds, forever, in the background.

```yaml
  configmanager-scheduler:
    build:
      context: ./modules/zabbix-config-module-main/scripts
      dockerfile: Dockerfile
    container_name: configmanager-scheduler
    restart: unless-stopped
    depends_on:
      - mysql-server
    volumes:
      - ./config-backups:/opt/config-backups   # same folder as zabbix-web above
    environment:
      DB_SERVER: mysql-server
      DB_NAME: zabbix
      DB_USER: zabbix
      DB_PASSWORD: <MYSQL_PASSWORD>
      TZ: Asia/Kolkata
```

This builds a small dedicated image (see `scripts/Dockerfile`) with `netmiko`, `mysql-connector-python`, and `cryptography` baked in at build time — so, unlike a plain `python:3.11-slim` container that reinstalls packages on every restart (and fails silently if it can't reach PyPI), this survives restarts, reboots, and `docker-compose down/up` with zero manual steps.

### Apply it all

```bash
docker-compose build zabbix-web configmanager-scheduler
docker-compose up -d --remove-orphans
```

### Verify

```bash
# Scheduler container is picking up due devices every minute:
docker logs -f configmanager-scheduler

# Backup files are visible from BOTH containers now (same file, same folder):
docker exec zabbix-web              ls /opt/config-backups/
docker exec configmanager-scheduler ls /opt/config-backups/

# Netmiko survived a restart without reinstalling:
docker-compose restart zabbix-web configmanager-scheduler
docker exec zabbix-web              python3 -c "import netmiko; print('OK')"
docker exec configmanager-scheduler python3 -c "import netmiko; print('OK')"
```

If a device's `next_run_at` was set before you applied the timezone fix, nudge it once so it doesn't wait out a stale offset:

```bash
docker exec -it mysql-server mysql -uzabbix -p<db_password> zabbix -e "
UPDATE config_devices
SET next_run_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
WHERE schedule_interval != 'disabled' AND enabled = 1;"
```

---

## Non-Docker Installation

```bash
cp -r zabbix-config-module-main /usr/share/zabbix/modules/
mkdir -p /opt/config-backups
chown www-data:www-data /opt/config-backups
chmod 750 /opt/config-backups

pip3 install netmiko mysql-connector-python cryptography
python3 -c "import netmiko; print('Netmiko OK:', netmiko.__version__)"

mysql -u <zabbix_user> -p <zabbix_database> < sql/schema.sql
mysql -u <zabbix_user> -p <zabbix_database> < sql/migrate_v1_1.sql
mysql -u <zabbix_user> -p <zabbix_database> < sql/migrate_v1_2.sql
mysql -u <zabbix_user> -p <zabbix_database> < sql/migrate_v1_3.sql
```

Your Zabbix DB credentials are in `/etc/zabbix/zabbix_server.conf`.

Install the cron job so the scheduler runs every minute without a browser open:

```bash
sudo ./scripts/install_cron.sh www-data
```

---

## Backup Script Usage (manual test)

```bash
python3 scripts/backup.py \
  --ip 192.168.1.1 \
  --vendor cisco_ios \
  --user admin \
  --pass mysecret \
  --port 22 \
  --out /tmp/test-backup.cfg
```

### Supported vendors

| Vendor Key   | Device Type      | Command                    | Native Netmiko Telnet driver? |
|--------------|------------------|-----------------------------|:---:|
| `cisco_ios`  | Cisco IOS/IOS-XE | `show running-config`       | ✅ |
| `cisco_nxos` | Cisco NX-OS      | `show running-config`       | ✅ |
| `fortinet`   | FortiOS          | `show full-configuration`   | ⚠️ generic fallback |
| `mikrotik`   | RouterOS         | `/export`                   | ⚠️ generic fallback |
| `juniper`    | Junos OS         | `show configuration`        | ✅ |
| `netgear`    | ProSafe          | `show running-config`       | ⚠️ generic fallback |

**SSH vs Telnet:** both are fully wired up end-to-end (UI → PHP → Python → Netmiko) as of v1.4. Set the **Method** dropdown per device and it's honored by manual "Backup Now", the background scheduler, and Config Push alike.

For vendors marked ⚠️, Netmiko has no purpose-built Telnet driver, so a generic line-mode fallback (`generic_telnet`) is used instead. It works for most devices with a plain CLI prompt but doesn't have vendor-specific prompt/paging handling the way `cisco_ios_telnet` does — if a Telnet backup on one of these vendors comes back empty or truncated, check `docker logs` / the Backup History error detail first; SSH remains the more reliable option wherever the device supports it.

**Port note:** the device's Port field is used for whichever method you select — Telnet devices are almost always on port `23`, not `22`. Double-check this when switching a device from SSH to Telnet.

---

## Backup Storage Structure

```
/opt/config-backups/
└── Router-01/
    ├── latest.cfg
    ├── 2026-06-20_0100.cfg
    └── 2026-06-21_0100.cfg
```

---

## Testing the scheduler manually

```bash
docker exec -it configmanager-scheduler python3 /scripts/scheduler.py \
  --db-host mysql-server --db-name zabbix --db-user zabbix --db-pass <db_password> \
  --dry-run
```

`--dry-run` prints which devices are due without actually running backups.

---

## Security Notes

- Device passwords are AES-256-CBC encrypted before storage, keyed off Zabbix's secret key.
- All DB inputs are escaped via Zabbix's `zbx_dbstr()`.
- The backup directory (`/opt/config-backups/`) contains plaintext device configs and **must not be web-accessible** — verify this in your reverse proxy / web server config.
- **Fixed in this build:** `checkPermissions()` in `CControllerConfigManager.php` used to return `true` unconditionally, so any authenticated Zabbix user — not just admins — could view devices, trigger backups, and push config. It now requires Zabbix Admin or Super Admin. If you need finer-grained control (e.g. only Super Admin), change `USER_TYPE_ZABBIX_ADMIN` to `USER_TYPE_SUPER_ADMIN` in that method.
- Never commit real database or device credentials to this repository — use placeholders in docs and environment variables / secrets management for real deployments.

---

## Troubleshooting

**"Tables missing" error on first load**
→ Run the SQL migrations (see Installation, step 4).

**Backup fails with "Authentication failed"**
→ Verify username/password and that SSH (or Telnet) is enabled on the device.

**Backup fails with "Connection timed out"**
→ Check firewall rules between the Zabbix server/container and the device IP/port.

**Empty config received**
→ Check that the user has privilege level 15 (Cisco) or equivalent read access.

**`netmiko` not found after a restart**
→ You're installing it live with `docker exec` instead of baking it into the image. See "Bake Python + Netmiko" above — this is now a build-time step, not a runtime one.

**Old backups disappeared / "View" says file not readable**
→ Almost always means `/opt/config-backups` isn't mounted into `zabbix-web`, or is mounted to a *different* host folder than the scheduler uses. Both containers must mount the exact same host path — see "Edit 1" in the Docker Compose section above.

**Scheduler container logs show `python3: can't open file '/scripts/scheduler.py'`**
→ The volume/build path doesn't point at the real module folder on your host. Confirm with:
```bash
docker exec zabbix-web find /usr/share/zabbix/modules -iname "scheduler.py"
```
and match the `configmanager-scheduler` build `context:` path to whatever that prints.

**Scheduler logs show `bash: line N: --db-host: command not found`**
→ A multi-line `command:` in `docker-compose.yml` got mangled by YAML folding rules. Keep the scheduler's command on a single line, or (recommended) use the `scripts/Dockerfile` approach in this README instead — it avoids the problem entirely.

**"Next Run" / dashboard times look hours off from reality**
→ Timezone mismatch between MySQL and everything else. See Edit 2 above (`--default-time-zone` + `TZ` on `mysql-server`).

**30-Day backup count on the Devices page is a huge, wrong number**
→ Fixed in this build (was a SQL join fan-out bug in `Repository.php`). If you're on an older copy of this module, see the Changelog below for the exact patch.

**Telnet devices fail, or a "Method: Telnet" device is still connecting over SSH**
→ Confirm the device's **Port** field matches the actual Telnet port (usually `23`, not `22`). Then check the backup output/error for a `[WARN] Netmiko has no dedicated Telnet driver for '<vendor>'...` line — this means it's using the `generic_telnet` fallback (Fortinet/MikroTik/Netgear), which is less robust than a vendor-specific driver; if the config comes back empty or garbled, try SSH instead if the device supports it.

**Can't add a Netgear device / vendor dropdown doesn't show Netgear**
→ You're on a copy of the module from before this fix, or you added the module files but didn't run `sql/migrate_v1_3.sql`, which updates the `vendor` column's ENUM in `config_devices` to include `netgear`. Run that migration (see Installation, step 4).

---

## Versioning

| Version | Adds |
|---------|------|
| 1.0     | Device inventory, backup, diff viewer, dashboard |
| 1.1     | Change-detection improvements |
| 1.2     | Config templates, push, bulk push, restore |
| 1.3     | Scheduler/timezone/backup-persistence/permission fixes |
| 1.4     | Working SSH/Telnet switching for backup + push, Netgear (ProSafe) vendor support |
| 1.4.1   | Fixed Edit Device dialog not showing the device's actual saved Method (this build) |

---

## Changelog / Fixes Applied in This Build

1. **30-Day backup count showing absurd numbers (e.g. 1178 instead of ~38)**
   `Repository.php::getDevices()` joined `config_backups` and `config_changes` to `config_devices` in a single query without pre-aggregating either side, causing a row fan-out (cross product) before the `SUM(CASE...)` ran. Rewritten to aggregate each join in a derived subquery first, so counts are correct.

2. **Scheduled backups never actually ran on their own**
   Nothing in the original setup ran `scripts/scheduler.py` continuously — it only fired when someone had the Config Manager page open in a browser. Added a dedicated `configmanager-scheduler` container (`scripts/Dockerfile` + docker-compose service) that checks every 60 seconds independent of the browser.

3. **Backup/next-run times looked hours off from reality**
   `mysql-server` had no timezone configured (defaulted to UTC) while other containers were set to `Asia/Kolkata`. Since `next_run_at` and all display times are computed from MySQL's `NOW()`, this caused a multi-hour offset. Fixed by setting `--default-time-zone` and `TZ` on `mysql-server`.

4. **Netmiko had to be reinstalled after every restart, and old backups disappeared with it**
   Packages installed via `docker exec ... pip install` live only in that container's writable layer, wiped on every recreate. Worse, `/opt/config-backups` was never mounted to the host from `zabbix-web`, so any backups made through the browser (manual "Backup Now", View, Diff, Restore) lived in that same ephemeral layer and were lost right along with netmiko on every restart. Fixed by (a) baking netmiko/mysql-connector/cryptography into both the `zabbix-web` and scheduler images at build time, and (b) mounting the same host folder (`./config-backups`) into both containers.

5. **Possible duplicate backups from two independent triggers**
   The in-browser scheduler (`doAction()` on every page load) and the new background scheduler container both check for due devices independently. Added a `CM_EXTERNAL_SCHEDULER` environment flag — set it on `zabbix-web` once the background scheduler container is running, and the in-browser auto-check steps aside, leaving the container as the single source of truth. Manual "Backup Now" and Push actions are unaffected.

6. **Any logged-in user could trigger backups and push config to live devices**
   `checkPermissions()` returned `true` unconditionally. Now requires Zabbix Admin or Super Admin.

7. **The Telnet option in the Method dropdown did nothing — everything backed up/pushed over SSH regardless**
   `backup_method` was saved to the database but never actually read anywhere downstream:
   - `CControllerConfigManager.php::runBackup()` built the `backup.py` command line without a `--method` flag.
   - `CControllerConfigManager.php::execPushScript()` built the JSON payload for `push_config.py` without a `method` field.
   - `scripts/scheduler.py` built its own `backup.py` command line without `--method` either.
   - `scripts/backup.py` and `scripts/push_config.py` didn't even accept a method argument — their vendor maps hardcoded a single (SSH) Netmiko `device_type` per vendor, with no `_telnet` variant.

   Fixed across all four files: `backup.py` and `push_config.py` now accept `--method`/`method` and resolve the correct Netmiko `device_type` (e.g. `cisco_ios` → `cisco_ios_telnet`), and both the manual-backup, push, and scheduler code paths in PHP/Python now pass the device's actual configured method through instead of silently dropping it. Vendors without a dedicated Netmiko Telnet driver (Fortinet, MikroTik, Netgear) fall back to `generic_telnet` with a logged warning, since SSH remains more reliable for those.

8. **Netgear devices couldn't be added at all**
   Added `netgear` (Netmiko `netgear_prosafe`) to the vendor dropdown, badge styling, and — critically — the `vendor` column's MySQL `ENUM` in `config_devices`, which previously would have silently rejected or truncated the value even if the UI allowed picking it. Existing installs should run `sql/migrate_v1_3.sql` to update the ENUM without losing data.

9. **Edit Device dialog appeared to "forget" Telnet and reset to SSH after saving**
   The save itself worked correctly — the bug was purely in `cmOpenEditDialog()` in `configmanager.view.php`: it looked up the Method `<select>` by the id `edit-backup_method`, but the actual element's id is `edit-method`. Because the ids never matched, the dropdown was never pre-filled with the device's real saved method when you reopened the Edit dialog — it just showed whatever the browser's first `<option>` (SSH) happened to be, making a correctly-saved Telnet device look like it had reverted. Fixed by mapping each field name to its real element id explicitly.
