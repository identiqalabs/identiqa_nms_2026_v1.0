<?php

namespace Modules\NetworkDeviceManager\Classes;

class DbManager {
    private $dbPath;
    private $jsonPath;
    private $keyPath;
    private $useSqlite = false;

    public $storageError = null;

    public function __construct() {
        $dataDir = __DIR__ . '/../data';

        try {
            if (!file_exists($dataDir)) {
                if (!@mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
                    throw new \RuntimeException("Cannot create data directory: $dataDir");
                }
            }
            if (!is_writable($dataDir)) {
                throw new \RuntimeException("Data directory is not writable: $dataDir");
            }

            $this->dbPath = $dataDir . '/devices.db';
            $this->jsonPath = $dataDir . '/devices.json';
            $this->keyPath = $dataDir . '/secret.key';

            // Check if SQLite3 is available
            if (class_exists('SQLite3')) {
                $this->initSqlite();
                $this->useSqlite = true;
            } else {
                $this->initJson();
            }
        } catch (\Throwable $e) {
            // Never let a storage problem crash the whole page with a 500.
            // Fall back to a degraded (read-only/in-memory) mode and surface
            // a clear message to the controller instead.
            $this->useSqlite = false;
            $this->dbPath = $dataDir . '/devices.db';
            $this->jsonPath = $dataDir . '/devices.json';
            $this->keyPath = $dataDir . '/secret.key';
            $this->storageError = 'Cannot write to module data directory (' . $dataDir . '). '
                . 'Check that the web server user can write to this folder. Original error: ' . $e->getMessage();
        }
    }

    /**
     * Get the encryption key, creating one if it doesn't exist.
     */
    private function getEncryptionKey(): string {
        if (!file_exists($this->keyPath)) {
            $key = bin2hex(random_bytes(32));
            file_put_contents($this->keyPath, $key);
            chmod($this->keyPath, 0600);
            return $key;
        }
        return trim(file_get_contents($this->keyPath));
    }

