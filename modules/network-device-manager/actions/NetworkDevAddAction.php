<?php

namespace Modules\NetworkDeviceManager\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use Modules\NetworkDeviceManager\Classes\DbManager;

class NetworkDevAddAction extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        // Using string type for inputs and parsing manually is version-safe across all Zabbix 6.x/7.x releases
        return $this->validateInput([
            'id' => 'string',
            'ip' => 'string',
            'name' => 'string',
            'username' => 'string',
            'password' => 'string',
            'port' => 'string',
            'form_action' => 'string',
            'auth_type' => 'string',
            'key_passphrase' => 'string',
            'protocol' => 'string'
        ]);
    }

    protected function checkPermissions(): bool {
        // Credential management requires Zabbix Admin or Super Admin permissions
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function doAction(): void {
        $db = new DbManager();
        $id = $this->getInput('id', '');
        $ip = $this->getInput('ip', '');
        
        $errors = [];
        if ($db->storageError) {
            $errors[] = $db->storageError;
        }
        $formAction = $this->getInput('form_action', '');

        if ($db->storageError) {
            // Can't read or write anything reliably — render the form with
            // the error instead of attempting save/delete against broken storage.
            $data = [
                'device' => null,
                'id' => $id,
                'ip_param' => $ip,
                'errors' => $errors
            ];
            $this->setResponse(new CControllerResponseData($data));
            return;
        }

        if ($formAction === 'delete' && !empty($id)) {
            if ($db->deleteDevice((int)$id)) {
                $response = new CControllerResponseRedirect((new \CUrl('zabbix.php'))->setArgument('action', 'networkdev.view'));
                \CMessageHelper::setSuccessTitle('Device configuration removed.');
                $this->setResponse($response);
                return;
            } else {
                $errors[] = 'Failed to delete device configuration.';
            }
        } elseif ($formAction === 'save') {
            $name = $this->getInput('name', '');
            $username = $this->getInput('username', '');
            $password = $this->getInput('password', '');
            $port = (int)$this->getInput('port', '22');
            $protocol = $this->getInput('protocol', 'ssh');
            if (!in_array($protocol, ['ssh', 'telnet'], true)) {
                $protocol = 'ssh';
            }
            $authType = $this->getInput('auth_type', 'password');
            if (!in_array($authType, ['password', 'key'], true)) {
                $authType = 'password';
            }
            // Telnet has no concept of key-based auth — it's just a raw text
            // login prompt, so force password mode regardless of what was posted.
            if ($protocol === 'telnet') {
                $authType = 'password';
            }
            $keyPassphrase = $this->getInput('key_passphrase', '');
            $privateKeyContent = null;

            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[] = 'A valid IP address is required.';
            }
            if (empty($username)) {
                $errors[] = 'Username is required.';
            }
            if ($port <= 0 || $port > 65535) {
                $errors[] = 'Port must be between 1 and 65535.';
            }

            if ($authType === 'key') {
                // validateInput() doesn't cover file uploads, so read $_FILES directly.
                if (isset($_FILES['private_key_file']) && $_FILES['private_key_file']['error'] === UPLOAD_ERR_OK && $_FILES['private_key_file']['size'] > 0) {
                    $uploaded = file_get_contents($_FILES['private_key_file']['tmp_name']);
                    if ($uploaded === false || trim($uploaded) === '') {
                        $errors[] = 'Could not read the uploaded private key file.';
                    } else {
                        $privateKeyContent = $uploaded;
                    }
                } elseif (!empty($id)) {
                    // Editing and no new file chosen — keep the key already on record.
                    $existing = $db->getDevice((int)$id);
                    if ($existing && !empty($existing['private_key'])) {
                        $privateKeyContent = $db->decrypt($existing['private_key']);
                        if ($keyPassphrase === '' && !empty($existing['key_passphrase'])) {
                            $keyPassphrase = $db->decrypt($existing['key_passphrase']);
                        }
                    } else {
                        $errors[] = 'A private key (.pem) file is required.';
                    }
                } else {
                    $errors[] = 'A private key (.pem) file is required.';
                }
                // Password field is irrelevant in key mode — nothing to validate/keep.
                $password = '';
            } else {
                if (empty($password)) {
                    // If editing and password is empty, keep existing password
                    if (!empty($id)) {
                        $existing = $db->getDevice((int)$id);
                        if ($existing && !empty($existing['password'])) {
                            $password = $db->decrypt($existing['password']);
                        }
                        if (empty($password)) {
                            // Either no prior password (e.g. was previously key-auth) or decrypt gave nothing
                            $errors[] = 'Password is required.';
                        }
                    } else {
                        $errors[] = 'Password is required.';
                    }
                }
            }

            if (empty($errors)) {
                if ($db->saveDevice($ip, $name, $username, $password, $port, $authType, $privateKeyContent, $keyPassphrase, $protocol)) {
                    $response = new CControllerResponseRedirect((new \CUrl('zabbix.php'))->setArgument('action', 'networkdev.view'));
                    \CMessageHelper::setSuccessTitle('Device credentials saved successfully.');
                    $this->setResponse($response);
                    return;
                } else {
                    $errors[] = 'Failed to save credentials to database.';
                }
            }
        }

        // Render form
        $device = null;
        if (!empty($id)) {
            $device = $db->getDevice((int)$id);
            if ($device) {
                // Never send decrypted secrets back to the browser — just tell
                // the view a key is already on file so it can show a note.
                $device['has_saved_key'] = !empty($device['private_key']);
                unset($device['private_key'], $device['key_passphrase'], $device['password']);
            }
        } elseif (!empty($ip)) {
            // Pre-fill IP if passed from discovery list
            $device = [
                'ip' => $ip,
                'name' => '',
                'username' => '',
                'port' => 22,
                'auth_type' => 'password',
                'protocol' => 'ssh',
                'has_saved_key' => false
            ];
        }

        $data = [
            'device' => $device,
            'id' => $id,
            'ip_param' => $ip,
            'errors' => $errors
        ];

        $this->setResponse(new CControllerResponseData($data));
    }
}
