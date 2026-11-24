# Known issues

Problems found while consolidating the repositories. The code was moved
**exactly as it was**, so every item below is still present and needs a
decision or a code change.

## 🔴 Security

| # | Where | Issue |
|---|---|---|
| S1 | Old repositories (git history) | Live credentials were committed to public repos: Zabbix/Zammad credentials (`unified-dashboard`), MongoDB URI and admin login (`APM`), MongoDB URI and `HMAC_SECRET` (`Licence-aws-backend` zip), MongoDB URI and SMTP login (`oBLicenceServer` `render.yaml`). **Rotate all of them.** This repo doesn't contain those files, but the old repos' history still does |
| S2 | `stack/docker-compose.yaml` | MySQL root and `zabbix` passwords and the backup DB password are written directly in the file (the same value is used everywhere). `modules/ipam/scanner/compose-snippet.yml` repeats it |
| S3 | `stack/license-agent/licensev1.mjs` | Logs into Zabbix with the default `Admin` account and password, hardcoded |
| S4 | `licensing/backend` | The admin endpoints (`generate-license`, `update-license`, `delete-license`, `all-licenses`) have **no authentication**; anyone who knows the Lambda URL can create or delete licenses |
| S5 | `licensing/frontend/src/components/Login.js` | The admin login is checked in the browser against a username and password hardcoded in the JavaScript, so anyone can read them in the page source. It doesn't protect the API (see S4) |
| S6 | `logging/loki-stack/setup-loki-stack.sh` | Grafana admin password is fixed in the generated compose file |
| S7 | `licensing/backend/server.js` | Prints `MONGO_URI` (including its password) to the console at startup |

## 🟠 Bugs

| # | Where | Issue |
|---|---|---|
| B1 | `stack/license-agent/licensev1.mjs` | `LICENSE_PATH` points to a Windows path on a developer's PC, so inside the container the agent can't read the license. The older `zabbix-docker` repo used `/app/license.lic`, which matches the compose mount |
| B2 | `stack/license-agent/licensev1.mjs` | `ZABBIX_URL` is `http://localhost:8080`. Inside the container this doesn't reach Zabbix; the older version used `http://zabbix-web:8080` |
| B3 | `stack/README.md` (old version) | Showed `license.lic` with `License Key:` / `Instance ID:` labels, which breaks validation. **Fixed in the docs**: see [licensing.md](licensing.md) |
| B4 | `stack/README.md` | The setup steps say to *stop* `license-agent` after starting the stack. Without the agent, nothing checks the license every 10 minutes or reports usage. Confirm this is intended |
| B5 | `stack/license-agent`, `stack/license-proxy` | The License API URL is hardcoded in both files instead of coming from configuration |
| B6 | `dashboard/backend/services/zammadService.js`, `dashboard/pages/Tickets.js` | Zammad links use a fixed ngrok URL, which stops working whenever the tunnel changes |
| B7 | `modules/ipam/ipampro/README.md` | Refers to `scanner/SETUP_LINUX.md` and `windows-scanner/SETUP_STEPS_WINDOWS.md`, which don't exist. Use `modules/ipam/INSTALL-linux.md` / `INSTALL-windows.md` |

## 🟡 To check

| # | Where | Issue |
|---|---|---|
| C1 | `modules/log-manager` | Documented for Zabbix 7.4; the stack now runs **8.0**. Config Manager, Network Device Manager and IPAM don't state a version. Test each module on 8.0 |
| C2 | `templates/` | Three Sophos IPsec templates share the name `Sophos XG FW 18 SNMPv2`, so importing more than one overwrites the earlier import |
| C3 | `modules/config-manager/view_fix.patch` | It's unclear whether this patch is already applied to `views/configmanager.view.php` |
| C4 | `stack/` | `stack/package.json` / `package-lock.json` at the stack root seem left over from running the license agent on the host; the containers use their own `package.json` |
