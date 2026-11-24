# Network Device Manager — Zabbix Module

A Zabbix frontend module that adds credential management and an in-browser SSH/Telnet terminal for network devices and hosts monitored by Zabbix — no separate SSH/Telnet client required.

![Zabbix](https://img.shields.io/badge/Zabbix-7.4-red)
![PHP](https://img.shields.io/badge/PHP-8.4-777bb4)
![License](https://img.shields.io/badge/license-Proprietary-lightgrey)

---

## Features

- **Devices page** (`Monitoring → Network Devices`) listing all discovered/monitored hosts alongside which ones have credentials configured.
- **Save credentials per device** — username + password, or username + private key (`.pem`) with an optional key passphrase (SSH only), plus a per-device choice of **SSH or Telnet** as the connection protocol.
- **In-browser terminal** — click a device to open a live SSH or Telnet session rendered with [xterm.js](https://xtermjs.org/), right inside the Zabbix UI.
- **Telnet auto-login** — when a password is saved, the module watches for the device's own `login:`/`Password:` prompts and types the credentials in automatically, the same way `sshpass` does for SSH. If no password is saved, the prompt is simply left on screen to answer manually.
- Credentials are **encrypted at rest** (AES-256-CBC) before being written to disk.
- Automatically falls back to JSON file storage if the `SQLite3` PHP extension isn't available.
- Legacy SSH algorithm support baked in (KEX/host-key/MAC overrides) for older network gear that modern OpenSSH clients refuse to negotiate with by default.

---

## Requirements

This module needs system tools that most minimal/Alpine-based Zabbix Docker images **do not ship by default**:

| Requirement | Why |
|---|---|
| `php-cli` (e.g. `php84-cli` on Alpine) | Runs the background SSH/Telnet worker process. Zabbix images typically only include `php-fpm`, not the standalone CLI binary. |
| `openssh-client` | Provides the actual `ssh` command. Required if any device is configured to use SSH. |
| `sshpass` | Allows non-interactive password/passphrase authentication for SSH. |
| `telnet` (e.g. `busybox-extras` or `inetutils-telnet` on Alpine, `telnet`/`telnet-client` on Debian/Ubuntu) | Provides the actual `telnet` command. Only required if any device is configured to use Telnet. |
| `exec()` / `proc_open()` enabled in PHP | Some hardened PHP builds disable these by default — check `disable_functions` in `php.ini`. |

If you're running the official `zabbix/zabbix-web-nginx-*:*-alpine-*` images, see [`docs/Network-Device-Manager-Installation-Guide.md`](docs/Network-Device-Manager-Installation-Guide.md) for a ready-to-use Dockerfile that adds all three.

---

## Installation

1. **Build the required tools into your Zabbix web image** (skip if already present):
   ```dockerfile
   FROM zabbix/zabbix-web-nginx-mysql:7.4-alpine-latest
   USER root
   RUN apk add --no-cache php84-cli openssh-client sshpass busybox-extras
   USER zabbix
   ```
   (`busybox-extras` provides the `telnet` client on Alpine — leave it out if you only ever connect over SSH.)

2. **Place the module.** Unzip this module so the folder ends up here on your Docker host:
   ```
   zabbix-docker/
   ├── docker-compose.yml
   ├── modules/
   │   └── zabbix-network-device-module/     ← this repo goes here
   ├── config-backups/                       ← create this empty folder (see step 3)
   └── ...
   ```
   > The module folder name **must** be `zabbix-network-device-module`.

3. **Set ownership and permissions** on the module folder (Ubuntu host example):
   ```bash
   sudo chown -R 1997:1995 ~/zabbix-docker/modules/zabbix-network-device-module
   sudo chmod -R 775 ~/zabbix-docker/modules/zabbix-network-device-module
   ```

4. **Create and permission the module's data folder** (stores encrypted credentials + active session state):
   ```bash
   docker exec -u 0 zabbix-web mkdir -p /usr/share/zabbix/modules/zabbix-network-device-module/data
   docker exec -u 0 zabbix-web chown -R zabbix:zabbix /usr/share/zabbix/modules/zabbix-network-device-module
   docker exec -u 0 zabbix-web chmod -R 775 /usr/share/zabbix/modules/zabbix-network-device-module/data
   ```

5. **Enable the module in Zabbix:** `Administration → General → Modules → Scan directory`, then set it to **Enabled**.

Full step-by-step instructions (including Docker Compose changes and troubleshooting) are in [`docs/Network-Device-Manager-Installation-Guide.md`](docs/Network-Device-Manager-Installation-Guide.md).

> ⚠️ **Persistence:** `docker cp` copies files into a running container's filesystem only. If the container is recreated (not just restarted), the module and its saved credentials will be lost unless you mount it as a proper volume in `docker-compose.yml`. See the installation guide's "Making this permanent" section.

---

## Permissions

| Action | Minimum Zabbix user role |
|---|---|
| View device list, open a terminal session | **User** |
| Add / edit / delete saved SSH credentials | **Admin** or **Super Admin** |

---

## How it works

1. Credentials (and the chosen protocol — SSH or Telnet) for a device are saved (encrypted) to a local SQLite database or JSON file inside the module's `data/` directory.
2. Clicking **Terminal** on a device starts a short-lived background PHP process (`worker/terminal_worker.php`) that opens a real `ssh` or `telnet` connection to that device, depending on how it's configured.
3. The browser can't hold that connection directly, so the frontend (xterm.js) and that background process communicate through small session files, polled several times a second via AJAX (`networkdev.terminal.bridge`).
4. For SSH, non-interactive login is handled by `sshpass`. For Telnet — which has no built-in credential exchange — the worker instead watches the raw output stream for the device's own `login:`/`Password:` prompts and types the saved username/password in response, then gets out of the way for the rest of the session.
5. When the session ends (explicit close, remote disconnect, or timeout), the worker process exits and its temporary session files — including any `.pem` key material written to disk for that session — are deleted.

---

## Project structure

```
zabbix-network-device-module/
├── manifest.json                        # Module registration (routes → controller classes)
├── Module.php                           # Adds "Network Devices" to the Monitoring menu
├── actions/
│   ├── NetworkDevViewAction.php         # Device list page
│   ├── NetworkDevAddAction.php          # Add/edit/delete credentials
│   ├── NetworkDevTerminalAction.php     # Terminal page shell
│   └── NetworkDevTerminalBridgeAction.php  # AJAX bridge (init/poll/close)
├── classes/
│   └── DbManager.php                    # Encrypted credential storage (SQLite or JSON)
├── worker/
│   └── terminal_worker.php              # Background SSH/Telnet process spawned per session
├── views/
│   ├── networkdev.view.php
│   ├── networkdev.add.php
│   └── networkdev.terminal.php
└── assets/
    ├── css/style.css
    └── js/terminal.js                   # xterm.js wiring, keystroke/output polling
```

---

## Security notes

- Passwords, private keys, and key passphrases are encrypted (AES-256-CBC) before being written to disk. The encryption key itself is generated once and stored in `data/secret.key` inside the module folder — back this up along with the rest of `data/` (losing it makes existing saved credentials unrecoverable).
- When a password/passphrase is used to authenticate over SSH, it is briefly visible in the container's process list (via `sshpass`) for the duration of that connection attempt. This is an inherent limitation of the password-injection approach, not something a wrapper script can fully hide.
- **Telnet is unencrypted end-to-end** — the username, password, and everything typed in the session travel over the network in plain text, regardless of anything this module does. Only use the Telnet option for devices that genuinely don't support SSH, and only on trusted, isolated management networks. The UI shows a warning when Telnet is selected, but the choice to use it is intentionally left up to whoever configures the device.
- The Telnet auto-login watcher matches on generic prompt wording (`login:`, `username:`, `password:`) rather than a vendor-specific script. Most Linux/Cisco-style devices match fine, but a device with an unusual prompt may not auto-login — in that case, just type the credentials manually in the terminal window the same way you would with a plain `telnet` session.
- Older/legacy key-exchange, host-key, and MAC algorithms are enabled by default to support older network gear. This is intentionally permissive for compatibility — if all your devices support modern algorithms, consider tightening `worker/terminal_worker.php`.
- Restrict "Add/Edit Credentials" access to trusted Admins only (see [Permissions](#permissions)) — anyone who can edit a device's credentials can also view/replace them.

---

## Known limitations

- No live terminal resize once a session is open — the terminal is sized correctly when a session starts, but resizing your browser window mid-session won't propagate to the remote shell.
- Polling-based, not a persistent connection (no WebSocket support) — there is an inherent minimum latency floor from this design; typing feels responsive but not identical to a native terminal.
- Telnet auto-login is prompt-pattern based, not a full `expect` dialect — it handles the common `login:`/`Password:` flow but won't navigate multi-step or menu-driven login sequences some devices use.
- Single-server deployments only; no clustering/HA consideration for the credential store.

---

## License

Internal / proprietary — Identiqa Consulting Pvt Ltd. Not for external distribution.
