document.addEventListener('DOMContentLoaded', () => {
    const termContainer = document.getElementById('terminal');
    if (!termContainer) return; // Not on the terminal page

    const deviceId = termContainer.getAttribute('data-device-id');
    const protocolLabel = (termContainer.getAttribute('data-protocol') === 'telnet') ? 'Telnet' : 'SSH';
    const statusEl = document.getElementById('connection-status');
    const btnReconnect = document.getElementById('btn-reconnect');

    let sessionId = null;
    let pollInterval = null;
    let currentOffset = 0;
    let inputQueue = [];
    let isClosed = false;
    let pollInFlight = false;
    let inputDebounceTimer = null;

    // 1. Initialize Xterm.js
    const term = new Terminal({
        cursorBlink: true,
        cursorStyle: 'block',
        theme: {
            background: '#000000',
            foreground: '#00ff66', // Glowing green text
            cursor: '#00ff66',
            selectionBackground: 'rgba(255, 255, 255, 0.3)'
        },
        fontFamily: "'Fira Code', 'Courier New', monospace",
        fontSize: 14,
        letterSpacing: 0.5,
        lineHeight: 1.2
    });

    const fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(termContainer);
    fitAddon.fit();

    // Focus on terminal
    term.focus();

    // Listen to resize
    window.addEventListener('resize', () => {
        fitAddon.fit();
    });

    // 2. Capture terminal input
    term.onData(data => {
        if (isClosed) return;
        // Push typed characters to input queue
        inputQueue.push(data);

        // Send right away instead of waiting for the next scheduled poll tick —
        // this is what made typing feel laggy. A tiny debounce still batches
        // fast bursts (e.g. pasted text) into one request instead of one per character.
        if (inputDebounceTimer) clearTimeout(inputDebounceTimer);
        inputDebounceTimer = setTimeout(() => runPollCycle(), 15);
    });

    // Helper: Decode Base64 to Uint8Array for binary-safe output writing
    function base64ToBytes(base64) {
        const binaryString = atob(base64);
        const len = binaryString.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes;
    }

    // Helper: Encode string to Base64 (supporting special key chars)
    function bytesToBase64(str) {
        return btoa(unescape(encodeURIComponent(str)));
    }

    // 3. Connect to the backend SSH session
    function startSession() {
        isClosed = false;
        currentOffset = 0;
        inputQueue = [];
        sessionId = null;
        
        term.clear();
        term.write('Connecting to device over ' + protocolLabel + '...\r\n');

        statusEl.className = 'ndm-connection-status ndm-status-connecting';
        statusEl.textContent = 'Connecting...';
        btnReconnect.style.display = 'none';

        // Initialize session via AJAX
        fetch('zabbix.php?action=networkdev.terminal.bridge', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                'bridge_action': 'init',
                'device_id': deviceId,
                'cols': term.cols,
                'rows': term.rows
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                sessionId = data.session_id;
                statusEl.className = 'ndm-connection-status ndm-status-connected';
                statusEl.textContent = 'Connected';
                term.clear();

                // Start polling loop
                startPolling();
            } else {
                term.write('\r\n\x1b[31mError: ' + (data.message || 'Failed to initialize terminal') + '\x1b[0m\r\n');
                statusEl.className = 'ndm-connection-status ndm-status-disconnected';
                statusEl.textContent = 'Connection Failed';
                btnReconnect.style.display = 'inline-block';
            }
        })
        .catch(err => {
            term.write('\r\n\x1b[31mError connecting to Zabbix proxy backend.\x1b[0m\r\n');
            statusEl.className = 'ndm-connection-status ndm-status-disconnected';
            statusEl.textContent = 'Network Error';
            btnReconnect.style.display = 'inline-block';
        });
    }

    // 4. Polling Loop (Keystrokes -> Backend, Outputs -> Frontend)
    function runPollCycle() {
        if (!sessionId || isClosed) return;
        if (pollInFlight) return; // avoid overlapping requests piling up
        pollInFlight = true;

        // Prepare client inputs
        const pendingInput = inputQueue.join('');
        inputQueue = []; // Clear queue

        const params = new URLSearchParams({
            'bridge_action': 'poll',
            'session_id': sessionId,
            'offset': currentOffset.toString()
        });

        if (pendingInput !== '') {
            // Base64 encode the keys typed to avoid URL character parsing bugs
            params.append('input', bytesToBase64(pendingInput));
        }

        fetch('zabbix.php?action=networkdev.terminal.bridge', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: params
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Update offset
                currentOffset = data.offset;

                // Output data to terminal if any
                if (data.output !== '') {
                    const bytes = base64ToBytes(data.output);
                    term.write(bytes);
                }

                // Check if SSH worker session has ended
                if (data.closed) {
                    closeLocalSession('Session closed by remote server.');
                }
            } else {
                closeLocalSession('Session expired or error: ' + (data.message || 'unknown error'));
            }
        })
        .catch(err => {
            console.error('Polling error:', err);
            // We don't immediately disconnect on a single network timeout to allow auto-recovery
        })
        .finally(() => {
            pollInFlight = false;
        });
    }

    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        // This interval is now just a heartbeat for output that arrives without
        // the user typing anything (e.g. a long-running command). Actual
        // keystrokes are sent immediately via runPollCycle() from onData above.
        pollInterval = setInterval(runPollCycle, 200);
    }

    // 5. Tear down and close
    function closeLocalSession(reason) {
        isClosed = true;
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        if (inputDebounceTimer) {
            clearTimeout(inputDebounceTimer);
            inputDebounceTimer = null;
        }

        term.write('\r\n\r\n\x1b[33m[' + reason + ']\x1b[0m\r\n');
        statusEl.className = 'ndm-connection-status ndm-status-disconnected';
        statusEl.textContent = 'Disconnected';
        btnReconnect.style.display = 'inline-block';
    }

    // Reconnect click
    btnReconnect.addEventListener('click', startSession);

    // Initial session start
    startSession();

    // Clean up on page unload (navigating away)
    window.addEventListener('beforeunload', () => {
        if (sessionId) {
            navigator.sendBeacon('zabbix.php', new URLSearchParams({
                'action': 'networkdev.terminal.bridge',
                'bridge_action': 'close',
                'session_id': sessionId
            }));
        }
    });
});
