# Grafana + Loki Syslog Stack (Linux)

A one-script installer for a centralised **syslog collection and log search**
stack on Ubuntu. Network devices (Cisco, Juniper, Fortinet, HP, MikroTik, …) and
Linux hosts send syslog to it. The logs are stored per device, shipped into Loki,
and can be searched in Grafana.

## What gets installed

`setup-loki-stack.sh` installs Docker and creates everything under `/data/loki-stack`:

| Component | Image | Port | Role |
|---|---|---|---|
| rsyslog | built locally (`loki-stack-rsyslog`) | `1514/udp`, `1514/tcp` | Receives syslog and writes one file per device IP |
| Promtail | `grafana/promtail:2.9.3` | — | Ships log files to Loki with labels |
| Loki | `grafana/loki:2.9.3` | `3100` | Log storage, **14-day retention** (`336h`) |
| Grafana | `grafana/grafana:12.3.0` | `3000` | UI; the Loki data source is provisioned automatically |

It also sets up logrotate for the raw rsyslog files on the host.

### Log labels

Promtail adds these labels: `vendor`, `device_type`, `device_ip`, `host`,
`severity`, `facility`, `program`. Severity and facility come from the standard
syslog header, so they work the same for every vendor.

## Requirements

- Ubuntu (tested with the Docker apt repository for Ubuntu)
- `sudo` access
- Devices able to send syslog to the server on port **1514** (UDP or TCP)

## Install

```bash
cd logging/loki-stack
chmod +x setup-loki-stack.sh
./setup-loki-stack.sh
```

When it finishes:

- Grafana: `http://<server-ip>:3000` — login `admin` / `admin` (**change it at first login**)
- Loki API: `http://<server-ip>:3100`

## Sending logs

Point each device's syslog target at `<server-ip>`, port `1514`. To test from the server itself:

```bash
logger -n localhost -P 1514 -d 'test message from setup script'
```

Raw logs are written to
`/data/loki-stack/rsyslog/log/unclassified/network/<device_ip>/device.log`.

### Sorting devices by vendor / type

All devices start in the `unclassified/network` bucket. To give a device proper
`vendor` and `device_type` labels, add a routing block in
`/data/loki-stack/rsyslog/rsyslog.conf` (the file has a commented example), then:

```bash
cd /data/loki-stack
sudo docker compose restart rsyslog
```

## Querying in Grafana

Open **Explore** and pick the Loki data source:

```logql
{job="network"} | severity="error"
{device_ip="10.0.0.1"}
```

## Related

- [`../../dashboard`](../../dashboard/) — IQ NMS dashboard; its Logs page reads from this Loki
- [`../../modules/grafana-dashboards`](../../modules/grafana-dashboards/) — embeds Grafana dashboards inside Zabbix
- [`../../modules/log-manager`](../../modules/log-manager/) — an alternative, Zabbix-native syslog solution (stores logs in MySQL)
