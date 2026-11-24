# Zabbix Templates

Custom SNMP templates for monitoring network devices in Zabbix. Most of them
cover **Sophos XG firewalls** and **HP / Aruba switches**.

## Templates

| File | Template name | Device | Zabbix export version |
|---|---|---|---|
| `HP_ProCurve_Aruba_3810M_SNMP_Zabbix_7.4.yaml` | HP ProCurve Aruba 3810M SNMP | HP / Aruba 3810M (single switch) | 7.4 |
| `template_hp_aruba_3810m_stack_snmpv2.yaml` | HP ProCurve Aruba 3810M SNMP - Stack | HP / Aruba 3810M stack, SNMPv2 | 7.4 |
| `template_net_hp_hpn_snmp.yaml` | HP Enterprise Switch by SNMP | HPE switches | 8.0 |
| `template_sophos_xg_firewall.yaml` | MIB XG-FIREWALL-MIB - sophos | Sophos XG firewall (MIB-based) | 6.0 |
| `template_sophos_xg_firewall_version_18_0_x_fixed.yaml` | — | Sophos XG firewall, firmware 18.0.x | 6.0 |
| `Sophos_XG_FW_full_template_FIXED.yaml` | — | Sophos XG firewall (full) | 6.0 |
| `template_sophos_xg_ipsec_vpn_fixed_numeric_oids.yaml` | Sophos XG FW 18 SNMPv2 | Sophos XG IPsec VPN (numeric OIDs) | 7.0 |
| `template_sophos_xg_ipsec_vpn_version_21.0.x.yaml` | Sophos XG FW 18 SNMPv2 | Sophos XG IPsec VPN, firmware 21.0.x | 7.0 |
| `template_sophos_xg_ipsec_vpn_version_21_0_x_fixed (1).yaml` | Sophos XG FW 18 SNMPv2 | Sophos XG IPsec VPN, firmware 21.0.x (fixed) | 7.0 |

Zabbix can import templates exported from the same or an older version. For
example, a 6.0 export imports into 7.4, but an 8.0 export does **not** import into 7.4.

> Several Sophos IPsec files share the template name `Sophos XG FW 18 SNMPv2`.
> Importing more than one of them will update the same template, so import only
> the one that matches your firmware.

## Importing a template

1. In Zabbix, go to **Data collection → Templates**.
2. Click **Import** and choose the `.yaml` file.
3. Keep the default import rules (create new, update existing) and click **Import**.
4. Link the template to the host (**Data collection → Hosts → host → Templates**).

## Device prerequisites

- Enable SNMP (v2c) on the device and allow the Zabbix server or proxy IP.
- On the Zabbix host, add an **SNMP interface** and set the `{$SNMP_COMMUNITY}` macro.

### Testing SNMP access

From the Zabbix server or proxy, confirm the device answers before linking the template:

```bash
snmpwalk -v2c -c "<community>" <device-ip> system
```

Install the tools with `apt install snmp` (Debian/Ubuntu) if needed.
