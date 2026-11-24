<?php

// Ensure this script only runs from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

if ($argc < 2) {
    die("Usage: php terminal_worker.php <session_id>\n");
}

$sessionId = $argv[1];
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
    die("Invalid session ID format.\n");
}

$dataDir = __DIR__ . '/../data';
$cfgFile = $dataDir . '/session_' . $sessionId . '.cfg';
$inFile = $dataDir . '/session_' . $sessionId . '.in';
$outFile = $dataDir . '/session_' . $sessionId . '.out';
$statusFile = $dataDir . '/session_' . $sessionId . '.status';

if (!file_exists($cfgFile)) {
    die("Session configuration not found.\n");
}

$cfg = json_decode(file_get_contents($cfgFile), true);
if (!$cfg) {
    die("Invalid session configuration.\n");
}

$host = $cfg['ip'];
$port = (int)$cfg['port'];
$username = $cfg['username'];
$protocol = ($cfg['protocol'] ?? 'ssh') === 'telnet' ? 'telnet' : 'ssh';
$authType = $cfg['auth_type'] ?? 'password';
$password = $cfg['password'] ?? '';
$privateKey = $cfg['private_key'] ?? '';
$keyPassphrase = $cfg['key_passphrase'] ?? '';
$cols = (int)($cfg['cols'] ?? 120);
$rows = (int)($cfg['rows'] ?? 30);
$winsizeFile = $dataDir . '/session_' . $sessionId . '.winsize';
$lastWinsize = $cols . 'x' . $rows;

// For key-based auth, write the key to its own file with the strict
// permissions ssh requires (it refuses to use a key that's group/world
// readable). This file is deleted again once the session ends.
$keyFile = null;
if ($authType === 'key' && !empty($privateKey)) {
    $keyFile = $dataDir . '/session_' . $sessionId . '.pem';
    file_put_contents($keyFile, $privateKey);
    chmod($keyFile, 0600);
}

// Setup descriptorspec for proc_open
$descriptorspec = [
    0 => ["pipe", "r"], // stdin
    1 => ["pipe", "w"], // stdout
    2 => ["pipe", "w"]  // stderr
];

if ($protocol === 'telnet') {
    // Telnet has no auth step of its own — it just opens a raw text
    // connection and the remote device prints its own "login:" /
    // "Password:" prompts, same as if you typed `telnet host port` by
    // hand. So the command itself is trivial; the actual login handshake
    // is done further down by watching the output for those prompts and
    // typing the answers, the same way a human would.
    $cmd = 'telnet ' . escapeshellarg($host) . ' ' . $port;
} else {
    // Determine the SSH command
    // Note: We use -t -t to force PTY allocation so interactive prompts and utilities work.
    // We use StrictHostKeyChecking=no to bypass key confirmation dialogs.
    $cmd = 'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null'
        . ' -o KexAlgorithms=+diffie-hellman-group14-sha1'
        . ' -o HostKeyAlgorithms=+ssh-rsa'
        . ' -o MACs=hmac-sha1,hmac-sha1-96';

    if ($keyFile !== null) {
        $cmd .= ' -i ' . escapeshellarg($keyFile);
    }

    $cmd .= ' -t -t -p ' . $port . ' ' . escapeshellarg($username . '@' . $host);

    // sshpass answers whichever prompt appears — a login password for
    // password-auth, or "Enter passphrase for key" for a protected private key.
    // An unencrypted key with no passphrase needs no prompt-answering at all.
    $sshpassSecret = ($authType === 'key') ? $keyPassphrase : $password;
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && !empty($sshpassSecret)) {
        // Check if sshpass is installed
        $hasSshPass = shell_exec('which sshpass');
        if ($hasSshPass) {
            $cmd = 'sshpass -p ' . escapeshellarg($sshpassSecret) . ' ' . $cmd;
        }
    }
}

// Spawn process
$process = proc_open($cmd, $descriptorspec, $pipes);

if (!is_resource($process)) {
    file_put_contents($statusFile, 'closed');
    $clientName = $protocol === 'telnet' ? 'Telnet' : 'SSH';
    appendOutput($outFile, "Failed to start $clientName process.\nMake sure '" . $protocol . "' is installed on the Zabbix server.\n");
    if ($keyFile !== null) {
        @unlink($keyFile);
    }
    cleanup($sessionId, $dataDir);
    exit(1);
}

