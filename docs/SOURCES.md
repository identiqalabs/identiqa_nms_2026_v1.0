# Sources

This repository was put together from the repositories below, each at the
commit listed. Old git history wasn't brought across, so it doesn't carry over
the credentials that were committed in some of those repos.

| Folder here | Source repository | Commit |
|---|---|---|
| `stack/` | [identiqalabs/zabbix-docker1](https://github.com/identiqalabs/zabbix-docker1) | `051265a` |
| `modules/config-manager/` | [identiqalabs/zabbix-config-module-main](https://github.com/identiqalabs/zabbix-config-module-main) | `7e0cdbd` |
| `modules/network-device-manager/` | [identiqalabs/zabbix-network-device-module](https://github.com/identiqalabs/zabbix-network-device-module) | `1f0fce6` |
| `modules/grafana-dashboards/` | [identiqalabs/zabbix-grafana-module-main](https://github.com/identiqalabs/zabbix-grafana-module-main) | `098973b` |
| `modules/log-manager/` | [identiqalabs/zabbix-logs-module](https://github.com/identiqalabs/zabbix-logs-module) | `de485f2` |
| `modules/ipam/` (module, scanner, Linux guide) | [identiqalabs/ipam-module-linux](https://github.com/identiqalabs/ipam-module-linux) | `72d31d9` |
| `modules/ipam/windows-scanner/`, Windows guide | [identiqalabs/ipam-module-windows](https://github.com/identiqalabs/ipam-module-windows) | `fb64b28` |
| `templates/` | [identiqalabs/Zabbix-Templates](https://github.com/identiqalabs/Zabbix-Templates) | `0b8bca5` |
| `logging/loki-stack/` | [identiqalabs/grafana-loki-linux](https://github.com/identiqalabs/grafana-loki-linux) | `19befa2` |
| `dashboard/` | [identiqalabs/unified-dashboard](https://github.com/identiqalabs/unified-dashboard) | `f268939` |
| `licensing/backend/` | [identiqalabs/license_backend](https://github.com/identiqalabs/license_backend) | `9a2fe46` |
| `licensing/frontend/` | [identiqalabs/license_frontend](https://github.com/identiqalabs/license_frontend) | `cd80f5b` |
| `docs/ova-setup.md` | [identiqalabs/OVA-SETUP](https://github.com/identiqalabs/OVA-SETUP) | `77d1943` |

## What changed during the move

- **Left out:** `.env` files, log files, `node_modules/`, `logs/`. They are
  local or runtime files, and some contained live credentials.
- **IPAM:** the Linux and Windows repos contained the same module and scanner.
  They are merged into `modules/ipam/`, and the Windows install scripts are in
  `windows-scanner/`, the folder the scripts themselves expect.
- **Docs:** module guides kept their content. Paths were updated where files
  moved, and the shared MySQL password in Markdown files was replaced with
  `<MYSQL_PASSWORD>`. New docs: this folder, the root README, `stack/README.md`,
  `modules/ipam/README.md`, `licensing/README.md` and `licensing/frontend/README.md`.
- **Code:** no source, config or compose files were changed.

## Not included

| Repository | Why |
|---|---|
| `zabbix-docker`, `zbxdocker` | Older versions of `stack/` (Zabbix 7.4) |
| `license_frontend1` | Identical to `license_frontend` |
| `oFLicenseServer` | Older version of the license frontend |
| `oBLicenceServer` | Older license server (Render); replaced by `licensing/backend` |
| `Licence-aws-backend` | Build output (`function.zip`) of `licensing/backend` |
| `APM`, `BSF-APP`, `scripts`, `patch-management-iq`, `oProjects`, `oZabbix`, `oNagiOS`, `demo-repository` | Outside the NMS and licensing scope |
