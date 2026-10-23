<?php
/**
 * QES Database-Backed Session Handler
 * Stores PHP session data in qes_sessions table for multi-instance serverless resilience.
 * Implements SessionHandlerInterface and SessionUpdateTimestampHandlerInterface.
 */

class QesSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface {
    private $conn;
    private $lifetime;
    private $isPostgres = false;

    public function __construct($conn, int $lifetime = 28800) {
        $this->conn = $conn;
        $this->lifetime = $lifetime > 0 ? $lifetime : 28800; // 8 hours default sliding window
        $this->isPostgres = is_object($conn) && (
            get_class($conn) === 'SupabaseConnection' ||
            (isset($conn->server_info) && stripos($conn->server_info, 'postgres') !== false)
        );
    }

    public function open(string $path, string $name): bool {
        return is_object($this->conn);
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        if (!is_object($this->conn)) {
            return '';
        }
        try {
            $now = time();
            $stmt = $this->conn->prepare("SELECT data FROM qes_sessions WHERE id = ? AND expires_at > ? LIMIT 1");
            if (!$stmt) {
                return '';
            }
            $stmt->bind_param("si", $id, $now);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stmt->close();
                return (string)($row['data'] ?? '');
            }
            $stmt->close();
            return '';
        } catch (\Throwable $e) {
            error_log('[QesSessionHandler read error] ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool {
        if (!is_object($this->conn)) {
            return false;
        }
        try {
            $expiresAt = time() + $this->lifetime;
            if ($this->isPostgres) {
                $sql = "INSERT INTO qes_sessions (id, data, updated_at, expires_at)
                        VALUES (?, ?, CURRENT_TIMESTAMP, ?)
                        ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = CURRENT_TIMESTAMP, expires_at = EXCLUDED.expires_at";
            } else {
                $sql = "INSERT INTO qes_sessions (id, data, updated_at, expires_at)
                        VALUES (?, ?, NOW(), ?)
                        ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW(), expires_at = VALUES(expires_at)";
            }

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("ssi", $id, $data, $expiresAt);
            $res = $stmt->execute();
            $stmt->close();
            return (bool)$res;
        } catch (\Throwable $e) {
            error_log('[QesSessionHandler write error] ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool {
        if (!is_object($this->conn)) {
            return true;
        }
        try {
            $stmt = $this->conn->prepare("DELETE FROM qes_sessions WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("s", $id);
                $stmt->execute();
                $stmt->close();
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[QesSessionHandler destroy error] ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false {
        if (!is_object($this->conn)) {
            return 0;
        }
        try {
            $now = time();
            $stmt = $this->conn->prepare("DELETE FROM qes_sessions WHERE expires_at < ?");
            if ($stmt) {
                $stmt->bind_param("i", $now);
                $stmt->execute();
                $affected = $stmt->affected_rows ?? 0;
                $stmt->close();
                return (int)$affected;
            }
            return 0;
        } catch (\Throwable $e) {
            error_log('[QesSessionHandler gc error] ' . $e->getMessage());
            return 0;
        }
    }

    public function validateId(string $id): bool {
        return !empty($id) && strlen($id) <= 128 && preg_match('/^[a-zA-Z0-9,-]+$/', $id);
    }

    public function updateTimestamp(string $id, string $data): bool {
        if (!is_object($this->conn)) {
            return false;
        }
        try {
            $expiresAt = time() + $this->lifetime;
            $sql = $this->isPostgres
                ? "UPDATE qes_sessions SET updated_at = CURRENT_TIMESTAMP, expires_at = ? WHERE id = ?"
                : "UPDATE qes_sessions SET updated_at = NOW(), expires_at = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("is", $expiresAt, $id);
                $res = $stmt->execute();
                $stmt->close();
                return (bool)$res;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