// Set pipes to non-blocking
stream_set_blocking($pipes[0], 0);
stream_set_blocking($pipes[1], 0);
stream_set_blocking($pipes[2], 0);

if ($protocol === 'ssh') {
    // Tell the remote shell the real terminal size. Without this, ssh (since its
    // stdin here is a plain pipe, not a real local tty) has no way to detect the
    // browser's actual width and falls back to a narrow default — which is why
    // wide output like `docker ps` was wrapping mid-row and looking garbled.
    // This only helps Linux-style shells (stty is meaningless to network gear
    // like Cisco IOS, which will just show one harmless "invalid input" line).
    usleep(300000); // give the remote shell a moment to be ready for input
    fwrite($pipes[0], "stty cols {$cols} rows {$rows} 2>/dev/null\n");
    fflush($pipes[0]);
}
// Note: for telnet we deliberately send nothing yet — the very first thing
// on the wire is the device's own "login:" prompt, and blindly typing a stty
// command here would land inside that prompt as garbage input. Terminal
// sizing has no equivalent for telnet/network-gear sessions anyway.

$lastActivity = time();
$timeout = 300; // 5 minutes inactivity timeout

// --- Telnet auto-login state ---
// Telnet has no auth step built into the protocol — the remote device just
// prints its own plain-text "login:" / "Password:" prompts, exactly as if
// someone had typed `telnet host port` by hand. So logging in automatically
// means watching the output stream for those prompts and typing the answers
// at the right moment, the same way a human (or an `expect` script) would.
// This only fires once per prompt type per session, and only if a password
// was actually configured — if not, the prompts are just left on screen for
// the person in the browser to answer themselves.
$telnetLoginBuffer = '';
$telnetUsernameSent = false;
$telnetPasswordSent = false;

while (true) {
    // 1. Always drain any pending output FIRST. If we check process
    //    status before this, a fast failure (bad auth, algorithm mismatch,
    //    host refusal) has its real error message discarded before we ever
    //    read it, leaving only a useless generic "connection closed" line.
    $hasOutput = false;
    $stdout = fread($pipes[1], 8192);
    if ($stdout !== false && $stdout !== '') {
        appendOutput($outFile, $stdout);
        $hasOutput = true;

        if ($protocol === 'telnet') {
            handleTelnetAutoLogin(
                $stdout, $pipes[0], $telnetLoginBuffer,
                $telnetUsernameSent, $telnetPasswordSent,
                $username, $password
            );
        }
    }

    $stderr = fread($pipes[2], 8192);
    if ($stderr !== false && $stderr !== '') {
        appendOutput($outFile, $stderr);
        $hasOutput = true;
    }

    if ($hasOutput) {
        $lastActivity = time();
    }

    // 2. Check if the process is still running
    $status = proc_get_status($process);
    if (!$status['running']) {
        // Give any last buffered bytes a moment to arrive, then drain fully.
        usleep(100000);
        $finalStdout = stream_get_contents($pipes[1]);
        if ($finalStdout !== false && $finalStdout !== '') {
            appendOutput($outFile, $finalStdout);
        }
        $finalStderr = stream_get_contents($pipes[2]);
        if ($finalStderr !== false && $finalStderr !== '') {
            appendOutput($outFile, $finalStderr);
        }
        $exitCode = $status['exitcode'];
        appendOutput($outFile, "\n[Connection closed" . ($exitCode > 0 ? " — $protocol exit code $exitCode" : "") . "]\n");
        break;
    }

    // 3. Check if the session was explicitly closed by the frontend
    if (file_exists($statusFile)) {
        $sessionStatus = trim(file_get_contents($statusFile));
        if ($sessionStatus === 'closed') {
            appendOutput($outFile, "\nSession terminated.\n");
            break;
        }
    } else {
        break;
    }

    // 4. Process frontend inputs (session.in)
    if (file_exists($inFile) && filesize($inFile) > 0) {
        $inHandle = fopen($inFile, 'r+');
        if ($inHandle) {
            if (flock($inHandle, LOCK_EX)) {
                $input = stream_get_contents($inHandle);
                if ($input !== '') {
                    ftruncate($inHandle, 0);
                    rewind($inHandle);
                    
                    // Write to SSH stdin
                    fwrite($pipes[0], $input);
                    fflush($pipes[0]);
                    
                    $lastActivity = time();
                }
                flock($inHandle, LOCK_UN);
            }
            fclose($inHandle);
        }
    }

    // 5. Inactivity timeout check
    if (time() - $lastActivity > $timeout) {
        appendOutput($outFile, "\nSession timed out due to inactivity.\n");
        break;
    }

    // Micro-sleep to avoid high CPU usage (10ms)
    usleep(10000);
}

