-- Config Manager v1.3 migration
-- Adds 'netgear' to the config_devices.vendor ENUM so existing installs can
-- add Netgear ProSafe devices without recreating the table.
-- Safe to run multiple times.

ALTER TABLE `config_devices`
    MODIFY COLUMN `vendor` ENUM('cisco_ios','cisco_nxos','fortinet','mikrotik','juniper','netgear')
        NOT NULL DEFAULT 'cisco_ios';

SELECT 'Migration v1.3 complete.' AS status;
