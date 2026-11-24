<?php

namespace Modules\NetworkDeviceManager\Actions;

use CController;
use CControllerResponseData;
use API;
use Modules\NetworkDeviceManager\Classes\DbManager;

class NetworkDevViewAction extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // 1. Fetch credentials from our local DbManager
        $db = new DbManager();
        $savedDevices = $db->getDevices();
        $savedDevicesByIp = [];
        foreach ($savedDevices as $dev) {
            $savedDevicesByIp[$dev['ip']] = $dev;
        }

        // 2. Fetch discovered hosts from Zabbix API
        $discoveredHosts = [];
        try {
            $discoveredHosts = API::DHost()->get([
                'output' => ['dhostid', 'druleid', 'status', 'lastup'],
                'selectDServices' => ['dserviceid', 'ip', 'port', 'status', 'key_', 'value', 'type']
            ]);
        } catch (\Exception $e) {
            // Fallback if discovery is disabled or throws error
            $discoveredHosts = [];
        }

        // 3. Fetch discovery rules to map names
        $ruleNames = [];
        try {
            $dRules = API::DRule()->get([
                'output' => ['druleid', 'name']
            ]);
            foreach ($dRules as $rule) {
                $ruleNames[$rule['druleid']] = $rule['name'];
            }
        } catch (\Exception $e) {
            // Fail silently
        }

        // 4. Group discovered services by IP
        $devices = [];
        foreach ($discoveredHosts as $dHost) {
            $ruleName = isset($ruleNames[$dHost['druleid']]) ? $ruleNames[$dHost['druleid']] : 'Discovery Rule';
            
            if (isset($dHost['dservices']) && is_array($dHost['dservices'])) {
                foreach ($dHost['dservices'] as $dService) {
                    $ip = $dService['ip'];
                    
                    if (!isset($devices[$ip])) {
                        // Zabbix dservice status: 0 = active/up, 1 = down
                        $statusText = 'Down';
                        if (isset($dService['status']) && (int)$dService['status'] === 0) {
                            $statusText = 'Up';
                        }
                        
                        $lastSeenText = 'Never';
                        if (!empty($dHost['lastup'])) {
                            $lastSeenText = date('Y-m-d H:i:s', (int)$dHost['lastup']);
                        }

                        $devices[$ip] = [
                            'ip' => $ip,
                            'source' => 'Discovered',
                            'rule' => $ruleName,
                            'status' => $statusText,
                            'last_seen' => $lastSeenText,
                            'configured' => isset($savedDevicesByIp[$ip]),
                            'device_id' => isset($savedDevicesByIp[$ip]) ? $savedDevicesByIp[$ip]['id'] : null,
                            'name' => isset($savedDevicesByIp[$ip]) ? $savedDevicesByIp[$ip]['name'] : '',
                            'username' => isset($savedDevicesByIp[$ip]) ? $savedDevicesByIp[$ip]['username'] : '',
                            'auth_type' => isset($savedDevicesByIp[$ip]) ? ($savedDevicesByIp[$ip]['auth_type'] ?? 'password') : '',
                            'protocol' => isset($savedDevicesByIp[$ip]) ? ($savedDevicesByIp[$ip]['protocol'] ?? 'ssh') : '',
                            'port' => isset($savedDevicesByIp[$ip]) ? $savedDevicesByIp[$ip]['port'] : (int)$dService['port']
                        ];
                    }
                }
            }
        }

        // 5. Add manually configured devices that were NOT discovered
        foreach ($savedDevices as $dev) {
            $ip = $dev['ip'];
            if (!isset($devices[$ip])) {
                $devices[$ip] = [
                    'ip' => $ip,
                    'source' => 'Manual',
                    'rule' => 'None',
                    'status' => 'Unknown',
                    'last_seen' => 'N/A',
                    'configured' => true,
                    'device_id' => $dev['id'],
                    'name' => $dev['name'],
                    'username' => $dev['username'],
                    'auth_type' => $dev['auth_type'] ?? 'password',
                    'protocol' => $dev['protocol'] ?? 'ssh',
                    'port' => (int)$dev['port']
                ];
            }
        }

        // Sort by IP only — rows must stay in a predictable position. Previously
        // this sorted configured devices to the top, which meant every device's
        // row would silently jump to a new position each time a DIFFERENT device
        // was configured, making it look like a device had disappeared.
        usort($devices, function($a, $b) {
            return ip2long($a['ip']) <=> ip2long($b['ip']);
        });

        // Pass variables to view
        $data = [
            'devices' => $devices
        ];

        $this->setResponse(new CControllerResponseData($data));
    }
}