// Close pipes and process
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

file_put_contents($statusFile, 'closed');
if ($keyFile !== null) {
    @unlink($keyFile);
}

// Delay cleanup slightly to let client poll final outputs
sleep(2);
cleanup($sessionId, $dataDir);

/**
 * Append terminal output to session file with lock.
 */
function appendOutput(string $outFile, string $data): void {
    if ($data === '') return;
    $outHandle = fopen($outFile, 'a');
    if ($outHandle) {
        if (flock($outHandle, LOCK_EX)) {
            fwrite($outHandle, $data);
            flock($outHandle, LOCK_UN);
        }
        fclose($outHandle);
    }
}

/**
 * Watch telnet output for a login/password prompt and type the configured
 * credentials in response — this is the closest telnet gets to what sshpass
 * does for SSH. Kept deliberately simple (a couple of case-insensitive
 * regexes against a rolling buffer of recent output) rather than a full
 * expect-style dialect, since prompt wording varies a lot across vendors
 * ("login:", "Username:", "Password:" all show up in the wild).
 *
 * Matching is deliberately tolerant of a few trailing bytes after the
 * prompt text (extra spaces, a stray control byte, etc.) instead of
 * requiring the prompt to be the exact last thing in the buffer — some
 * devices trail the prompt with a byte or two that a human never notices
 * but that would silently break a strict end-of-string match, leaving the
 * prompt undetected and the credentials never typed.
 *
 * If no password was configured for the device, this quietly does nothing —
 * the prompts just stay on screen and the person typing in the browser
 * answers them manually, same as a plain `telnet` session.
 */
function handleTelnetAutoLogin(
    string $newOutput,
    $stdinPipe,
    string &$buffer,
    bool &$usernameSent,
    bool &$passwordSent,
    string $username,
    string $password
): void {
    if ($password === '' && $username === '') {
        return;
    }

    // Keep only a short rolling tail — enough to catch a prompt that arrives
    // split across two reads, without the buffer growing forever.
    $buffer = substr($buffer . $newOutput, -256);

    // Allow up to a handful of trailing bytes after the prompt text (a
    // stray space, control byte, or partial next-line fragment) rather than
    // demanding the prompt be the literal last character in the buffer.
    $trailingSlack = 6;

    // Each credential is only auto-typed if it was actually configured for
    // this device. Previously this only bailed out when BOTH username and
    // password were blank, so a device saved with a username but no (or a
    // failed-to-decrypt) password would still "answer" the Password: prompt
    // with an empty string — silently submitting a blank line and burning a
    // failed login attempt instead of leaving the prompt for a human to type.
    if (!$usernameSent && $username !== '') {
        if (preg_match('/(login|username)\s*:/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            $matchEnd = $m[0][1] + strlen($m[0][0]);
            if (strlen($buffer) - $matchEnd <= $trailingSlack) {
                fwrite($stdinPipe, $username . "\r\n");
                fflush($stdinPipe);
                $usernameSent = true;
                $buffer = '';
                return;
            }
        }
    }

    if ($usernameSent && !$passwordSent && $password !== '') {
        if (preg_match('/password\s*:/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            $matchEnd = $m[0][1] + strlen($m[0][0]);
            if (strlen($buffer) - $matchEnd <= $trailingSlack) {
                fwrite($stdinPipe, $password . "\r\n");
                fflush($stdinPipe);
                $passwordSent = true;
                $buffer = '';
            }
        }
    }
}

/**
 * Clean up all session files.
 */
function cleanup(string $sessionId, string $dataDir): void {
    $files = [
        $dataDir . '/session_' . $sessionId . '.cfg',
        $dataDir . '/session_' . $sessionId . '.in',
        $dataDir . '/session_' . $sessionId . '.out',
        $dataDir . '/session_' . $sessionId . '.status',
        $dataDir . '/session_' . $sessionId . '.pem'
    ];
    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
