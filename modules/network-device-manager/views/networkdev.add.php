<?php
/**
 * View for adding/editing device credentials
 * 
 * @var array $data
 */
$isEdit = !empty($data['id']);
$device = $data['device'];

// Resolve the module's public asset path regardless of the folder name it's installed under
$ndm_module_dir = 'modules/' . basename(dirname(__DIR__));
$ndm_css_ver = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($ndm_module_dir) ?>/assets/css/style.css?v=<?= $ndm_css_ver ?>" />

<div class="ndm-container ndm-form-container">
    <div class="ndm-header">
        <div class="ndm-header-left">
            <div class="ndm-header-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="#fff" stroke-width="1.6"/>
                    <path d="M19.4 13.5a1.6 1.6 0 0 0 .32 1.77l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-1 1.46V19.4a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1.05-1.46 1.6 1.6 0 0 0-1.77.32l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.46-1H4.6a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.46-1.05 1.6 1.6 0 0 0-.32-1.77l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.6 1.6 0 0 0 1.77.32H9.5a1.6 1.6 0 0 0 1-1.46V4.6a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.46 1.6 1.6 0 0 0 1.77-.32l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.6 1.6 0 0 0-.32 1.77V9.5a1.6 1.6 0 0 0 1.46 1h.1a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.46 1Z" stroke="#fff" stroke-width="1.4"/>
                </svg>
            </div>
            <div class="ndm-title-area">
                <span class="ndm-eyebrow">Network Device Manager</span>
                <h1><?= $isEdit ? 'Edit Device Credentials' : 'Configure Device Credentials' ?></h1>
                <p>Define connection options for the network device to allow console access.</p>
            </div>
        </div>
        <div class="ndm-actions-area">
            <a href="zabbix.php?action=networkdev.view" class="ndm-btn ndm-btn-secondary">
                ← Back to List
            </a>
        </div>
    </div>

    <?php if (!empty($data['errors'])): ?>
        <div class="ndm-alert ndm-alert-error">
            <strong>Validation Error:</strong>
            <ul>
                <?php foreach ($data['errors'] as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="ndm-card">
        <form action="zabbix.php?action=networkdev.add" method="post" class="ndm-form" enctype="multipart/form-data">
            <?php
                $currentAuthType = $device['auth_type'] ?? 'password';
                $hasSavedKey = $device['has_saved_key'] ?? false;
            ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($data['id']) ?>">
            <?php endif; ?>
            
            <input type="hidden" name="form_action" value="save">

            <div class="ndm-form-group">
                <label for="ip">Device IP Address <span class="ndm-required">*</span></label>
                <input type="text" id="ip" name="ip" class="ndm-input ndm-font-mono" 
                       placeholder="e.g., 192.168.1.50" 
                       value="<?= htmlspecialchars($device ? $device['ip'] : '') ?>"
                       <?= ($isEdit || !empty($data['ip_param'])) ? 'readonly' : '' ?>>
                <small class="ndm-form-tip">Must be a valid IP address. IPv4 or IPv6.</small>
            </div>

            <div class="ndm-form-group">
                <label for="name">Friendly Name (Optional)</label>
                <input type="text" id="name" name="name" class="ndm-input" 
                       placeholder="e.g., Core Switch, Edge Router" 
                       value="<?= htmlspecialchars($device ? $device['name'] : '') ?>">
            </div>

            <div class="ndm-form-group">
                <label>Connection Protocol <span class="ndm-required">*</span></label>
                <?php $currentProtocol = $device['protocol'] ?? 'ssh'; ?>
                <div class="ndm-auth-toggle" id="ndm-protocol-toggle">
                    <label class="ndm-radio-pill">
                        <input type="radio" name="protocol" value="ssh" <?= $currentProtocol === 'ssh' ? 'checked' : '' ?>>
                        <span>SSH</span>
                    </label>
                    <label class="ndm-radio-pill">
                        <input type="radio" name="protocol" value="telnet" <?= $currentProtocol === 'telnet' ? 'checked' : '' ?>>
                        <span>Telnet</span>
                    </label>
                </div>
                <small class="ndm-form-tip" id="ndm-telnet-warning" style="<?= $currentProtocol === 'telnet' ? '' : 'display:none;' ?> color:#b45309;">
                    Telnet sends credentials and traffic in plain text — only use it on trusted management networks, and only for devices that don't support SSH.
                </small>
            </div>

            <div class="ndm-form-group">
                <label for="username">Username <span class="ndm-required">*</span></label>
                <input type="text" id="username" name="username" class="ndm-input ndm-font-mono" 
                       placeholder="e.g., admin, cisco, root" 
                       value="<?= htmlspecialchars($device ? $device['username'] : '') ?>">
            </div>

            <div class="ndm-form-group" id="ndm-auth-method-group">
                <label>Authentication Method <span class="ndm-required">*</span></label>
                <div class="ndm-auth-toggle">
                    <label class="ndm-radio-pill">
                        <input type="radio" name="auth_type" value="password" <?= $currentAuthType === 'password' ? 'checked' : '' ?>>
                        <span>Password</span>
                    </label>
                    <label class="ndm-radio-pill" id="ndm-auth-key-pill">
                        <input type="radio" name="auth_type" value="key" <?= $currentAuthType === 'key' ? 'checked' : '' ?>>
                        <span>Private Key (.pem)</span>
                    </label>
                </div>
                <small class="ndm-form-tip" id="ndm-telnet-key-note" style="display:none;">
                    Telnet is plain text terminal access — it has no concept of key-based auth, so only a password login is available.
                </small>
            </div>

            <div class="ndm-form-row" id="ndm-auth-password" style="<?= $currentAuthType === 'key' ? 'display:none;' : '' ?>">
                <div class="ndm-form-group ndm-col-12">
                    <label for="password">Password / Secret <span class="ndm-required">*</span></label>
                    <input type="password" id="password" name="password" class="ndm-input" 
                           placeholder="<?= $isEdit ? '•••••••• (leave blank to keep current)' : 'Enter password' ?>">
                </div>
            </div>

            <div class="ndm-form-row" id="ndm-auth-key" style="<?= $currentAuthType === 'key' ? '' : 'display:none;' ?>">
                <div class="ndm-form-group ndm-col-12">
                    <label for="private_key_file">Private Key File (.pem) <?= $hasSavedKey ? '' : '<span class="ndm-required">*</span>' ?></label>
                    <input type="file" id="private_key_file" name="private_key_file" class="ndm-input" accept=".pem,.key,.txt">
                    <small class="ndm-form-tip">
                        <?= $hasSavedKey
                            ? 'A private key is already saved for this device. Choose a file only if you want to replace it.'
                            : 'Upload the .pem private key file for this VM/device.' ?>
                    </small>
                </div>
            </div>

            <div class="ndm-form-group" id="ndm-auth-key-passphrase" style="<?= $currentAuthType === 'key' ? '' : 'display:none;' ?>">
                <label for="key_passphrase">Key Passphrase (Optional)</label>
                <input type="password" id="key_passphrase" name="key_passphrase" class="ndm-input" 
                       placeholder="Leave blank if the key has no passphrase">
                <small class="ndm-form-tip">Only needed if the private key file itself is passphrase-protected.</small>
            </div>

            <div class="ndm-form-group">
                <label for="port">Port <span class="ndm-required">*</span></label>
                <input type="number" id="port" name="port" class="ndm-input ndm-font-mono" style="max-width: 140px;"
                       placeholder="22" 
                       value="<?= htmlspecialchars($device ? $device['port'] : '22') ?>">
            </div>

            <script>
                (function() {
                    var authRadios = document.querySelectorAll('input[name="auth_type"]');
                    var protoRadios = document.querySelectorAll('input[name="protocol"]');
                    var pwdBlock = document.getElementById('ndm-auth-password');
                    var keyBlock = document.getElementById('ndm-auth-key');
                    var keyPassBlock = document.getElementById('ndm-auth-key-passphrase');
                    var keyPill = document.getElementById('ndm-auth-key-pill');
                    var telnetWarning = document.getElementById('ndm-telnet-warning');
                    var telnetKeyNote = document.getElementById('ndm-telnet-key-note');
                    var portInput = document.getElementById('port');

                    function currentProtocol() {
                        var checked = document.querySelector('input[name="protocol"]:checked');
                        return checked ? checked.value : 'ssh';
                    }

                    function syncAuth() {
                        var isTelnet = currentProtocol() === 'telnet';
                        var isKey = !isTelnet && document.querySelector('input[name="auth_type"]:checked').value === 'key';

                        pwdBlock.style.display = isKey ? 'none' : '';
                        keyBlock.style.display = isKey ? '' : 'none';
                        keyPassBlock.style.display = isKey ? '' : 'none';

                        // Telnet has no key-based auth — hide that option entirely
                        // and force the password radio to be the selected one.
                        keyPill.style.display = isTelnet ? 'none' : '';
                        telnetKeyNote.style.display = isTelnet ? '' : 'none';
                        telnetWarning.style.display = isTelnet ? '' : 'none';
                        if (isTelnet) {
                            document.querySelector('input[name="auth_type"][value="password"]').checked = true;
                        }
                    }

                    function syncPortDefault(previousProtocol) {
                        // Only auto-switch the port if it still holds the *other*
                        // protocol's default — never overwrite a value the user
                        // deliberately typed in.
                        var isTelnet = currentProtocol() === 'telnet';
                        if (isTelnet && portInput.value === '22') {
                            portInput.value = '23';
                        } else if (!isTelnet && portInput.value === '23') {
                            portInput.value = '22';
                        }
                    }

                    authRadios.forEach(function(r) { r.addEventListener('change', syncAuth); });
                    protoRadios.forEach(function(r) {
                        r.addEventListener('change', function() {
                            syncPortDefault();
                            syncAuth();
                        });
                    });
                    syncAuth();
                })();
            </script>

            <div class="ndm-form-actions">
                <button type="submit" class="ndm-btn ndm-btn-primary">Save Configuration</button>
                
                <?php if ($isEdit): ?>
                    <button type="button" class="ndm-btn ndm-btn-danger" 
                            onclick="if(confirm('Are you sure you want to delete these credentials?')) { 
                                document.getElementById('delete-form').submit(); 
                            }">
                        Delete Credentials
                    </button>
                <?php endif; ?>
                
                <a href="zabbix.php?action=networkdev.view" class="ndm-btn ndm-btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- Hidden delete form -->
<?php if ($isEdit): ?>
    <form id="delete-form" action="zabbix.php?action=networkdev.add" method="post" style="display: none;">
        <input type="hidden" name="id" value="<?= htmlspecialchars($data['id']) ?>">
        <input type="hidden" name="form_action" value="delete">
    </form>
<?php endif; ?>
