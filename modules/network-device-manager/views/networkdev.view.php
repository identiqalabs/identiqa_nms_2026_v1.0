<?php
/**
 * View for listing network devices (discovered and manual)
 * 
 * @var array $data
 */

// Resolve the module's public asset path regardless of the folder name it's installed under
$ndm_module_dir = 'modules/' . basename(dirname(__DIR__));
$ndm_css_ver = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($ndm_module_dir) ?>/assets/css/style.css?v=<?= $ndm_css_ver ?>" />

<div class="ndm-container">
    <div class="ndm-header">
        <div class="ndm-header-left">
            <div class="ndm-header-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2" y="3" width="20" height="6" rx="1.5" stroke="#fff" stroke-width="1.6"/>
                    <rect x="2" y="15" width="20" height="6" rx="1.5" stroke="#fff" stroke-width="1.6"/>
                    <circle cx="6" cy="6" r="1" fill="#fff"/>
                    <circle cx="6" cy="18" r="1" fill="#fff"/>
                    <path d="M8 9v3a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-3" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="ndm-title-area">
                <span class="ndm-eyebrow">Network Device Manager</span>
                <h1>Devices</h1>
                <p>Access and configure terminal sessions for discovered and manually managed network devices.</p>
            </div>
        </div>
        <div class="ndm-actions-area">
            <a href="zabbix.php?action=networkdev.add" class="ndm-btn ndm-btn-primary">
                <span class="ndm-icon">+</span> Add Credentials
            </a>
        </div>
    </div>

    <!-- Stats summary -->
    <div class="ndm-stats">
        <div class="ndm-stat-card">
            <div class="ndm-stat-val"><?= count($data['devices']) ?></div>
            <div class="ndm-stat-lbl">Total Devices</div>
        </div>
        <div class="ndm-stat-card">
            <div class="ndm-stat-val"><?= count(array_filter($data['devices'], function($d) { return $d['configured']; })) ?></div>
            <div class="ndm-stat-lbl">Configured SSH</div>
        </div>
        <div class="ndm-stat-card">
            <div class="ndm-stat-val"><?= count(array_filter($data['devices'], function($d) { return $d['status'] === 'Up'; })) ?></div>
            <div class="ndm-stat-lbl">Online (Discovered)</div>
        </div>
    </div>

    <!-- Main table -->
    <div class="ndm-table-wrapper">
        <table class="ndm-table">
            <thead>
                <tr>
                    <th>Device IP</th>
                    <th>Device Name</th>
                    <th>Source</th>
                    <th>Discovery Rule</th>
                    <th>Username</th>
                    <th>Last Seen</th>
                    <th>Status</th>
                    <th class="ndm-txt-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['devices'])): ?>
                    <tr>
                        <td colspan="8" class="ndm-empty-row">No network devices discovered or configured yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['devices'] as $device): ?>
                        <tr class="<?= $device['configured'] ? 'ndm-row-configured' : 'ndm-row-unconfigured' ?>">
                            <td class="ndm-font-mono"><?= htmlspecialchars($device['ip']) ?></td>
                            <td>
                                <?php if ($device['name']): ?>
                                    <strong><?= htmlspecialchars($device['name']) ?></strong>
                                <?php else: ?>
                                    <span class="ndm-placeholder">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ndm-badge ndm-badge-<?= strtolower($device['source']) ?>">
                                    <?= htmlspecialchars($device['source']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($device['rule']) ?></td>
                            <td>
                                <?php if ($device['username']): ?>
                                    <span class="ndm-font-mono"><?= htmlspecialchars($device['username']) ?></span>
                                    <?php if (($device['protocol'] ?? 'ssh') === 'telnet'): ?>
                                        <span class="ndm-auth-badge ndm-auth-badge-password" title="Connects over Telnet (unencrypted)">☎ Telnet</span>
                                    <?php else: ?>
                                        <span class="ndm-auth-badge ndm-auth-badge-password" title="Connects over SSH">🔐 SSH</span>
                                    <?php endif; ?>
                                    <?php if (($device['auth_type'] ?? '') === 'key'): ?>
                                        <span class="ndm-auth-badge ndm-auth-badge-key" title="Authenticates with a private key (.pem)">🔑 Key</span>
                                    <?php else: ?>
                                        <span class="ndm-auth-badge ndm-auth-badge-password" title="Authenticates with a password">🔒 Password</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="ndm-placeholder">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($device['last_seen']) ?></td>
                            <td>
                                <span class="ndm-status-indicator ndm-status-<?= strtolower($device['status']) ?>">
                                    <?= htmlspecialchars($device['status']) ?>
                                </span>
                            </td>
                            <td class="ndm-txt-center ndm-actions-cell">
                                <?php if ($device['configured']): ?>
                                    <a href="zabbix.php?action=networkdev.terminal&id=<?= $device['device_id'] ?>" 
                                       class="ndm-btn ndm-btn-terminal" title="Open Interactive Terminal">
                                        <span class="ndm-icon">>_</span> Terminal
                                    </a>
                                    <a href="zabbix.php?action=networkdev.add&id=<?= $device['device_id'] ?>" 
                                       class="ndm-btn ndm-btn-edit" title="Edit Credentials">
                                        Edit
                                    </a>
                                <?php else: ?>
                                    <a href="zabbix.php?action=networkdev.add&ip=<?= urlencode($device['ip']) ?>" 
                                       class="ndm-btn ndm-btn-configure" title="Configure Credentials">
                                        Set Credentials
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
