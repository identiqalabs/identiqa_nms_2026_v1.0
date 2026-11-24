<?php

namespace Modules\NetworkDeviceManager\Actions;

use CController;
use CControllerResponseData;
use Modules\NetworkDeviceManager\Classes\DbManager;

class NetworkDevTerminalBridgeAction extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'bridge_action' => 'required|string|in '.implode(',', ['init', 'poll', 'close', 'resize']),
            'device_id' => 'string',
            'session_id' => 'string',
            'input' => 'string', // Base64 encoded input keys
            'offset' => 'string',  // Byte offset for reading output
            'cols' => 'string',
            'rows' => 'string'
        ]);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $bridgeAction = $this->getInput('bridge_action');
        $db = new DbManager();
        $dataDir = __DIR__ . '/../data';

        $response = ['status' => 'error', 'message' => 'Unknown action'];

        if ($bridgeAction === 'init') {
            if ($db->storageError) {
                $response = ['status' => 'error', 'message' => $db->storageError];
                $this->sendJson($response);
                return;
            }

            $deviceId = (int)$this->getInput('device_id');
            $device = $db->getDevice($deviceId);

            if ($device) {
                // Preflight checks — without these, a broken environment silently
                // reports "success" and the terminal just sits there blank forever.
                if (!function_exists('exec') || !function_exists('proc_open')) {
                    $response = ['status' => 'error', 'message' =>
                        "PHP's exec()/proc_open() functions are disabled on this server " .
                        "(check disable_functions in php.ini). The terminal cannot spawn a background process without them."];
                    $this->sendJson($response);
                    return;
                }

                $phpBinary = null;
                foreach (['php', 'php84', 'php83', 'php82', 'php8'] as $candidate) {
                    $path = trim((string)shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null'));
                    if ($path !== '') {
                        $phpBinary = $path;
                        break;
                    }
                }
                if ($phpBinary === null) {
                    $response = ['status' => 'error', 'message' =>
                        "No PHP CLI binary found in PATH (only php-fpm is installed). " .
                        "Install php-cli / php84-cli in the Zabbix web container image."];
                    $this->sendJson($response);
                    return;
                }

                $protocol = ($device['protocol'] ?? 'ssh') === 'telnet' ? 'telnet' : 'ssh';

                $clientBinary = $protocol === 'telnet' ? 'telnet' : 'ssh';
                $clientPath = trim((string)shell_exec('which ' . escapeshellarg($clientBinary) . ' 2>/dev/null'));
                if ($clientPath === '') {
                    $response = ['status' => 'error', 'message' =>
                        "No '$clientBinary' client found in PATH. Install " .
                        ($protocol === 'telnet' ? 'telnet (inetutils-telnet / telnet-client)' : 'openssh-client') .
                        " in the Zabbix web container image."];
                    $this->sendJson($response);
                    return;
                }

                // Generate a unique session ID
                $sessionId = bin2hex(random_bytes(16));

                // Terminal size reported by xterm.js — without this, the remote
                // shell defaults to 80 columns and wraps output (e.g. `docker ps`)
                // regardless of how wide the browser window actually is.
                $cols = max(20, min(500, (int)$this->getInput('cols', '120')));
                $rows = max(5, min(200, (int)$this->getInput('rows', '30')));

                // Save session configuration (host details + decrypted secrets)
                $authType = $device['auth_type'] ?? 'password';
                $sessionCfg = [
                    'ip' => $device['ip'],
                    'port' => (int)$device['port'],
                    'username' => $device['username'],
                    'protocol' => $protocol,
                    'auth_type' => $authType,
                    'password' => ($authType === 'password') ? $db->decrypt($device['password']) : '',
                    'private_key' => (!empty($device['private_key'])) ? $db->decrypt($device['private_key']) : '',
                    'key_passphrase' => (!empty($device['key_passphrase'])) ? $db->decrypt($device['key_passphrase']) : '',
                    'cols' => $cols,
                    'rows' => $rows
                ];

                file_put_contents($dataDir . '/session_' . $sessionId . '.cfg', json_encode($sessionCfg));
                @chmod($dataDir . '/session_' . $sessionId . '.cfg', 0600);
                file_put_contents($dataDir . '/session_' . $sessionId . '.in', '');
                file_put_contents($dataDir . '/session_' . $sessionId . '.out', '');
                file_put_contents($dataDir . '/session_' . $sessionId . '.status', 'running');

                // Spawn background terminal worker process
                $workerPath = __DIR__ . '/../worker/terminal_worker.php';
                $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerPath) . ' ' . escapeshellarg($sessionId);

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose(popen('start /B ' . $cmd, 'r'));
                } else {
                    exec($cmd . ' > /dev/null 2>&1 &');
                }

                $response = [
                    'status' => 'success',
                    'session_id' => $sessionId
                ];
            } else {
                $response = ['status' => 'error', 'message' => 'Device not found'];
            }

        } elseif ($bridgeAction === 'poll') {
            $sessionId = $this->getInput('session_id', '');
            
            // Validate session format
            if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
                $response = ['status' => 'error', 'message' => 'Invalid session ID'];
                $this->sendJson($response);
                return;
            }

            $cfgFile = $dataDir . '/session_' . $sessionId . '.cfg';
            $outFile = $dataDir . '/session_' . $sessionId . '.out';
            $inFile = $dataDir . '/session_' . $sessionId . '.in';
            $statusFile = $dataDir . '/session_' . $sessionId . '.status';

            if (file_exists($cfgFile)) {
                // 1. Process client input if provided
                $clientInput = $this->getInput('input', '');
                if ($clientInput !== '') {
                    $decodedInput = base64_decode($clientInput);
                    if ($decodedInput !== false) {
                        file_put_contents($inFile, $decodedInput, FILE_APPEND);
                    }
                }

                // 2. Read new output from file
                $offset = (int)$this->getInput('offset', '0');
                $outputData = '';
                $newOffset = $offset;
                $closed = false;

                // Check process status
                if (file_exists($statusFile)) {
                    $status = trim(file_get_contents($statusFile));
                    if ($status === 'closed') {
                        $closed = true;
                    }
                } else {
                    $closed = true;
                }

                if (file_exists($outFile)) {
                    $fileSize = filesize($outFile);
                    if ($fileSize > $offset) {
                        $fp = fopen($outFile, 'r');
                        if ($fp) {
                            fseek($fp, $offset);
                            $outputData = fread($fp, $fileSize - $offset);
                            fclose($fp);
                            $newOffset = $fileSize;
                        }
                    }
                }

                $response = [
                    'status' => 'success',
                    'output' => base64_encode($outputData),
                    'offset' => $newOffset,
                    'closed' => $closed
                ];
            } else {
                $response = ['status' => 'error', 'message' => 'Session expired or not found', 'closed' => true];
            }

        } elseif ($bridgeAction === 'close') {
            $sessionId = $this->getInput('session_id', '');
            if (preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
                $statusFile = $dataDir . '/session_' . $sessionId . '.status';
                if (file_exists($statusFile)) {
                    file_put_contents($statusFile, 'closed');
                }
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid session ID'];
            }

        } elseif ($bridgeAction === 'resize') {
            $sessionId = $this->getInput('session_id', '');
            if (preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
                $cols = max(20, min(500, (int)$this->getInput('cols', '120')));
                $rows = max(5, min(200, (int)$this->getInput('rows', '30')));
                $winsizeFile = $dataDir . '/session_' . $sessionId . '.winsize';
                file_put_contents($winsizeFile, $cols . 'x' . $rows);
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Invalid session ID'];
            }
        }

        $this->sendJson($response);
    }

    /**
     * Send JSON response and terminate.
     */
    private function sendJson(array $data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
