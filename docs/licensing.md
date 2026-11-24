# Licensing

Every NMS deployment needs a valid license before its Zabbix UI can be used.

## Pieces involved

| Piece | Where | Role |
|---|---|---|
| License API | `licensing/backend` (AWS Lambda + MongoDB Atlas) | Stores licenses; validates keys; records usage |
| Admin UI | `licensing/frontend` (React) | Identiqa staff create, view, update and delete licenses |
| `license-proxy` | `stack/license-proxy` | Blocks the Zabbix UI unless the license is valid |
| `license-agent` | `stack/license-agent` | Periodically checks the license, starts or stops `zabbix-server`, reports usage |
| `license.lic` | `stack/license/license.lic` | The deployment's license key and instance ID |

## Flow

1. Identiqa creates a license in the admin UI, which calls `POST /backend_api/generate-license`.
   The API returns a **license key** and an **instance ID**.
2. The client (or the OVA first-login prompt, see [ova-setup.md](ova-setup.md))
   writes them to `stack/license/license.lic`.
3. On every web request, `license-proxy` calls
   `GET /backend_api/check-license/<key>`. If the response is `valid: true`, it
   forwards the request to `zabbix-web`. Otherwise it shows `custom.html`.
4. Every 10 minutes, `license-agent` validates the license again, keeps
   `zabbix-server` running only while it is valid, and sends host count and
   Zabbix version to `POST /backend_api/check-license/usage`.

## `license.lic` format

Two lines: **just the values**, with no labels.

```
<LICENSE_KEY>
<INSTANCE_ID>
```

```bash
printf '%s\n%s\n' "<LICENSE_KEY>" "<INSTANCE_ID>" > stack/license/license.lic
```

> The old stack README showed `License Key: ...` / `Instance ID: ...` labels.
> The code splits the file on the newline and uses each whole line as the
> value, so labels make the license invalid.

## Backend API

See [licensing/backend/README.md](../licensing/backend/README.md) for the full
endpoint list, configuration and the Lambda deployment steps.
