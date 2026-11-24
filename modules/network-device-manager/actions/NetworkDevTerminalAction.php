<?php

namespace Modules\NetworkDeviceManager\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use Modules\NetworkDeviceManager\Classes\DbManager;

class NetworkDevTerminalAction extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'id' => 'required|string'
        ]);
    }

    protected function checkPermissions(): bool {
        // NOC Operators and above can open terminals to troubleshoot
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $db = new DbManager();
        $id = (int)$this->getInput('id');
        $device = $db->getDevice($id);

        if (!$device) {
            $response = new CControllerResponseRedirect((new \CUrl('zabbix.php'))->setArgument('action', 'networkdev.view'));
            \CMessageHelper::setErrorTitle('Device not configured or found.');
            $this->setResponse($response);
            return;
        }

        $data = [
            'device' => $device
        ];

        $this->setResponse(new CControllerResponseData($data));
    }
}
