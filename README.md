# Identiqa NMS

Identiqa Labs' network monitoring platform: a branded **Zabbix** server with
add-on modules, centralised syslog, a unified dashboard, and a licensing
service. All of it lives in this repository.

| Area | Folder | What it is |
|---|---|---|
| **Core stack** | [`stack/`](stack/) | Docker Compose deployment: Zabbix 8.0, MySQL, SNMP traps, branding, license agent and proxy, weekly DB backups |
| **Zabbix modules** | [`modules/`](modules/) | Frontend modules that add pages to Zabbix — see the table below |
| **Templates** | [`templates/`](templates/) | SNMP templates for Sophos XG firewalls and HP/Aruba switches |
| **Logging** | [`logging/loki-stack/`](logging/loki-stack/) | rsyslog → Promtail → Loki → Grafana syslog stack |
| **Dashboard** | [`dashboard/`](dashboard/) | IQ NMS unified dashboard (Zabbix, Loki, Zammad, Oxidized) |
| **Licensing** | [`licensing/`](licensing/) | License API (AWS Lambda) and admin web UI |
| **Docs** | [`docs/`](docs/) | Architecture, module installation, licensing, OVA preparation, known issues |

### Zabbix modules

| Module | Folder | Version | Adds to Zabbix |
|---|---|---|---|
| Config Manager | [`modules/config-manager/`](modules/config-manager/) | 1.4.1 | Network device config backup, push, diff and restore, with a scheduler |
| Network Device Manager | [`modules/network-device-manager/`](modules/network-device-manager/) | 1.0.0 | Device inventory and in-browser SSH/Telnet terminal |
| IPAM | [`modules/ipam/`](modules/ipam/) | 1.0.0 | Subnets, IP addresses, VLANs, network scanning, sync with Zabbix hosts |
| Log Manager | [`modules/log-manager/`](modules/log-manager/) | 1.0.0 | Syslog receiver, live log view, search, alerts and retention |
| Grafana Dashboards | [`modules/grafana-dashboards/`](modules/grafana-dashboards/) | 2.0.0 | Embeds Grafana dashboards inside Zabbix |

## How it fits together

```
                    ┌──────────────────────── stack/ (Docker Compose) ─────────────────────────┐
 Browser ── :8080 ──► license-proxy ──(license valid)──► zabbix-web ◄── modules/* (mounted)    │
                    │        │                              │                                  │
                    │        ▼                              ▼                                  │
                    │  License API ◄── license-agent    zabbix-server ── mysql-server ── backup│
                    └────────│─────────────────────────────────────────────────────────────────┘
                             │
          licensing/backend (AWS Lambda + MongoDB) ◄── licensing/frontend (admin UI)

 Network devices ── syslog :1514 ──► logging/loki-stack ──► Loki ──► Grafana / dashboard/
                 ── SNMP traps :162 ──► stack/zabbix-snmptraps
```

More detail: [docs/architecture.md](docs/architecture.md).

## Quick start

1. **Deploy the core stack.** See [stack/README.md](stack/README.md).
2. **Add a license.** Put the key and instance ID in `stack/license/license.lic`
   ([docs/licensing.md](docs/licensing.md)).
3. **Install the modules you need.** All modules are installed the same way:
   [docs/modules.md](docs/modules.md).
4. **Optional:** set up central syslog ([logging/loki-stack](logging/loki-stack/README.md))
   and the unified dashboard ([dashboard](dashboard/README.md)).
5. **Shipping to a client as a VM?** Follow [docs/ova-setup.md](docs/ova-setup.md).

## Repository conventions

- **No secrets in git.** `.env` files, logs, `node_modules/`, build output and
  `stack/backups/` are ignored. Use the `.env.example` files as templates.
- **Each folder has its own README** with setup and configuration details.
- **Modules are installed into `stack/modules/`** (ignored by git), which is
  mounted into the `zabbix-web` container.
- Before deploying anything, read [docs/known-issues.md](docs/known-issues.md).

## Where this code came from

This repository brings together 13 earlier repositories. [docs/SOURCES.md](docs/SOURCES.md)
lists the original repository and commit for every folder.
