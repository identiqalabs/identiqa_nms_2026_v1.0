# IPAM Module

IP address management inside Zabbix: subnets, IP addresses, VLANs,
reservations, network scanning (nmap), CSV export, and sync with Zabbix hosts.

| | |
|---|---|
| Module ID | `ipampro` |
| Version | 1.0.0 |
| Install folder | `stack/modules/ipampro` |

## Contents

```
modules/ipam/
├── ipampro/              The Zabbix frontend module (PHP)
│   ├── sql/schema.sql    Database tables, applied before enabling the module
│   └── README.md         Feature details
├── scanner/              Background scanner (polls for scan requests, runs nmap)
│   ├── scan_daemon.py    Same script on Linux and Windows
│   ├── Dockerfile        Linux: runs as a container
│   ├── requirements.txt
│   └── compose-snippet.yml
├── windows-scanner/      Windows: installs the scanner as a Scheduled Task
│   ├── install_windows_service.ps1
│   └── uninstall_windows_service.ps1
├── INSTALL-linux.md
└── INSTALL-windows.md
```

## How it works

1. The module shows the IPAM pages in Zabbix and stores data in MySQL.
2. When you start a scan, the module adds a request to a database table.
3. The **scanner** polls that table, runs nmap, and writes the results back.

The scanner needs real network access to your LAN, so where it runs depends on the host:

| Docker host | Guide | Scanner runs as |
|---|---|---|
| Linux | [INSTALL-linux.md](INSTALL-linux.md) | Docker container with `network_mode: host` |
| Windows (Docker Desktop) | [INSTALL-windows.md](INSTALL-windows.md) | Native Python process, started as a Scheduled Task |

Both guides follow the shared module steps in
[../../docs/modules.md](../../docs/modules.md) and add the schema and scanner setup.
