#!/bin/bash
set -e

BASE_DIR="/data/loki-stack"

echo "=== Installing Docker & Docker Compose ==="
sudo apt update -y
sudo apt install -y ca-certificates curl gnupg lsb-release logrotate
sudo mkdir -m 0755 -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
sudo tee /etc/apt/keyrings/docker.asc > /dev/null
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | \
sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update -y
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

echo "=== Creating directories ==="
mkdir -p "$BASE_DIR"/{promtail,rsyslog/log,loki,grafana/provisioning/datasources,grafana/provisioning/dashboards,grafana/dashboards}
cd "$BASE_DIR"

echo "=== Creating docker-compose.yml ==="
cat << 'EOF' > docker-compose.yml
services:
  loki:
    image: grafana/loki:2.9.3
    container_name: loki
    ports:
      - "3100:3100"
    volumes:
      - ./loki/local-config.yaml:/etc/loki/local-config.yaml:ro
      - loki-data:/loki
    command: -config.file=/etc/loki/local-config.yaml
    restart: unless-stopped

  promtail:
    image: grafana/promtail:2.9.3
    container_name: promtail
    volumes:
      - ./promtail/promtail.yml:/etc/promtail/config.yml:ro
      - ./rsyslog/log:/rsyslog/log:ro
      - promtail-positions:/tmp
    command: -config.file=/etc/promtail/config.yml
    depends_on:
      - loki
    restart: unless-stopped

  rsyslog:
    image: loki-stack-rsyslog
    container_name: rsyslog
    ports:
      - "1514:1514/udp"
      - "1514:1514/tcp"
    volumes:
      - ./rsyslog/rsyslog.conf:/etc/rsyslog.conf:ro
      - ./rsyslog/log:/var/log/rsyslog
    command: rsyslogd -n -f /etc/rsyslog.conf
    restart: unless-stopped

  grafana:
    image: grafana/grafana:12.3.0
    container_name: grafana
    ports:
      - "3000:3000"
    volumes:
      - grafana-data:/var/lib/grafana
      - ./grafana/provisioning:/etc/grafana/provisioning:ro
    environment:
      - GF_SECURITY_ADMIN_USER=admin
      - GF_SECURITY_ADMIN_PASSWORD=admin
    depends_on:
      - loki
    restart: unless-stopped

volumes:
  loki-data:
  grafana-data:
  promtail-positions:
EOF

echo "=== Creating Loki config (with retention) ==="
cat << 'EOF' > loki/local-config.yaml
auth_enabled: false

server:
  http_listen_port: 3100

common:
  path_prefix: /loki
  storage:
    filesystem:
      chunks_directory: /loki/chunks
      rules_directory: /loki/rules
  replication_factor: 1
  ring:
    instance_addr: 127.0.0.1
    kvstore:
      store: inmemory

schema_config:
  configs:
    - from: 2020-10-24
      store: boltdb-shipper
      object_store: filesystem
      schema: v11
      index:
        prefix: index_
        period: 24h

limits_config:
  retention_period: 336h
  reject_old_samples: true
  reject_old_samples_max_age: 168h

compactor:
  working_directory: /loki/compactor
  retention_enabled: true
  delete_request_store: filesystem
EOF

echo "=== Creating rsyslog.conf (vendor-agnostic, structured, per-device files) ==="
cat << 'EOF' > rsyslog/rsyslog.conf
module(load="imudp")
module(load="imtcp")
input(type="imudp" port="1514")
input(type="imtcp" port="1514")

# Structured, vendor-neutral log line.
# severity/facility come from the syslog PRI header (RFC standard),
# not from vendor-specific message text, so this works for Cisco,
# Juniper, Fortinet, HP, MikroTik, Linux hosts, etc.
template(name="StructuredLog" type="string"
  string="%timestamp:::date-rfc3339% severity=%syslogseverity-text% facility=%syslogfacility-text% host=%hostname% program=%programname% msg=%msg%\n")

# One file per source IP address, auto-created.
# Path pattern: /var/log/rsyslog/unclassified/network/<device_ip>/device.log
# "unclassified/network" is a default bucket for any device that hasn't
# been explicitly mapped to a vendor/type below.
template(name="DynaFile" type="string"
  string="/var/log/rsyslog/unclassified/network/%fromhost-ip%/device.log")

# ---- Optional: explicit per-vendor routing ----
# If you want specific IPs sorted into vendor/device_type folders
# (so Promtail's vendor/device_type labels are accurate instead of
# always "unclassified"), add blocks like this ABOVE the catch-all below:
#
# if $fromhost-ip == '10.0.0.1' then {
#   action(type="omfile" dynaFile="CiscoSwitch1" template="StructuredLog")
# }
# template(name="CiscoSwitch1" type="string"
#   string="/var/log/rsyslog/cisco/switch/10.0.0.1/device.log")

