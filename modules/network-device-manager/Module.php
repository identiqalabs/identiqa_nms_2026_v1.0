<?php

namespace Modules\NetworkDeviceManager;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->add((new CMenuItem(_('Network Devices')))
                ->setAction('networkdev.view')
            );
    }
}