    /**
     * Encrypt a string using AES-256-CBC.
     */
    public function encrypt(string $value): string {
        $key = hex2bin($this->getEncryptionKey());
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string.
     */
    public function decrypt(string $value): string {
        $key = hex2bin($this->getEncryptionKey());
        $data = base64_decode($value);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Initialize SQLite Database.
     */
    private function initSqlite(): void {
        $db = new \SQLite3($this->dbPath);
        $query = "CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT UNIQUE,
            name TEXT,
            username TEXT,
            password TEXT,
            port INTEGER DEFAULT 22,
            auth_type TEXT DEFAULT 'password',
            private_key TEXT,
            key_passphrase TEXT,
            protocol TEXT DEFAULT 'ssh',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($query);

        // Migration for installs created before key-based auth (and, later,
        // telnet support) existed — SQLite has no "ADD COLUMN IF NOT EXISTS",
        // so just try and ignore the error if the column is already there.
        foreach (['auth_type' => "TEXT DEFAULT 'password'", 'private_key' => 'TEXT', 'key_passphrase' => 'TEXT', 'protocol' => "TEXT DEFAULT 'ssh'"] as $col => $type) {
            try {
                $db->exec("ALTER TABLE devices ADD COLUMN $col $type");
            } catch (\Throwable $e) {
                // Column already exists — fine.
            }
        }

        $db->close();
    }

    /**
     * Initialize JSON Database.
     */
    private function initJson(): void {
        if (!file_exists($this->jsonPath)) {
            file_put_contents($this->jsonPath, json_encode([]));
            chmod($this->jsonPath, 0666);
        }
    }

    /**
     * Read-modify-write the devices JSON file under one continuous exclusive
     * lock, so no other request can read a stale copy in between our read
     * and our write. $mutator receives the current device list and must
     * return the new device list to persist.
     */
    private function withLockedDevices(callable $mutator): bool {
        $handle = fopen($this->jsonPath, 'c+');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        $content = stream_get_contents($handle);
        $devices = $content !== '' ? (json_decode($content, true) ?: []) : [];

        $newDevices = $mutator($devices);

        ftruncate($handle, 0);
        rewind($handle);
        $success = fwrite($handle, json_encode($newDevices, JSON_PRETTY_PRINT)) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $success;
    }

    /**
     * Read the JSON devices file with a shared lock (safe against concurrent writes).
     */
    private function readJsonLocked(): array {
        $handle = fopen($this->jsonPath, 'c+');
        if (!$handle) {
            return [];
        }
        flock($handle, LOCK_SH);
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $content !== '' ? (json_decode($content, true) ?: []) : [];
    }

    /**
     * Get all devices.
     */
    public function getDevices(): array {
        if ($this->useSqlite) {
            $db = new \SQLite3($this->dbPath);
            $results = $db->query("SELECT * FROM devices ORDER BY id DESC");
            $devices = [];
            while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
                $devices[] = $row;
            }
            $db->close();
            return $devices;
        } else {
            return $this->readJsonLocked();
        }
    }

    /**
     * Get device by ID.
     */
    public function getDevice(int $id): ?array {
        if ($this->useSqlite) {
            $db = new \SQLite3($this->dbPath);
            $stmt = $db->prepare("SELECT * FROM devices WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();
            return $row ?: null;
        } else {
            $devices = $this->getDevices();
            foreach ($devices as $device) {
                if ((int)$device['id'] === $id) {
                    return $device;
                }
            }
            return null;
        }
    }

    /**
     * Get device by IP.
     */
    public function getDeviceByIp(string $ip): ?array {
        if ($this->useSqlite) {
            $db = new \SQLite3($this->dbPath);
            $stmt = $db->prepare("SELECT * FROM devices WHERE ip = :ip");
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();
            return $row ?: null;
        } else {
            $devices = $this->getDevices();
            foreach ($devices as $device) {
                if ($device['ip'] === $ip) {
                    return $device;
                }
            }
            return null;
        }
    }

    /**
     * Save/Update a device.
     *
     * @param string $authType 'password' or 'key'
     * @param string|null $privateKey Raw PEM contents (only used when $authType === 'key')
     * @param string|null $keyPassphrase Optional passphrase protecting the private key
     * @param string $protocol 'ssh' or 'telnet'
     */
    public function saveDevice(
        string $ip,
        string $name,
        string $username,
        string $password,
        int $port = 22,
        string $authType = 'password',
        ?string $privateKey = null,
        ?string $keyPassphrase = null,
        string $protocol = 'ssh'
    ): bool {
        $encryptedPassword = $this->encrypt($password);
        $encryptedKey = ($privateKey !== null && $privateKey !== '') ? $this->encrypt($privateKey) : null;
        $encryptedPassphrase = ($keyPassphrase !== null && $keyPassphrase !== '') ? $this->encrypt($keyPassphrase) : null;

        if ($this->useSqlite) {
            $db = new \SQLite3($this->dbPath);
            
            // Check if device already exists
            $existing = $this->getDeviceByIp($ip);
            if ($existing) {
                $stmt = $db->prepare("UPDATE devices SET name = :name, username = :username, password = :password, port = :port, auth_type = :auth_type, private_key = :private_key, key_passphrase = :key_passphrase, protocol = :protocol WHERE ip = :ip");
            } else {
                $stmt = $db->prepare("INSERT INTO devices (ip, name, username, password, port, auth_type, private_key, key_passphrase, protocol) VALUES (:ip, :name, :username, :password, :port, :auth_type, :private_key, :key_passphrase, :protocol)");
            }

            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':password', $encryptedPassword, SQLITE3_TEXT);
            $stmt->bindValue(':port', $port, SQLITE3_INTEGER);
            $stmt->bindValue(':auth_type', $authType, SQLITE3_TEXT);
            $stmt->bindValue(':private_key', $encryptedKey, $encryptedKey === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':key_passphrase', $encryptedPassphrase, $encryptedPassphrase === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':protocol', $protocol, SQLITE3_TEXT);
            
            $success = $stmt->execute() !== false;
            $db->close();
            return $success;
        } else {
            return $this->withLockedDevices(function(array $devices) use ($ip, $name, $username, $encryptedPassword, $port, $authType, $encryptedKey, $encryptedPassphrase, $protocol) {
                $updated = false;
                foreach ($devices as &$device) {
                    if ($device['ip'] === $ip) {
                        $device['name'] = $name;
                        $device['username'] = $username;
                        $device['password'] = $encryptedPassword;
                        $device['port'] = $port;
                        $device['auth_type'] = $authType;
                        $device['private_key'] = $encryptedKey;
                        $device['key_passphrase'] = $encryptedPassphrase;
                        $device['protocol'] = $protocol;
                        $updated = true;
                        break;
                    }
                }
                unset($device);

                if (!$updated) {
                    // Collision-proof ID: max(existing)+1 is fragile — if the file
                    // is ever hand-edited, corrupted, or briefly inconsistent, two
                    // saves can compute the same "next" ID and one silently
                    // overwrites the other. A microtime-based ID practically
                    // never collides regardless of the file's current state.
                    $existingIds = array_column($devices, 'id');
                    do {
                        $newId = (int)(microtime(true) * 1000) % 2147483647;
                    } while (in_array($newId, $existingIds, true));

                    $devices[] = [
                        'id' => $newId,
                        'ip' => $ip,
                        'name' => $name,
                        'username' => $username,
                        'password' => $encryptedPassword,
                        'port' => $port,
                        'auth_type' => $authType,
                        'private_key' => $encryptedKey,
                        'key_passphrase' => $encryptedPassphrase,
                        'protocol' => $protocol,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                }

                return $devices;
            });
        }
    }

    /**
     * Delete a device.
     */
    public function deleteDevice(int $id): bool {
        if ($this->useSqlite) {
            $db = new \SQLite3($this->dbPath);
            $stmt = $db->prepare("DELETE FROM devices WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $success = $stmt->execute() !== false;
            $db->close();
            return $success;
        } else {
            return $this->withLockedDevices(function(array $devices) use ($id) {
                $newDevices = [];
                foreach ($devices as $device) {
                    if ((int)$device['id'] !== $id) {
                        $newDevices[] = $device;
                    }
                }
                return $newDevices;
            });
        }
    }
}