# Catch-all: anything not matched above goes to the default bucket.
*.* action(type="omfile" dynaFile="DynaFile" template="StructuredLog")
EOF

echo "=== Creating Promtail config (labels: vendor, device_type, device_ip, host, severity, facility, program) ==="
cat << 'EOF' > promtail/promtail.yml
server:
  http_listen_port: 9080
  grpc_listen_port: 0

positions:
  filename: /tmp/positions.yaml

clients:
  - url: http://loki:3100/loki/api/v1/push

scrape_configs:
  - job_name: network
    static_configs:
      - targets:
          - localhost
        labels:
          job: network
          __path__: /rsyslog/log/**/*.log

    pipeline_stages:
      # Pull vendor / device_type / device_ip from the folder structure.
      # Path shape: /rsyslog/log/<vendor>/<device_type>/<device_ip>/device.log
      - regex:
          source: filename
          expression: '.*/(?P<vendor>[^/]+)/(?P<device_type>[^/]+)/(?P<device_ip>[^/]+)/device\.log'
      - labels:
          vendor:
          device_type:
          device_ip:

      # Parse the structured line written by rsyslog. This is vendor-agnostic:
      # severity/facility/host/program come from the syslog protocol layer,
      # not from vendor-specific message text.
      - regex:
          expression: '^(?P<ts>\S+)\s+severity=(?P<severity>\S+)\s+facility=(?P<facility>\S+)\s+host=(?P<host>\S+)\s+program=(?P<program>\S+)\s+msg=(?P<message>.*)$'
      - labels:
          severity:
          facility:
          host:
          program:

      # Use the actual event time instead of ingestion time.
      - timestamp:
          source: ts
          format: RFC3339

      # Keep the clean message as the stored log line (drops the
      # severity=/facility=/host=/program= prefix from the body since
      # that info now lives in labels).
      - output:
          source: message
EOF

echo "=== Creating Dockerfile for rsyslog ==="
cat << 'EOF' > rsyslog/Dockerfile
FROM ubuntu:22.04
RUN apt-get update && \
    apt-get install -y rsyslog && \
    rm -rf /var/lib/apt/lists/*
COPY rsyslog.conf /etc/rsyslog.conf
CMD ["rsyslogd", "-n"]
EOF

echo "=== Creating Grafana datasource provisioning (Loki auto-configured) ==="
cat << 'EOF' > grafana/provisioning/datasources/loki.yaml
apiVersion: 1
datasources:
  - name: Loki
    type: loki
    access: proxy
    url: http://loki:3100
    isDefault: true
    jsonData:
      maxLines: 5000
EOF

echo "=== Creating Grafana dashboard provisioning pointer ==="
cat << 'EOF' > grafana/provisioning/dashboards/dashboards.yaml
apiVersion: 1
providers:
  - name: default
    orgId: 1
    folder: ""
    type: file
    disableDeletion: false
    updateIntervalSeconds: 30
    options:
      path: /etc/grafana/provisioning/dashboards
EOF

echo "=== Setting permissions ==="
sudo chown -R 10001:10001 "$BASE_DIR/grafana" 2>/dev/null || true
chmod -R 777 "$BASE_DIR/rsyslog/log"

echo "=== Setting up log rotation for raw rsyslog files on the host ==="
sudo tee /etc/logrotate.d/loki-stack-rsyslog > /dev/null << EOF
$BASE_DIR/rsyslog/log/**/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF

echo "=== Building rsyslog image ==="
sudo docker build -t loki-stack-rsyslog ./rsyslog

echo "=== Starting stack ==="
sudo docker compose up -d

echo ""
echo "======================================"
echo "STACK IS READY"
echo "======================================"
echo "Grafana URL: http://YOUR_VM_IP:3000"
echo "Username: admin"
echo "Password: admin"
echo "Loki datasource is already provisioned — no manual setup needed in Grafana."
echo ""
echo "Send a test log (from this host):"
echo "  logger -n localhost -P 1514 -d 'test message from setup script'"
echo ""
echo "View raw structured logs:"
echo "  find $BASE_DIR/rsyslog/log -name device.log -exec tail -f {} +"
echo ""
echo "In Grafana Explore, try this LogQL query once devices are sending logs:"
echo '  {job="network"} | severity="error"'
echo ""
echo "To route a specific device into a named vendor/type folder instead of"
echo "the 'unclassified' bucket, edit $BASE_DIR/rsyslog/rsyslog.conf"
echo "(see the commented example block) then: sudo docker compose restart rsyslog"
