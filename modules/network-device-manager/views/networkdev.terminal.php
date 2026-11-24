<?php
/**
 * View for interactive web SSH terminal
 * 
 * @var array $data
 */
$device = $data['device'];
$displayName = $device['name'] ? $device['name'] : $device['ip'];
$protocol = $device['protocol'] ?? 'ssh';
$protocolLabel = $protocol === 'telnet' ? 'Telnet' : 'SSH';

// Resolve the module's public asset path regardless of the folder name it's installed under
$ndm_module_dir = 'modules/' . basename(dirname(__DIR__));
$ndm_css_ver = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
$ndm_js_ver = file_exists(__DIR__ . '/../assets/js/terminal.js') ? filemtime(__DIR__ . '/../assets/js/terminal.js') : time();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($ndm_module_dir) ?>/assets/css/style.css?v=<?= $ndm_css_ver ?>" />

<!-- Load xterm.css and xterm.js from jsdelivr CDN for zero-install experience -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css" />
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>

<div class="ndm-container ndm-terminal-page">
    <div class="ndm-terminal-header">
        <div class="ndm-terminal-info">
            <span class="ndm-terminal-icon">>_</span>
            <span class="ndm-terminal-title"><?= htmlspecialchars($protocolLabel) ?>: <?= htmlspecialchars($displayName) ?></span>
            <span class="ndm-terminal-host">(<?= htmlspecialchars($device['ip']) ?>:<?= $device['port'] ?>)</span>
            <span id="connection-status" class="ndm-connection-status ndm-status-connecting">Connecting...</span>
        </div>
        <div class="ndm-terminal-actions">
            <button id="btn-reconnect" class="ndm-btn ndm-btn-terminal-ghost" style="display: none;">⟳ Reconnect</button>
            <a href="zabbix.php?action=networkdev.view" class="ndm-btn ndm-btn-danger">Close Session</a>
        </div>
    </div>

    <!-- The actual terminal container -->
    <div class="ndm-terminal-wrapper">
        <div id="terminal" data-device-id="<?= $device['id'] ?>" data-protocol="<?= htmlspecialchars($protocol) ?>"></div>
    </div>
    
    <div class="ndm-terminal-footer">
        <?php if ($protocol === 'telnet'): ?>
            <span>⚠ Telnet sessions are <strong>not encrypted</strong>. Press <kbd>Ctrl</kbd> + <kbd>C</kbd> or type <kbd>exit</kbd> to quit.</span>
        <?php else: ?>
            <span>Terminal sessions are encrypted. Press <kbd>Ctrl</kbd> + <kbd>C</kbd> or type <kbd>exit</kbd> to quit.</span>
        <?php endif; ?>
        <span class="ndm-terminal-powered">Powered by xterm.js</span>
    </div>
</div>

<script src="<?= htmlspecialchars($ndm_module_dir) ?>/assets/js/terminal.js?v=<?= $ndm_js_ver ?>"></script>
