<?php
/**
 * Supabase High-Performance Database Adapter for EventVista / Tyoy Creation
 * Drop-in polymorphic replacement for mysqli, connecting directly to Supabase
 */

class SupabaseResult {
    public $num_rows = 0;
    private $rows = [];
    private $cursor = 0;

    public function __construct(array $rows = []) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
        $this->cursor = 0;
    }

    public function fetch_assoc(): ?array {
        if ($this->cursor < $this->num_rows) {
            return $this->rows[$this->cursor++];
        }
        return null;
    }

    public function fetch_row(): ?array {
        if ($this->cursor < $this->num_rows) {
            return array_values($this->rows[$this->cursor++]);
        }
        return null;
    }

    public function fetch_all(int $mode = MYSQLI_NUM): array {
        if ($mode === MYSQLI_ASSOC) {
            return $this->rows;
        }
        return array_map('array_values', $this->rows);
    }

    public function data_seek(int $offset = 0): bool {
        if ($offset >= 0 && $offset < $this->num_rows) {
            $this->cursor = $offset;
            return true;
        }
        return false;
    }

    public function free(): void {}
    public function close(): void {}
}

class SupabaseStatement {
    private $conn;
    private $rawSql;
    private $params = [];
    private $result = null;
    private $boundResultVars = [];

    public $num_rows = 0;
    public $insert_id = 0;
    public $affected_rows = 0;
    public $error = '';
    public $errno = 0;

    public function __construct($conn, string $sql) {
        $this->conn = $conn;
        $this->rawSql = $sql;
    }

    public function bind_param(string $types, &...$params): bool {
        $this->params = [];
        foreach ($params as &$p) {
            $this->params[] = &$p;
        }
        return true;
    }

    public function execute(?array $params = null): bool {
        $paramValues = [];
        $source = ($params !== null) ? $params : $this->params;
        foreach ($source as $v) {
            $paramValues[] = $v;
        }
        $res = $this->conn->executePrepared($this->rawSql, $paramValues);
        if ($res === false) {
            $this->error = $this->conn->error;
            $this->errno = $this->conn->errno;
            return false;
        }
        if ($res instanceof SupabaseResult) {
            $this->result = $res;
            $this->num_rows = $res->num_rows;
        } else {
            $this->result = null;
            $this->num_rows = 0;
        }
        $this->insert_id = $this->conn->insert_id;
        $this->affected_rows = $this->conn->affected_rows;
        return true;
    }

    public function get_result(): SupabaseResult|false {
        return $this->result ?? new SupabaseResult([]);
    }

    public function store_result(): bool {
        return true;
    }

    public function bind_result(&...$vars): bool {
        $this->boundResultVars = [];
        foreach ($vars as &$v) {
            $this->boundResultVars[] = &$v;
        }
        return true;
    }

    public function fetch(): ?bool {
        if ($this->result) {
            $row = $this->result->fetch_row();
            if ($row === null) {
                return null;
            }
            if (!empty($this->boundResultVars)) {
                foreach ($this->boundResultVars as $idx => &$var) {
                    $var = $row[$idx] ?? null;
                }
            }
            return true;
        }
        return false;
    }

    public function close(): bool {
        return true;
    }
}

class SupabaseConnection {
    public $insert_id = 0;
    public $affected_rows = 0;
    public $error = '';
    public $errno = 0;
    public $connect_error = null;

    private $token;
    private $projectRef;
    private $pdo = null;
    private $curlHandle = null;
    private $queryCache = [];

    public function __construct() {
        $this->token = defined('ENV_SUPABASE_TOKEN') ? ENV_SUPABASE_TOKEN : '';
        $this->projectRef = defined('ENV_SUPABASE_PROJECT_REF') ? ENV_SUPABASE_PROJECT_REF : '';

        // Check if direct PostgreSQL connection is configured with password
        if (defined('ENV_SUPABASE_DB_PASS') && !empty(ENV_SUPABASE_DB_PASS) && extension_loaded('pdo_pgsql')) {
            $host = defined('ENV_SUPABASE_DB_HOST') ? ENV_SUPABASE_DB_HOST : "aws-0-ap-southeast-1.pooler.supabase.com";
            $port = defined('ENV_SUPABASE_DB_PORT') ? ENV_SUPABASE_DB_PORT : "6543";
            $user = defined('ENV_SUPABASE_DB_USER') ? ENV_SUPABASE_DB_USER : "postgres.{$this->projectRef}";
            $pass = ENV_SUPABASE_DB_PASS;
            try {
                $dsn = "pgsql:host={$host};port={$port};dbname=postgres;sslmode=require";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5
                ]);
            } catch (Exception $e) {
                $this->pdo = null;
            }
        }
    }

    public function set_charset(string $charset): bool {
        return true;
    }

    public function real_escape_string(string $str): string {
        if ($this->pdo) {
            $quoted = $this->pdo->quote($str);
            return substr($quoted, 1, -1);
        }
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $str);
    }

    public function escape_string(string $str): string {
        return $this->real_escape_string($str);
    }

    public function close(): bool {
        $this->pdo = null;
        if ($this->curlHandle !== null) {
            @curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
        return true;
    }

    public function __destruct() {
        $this->close();
    }

    public function prepare(string $query): SupabaseStatement|false {
        return new SupabaseStatement($this, $query);
    }

    public function query(string $query, int $resultmode = MYSQLI_STORE_RESULT): SupabaseResult|bool {
        $translated = $this->translateQuery($query, []);
        return $this->executeSql($translated['sql'], $translated['params']);
    }

    public function executePrepared(string $sql, array $params = []): SupabaseResult|bool {
        $cleanParams = [];
        foreach ($params as $v) {
            $cleanParams[] = $v;
        }
        $translated = $this->translateQuery($sql, $cleanParams);
        return $this->executeSql($translated['sql'], $translated['params']);
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        if ($this->pdo) return $this->pdo->beginTransaction();
        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool {
        if ($this->pdo) return $this->pdo->commit();
        return true;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool {
        if ($this->pdo) return $this->pdo->rollBack();
        return true;
    }

    private function translateQuery(string $sql, array $params = []): array {
        // Strip MySQL backticks
        $sql = str_replace('`', '', $sql);

        // Translate CURDATE() -> CURRENT_DATE
        $sql = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $sql);

        // Translate DATE_SUB(...) and DATE_ADD(...)
        $sql = preg_replace('/DATE_SUB\s*\(\s*([^,]+)\s*,\s*INTERVAL\s+(\d+)\s+([a-zA-Z]+)\s*\)/i', "($1 - INTERVAL '$2 $3')", $sql);
        $sql = preg_replace('/DATE_ADD\s*\(\s*([^,]+)\s*,\s*INTERVAL\s+(\d+)\s+([a-zA-Z]+)\s*\)/i', "($1 + INTERVAL '$2 $3')", $sql);

        // Translate DAY(...), MONTH(...), YEAR(...)
        $sql = preg_replace('/\bDAY\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(DAY FROM $1)::int", $sql);
        $sql = preg_replace('/\bMONTH\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(MONTH FROM $1)::int", $sql);
        $sql = preg_replace('/\bYEAR\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(YEAR FROM $1)::int", $sql);

        // Translate GROUP_CONCAT(...) -> STRING_AGG(...)
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*DISTINCT\s+([^,]+)\s+SEPARATOR\s+([^)]+)\)/i', 'STRING_AGG(DISTINCT $1, $2)', $sql);
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*([^,]+)\s+SEPARATOR\s+([^)]+)\)/i', 'STRING_AGG($1, $2)', $sql);


        // Translate ON DUPLICATE KEY UPDATE for settings
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            if (stripos($sql, 'settings') !== false) {
                $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+setting_value\s*=\s*(?:VALUES\(setting_value\)|\?)/i', 'ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value', $sql);
            }
        }

        // Handle MySQL LIMIT offset, count -> Postgres LIMIT count OFFSET offset
        // Case: LIMIT ?, ?
        if (preg_match('/\bLIMIT\s+\?\s*,\s*\?/i', $sql)) {
            $sql = preg_replace('/\bLIMIT\s+\?\s*,\s*\?/i', 'LIMIT ? OFFSET ?', $sql);
            // In MySQL, parameter 1 is OFFSET, parameter 2 is COUNT.
            // In Postgres LIMIT count OFFSET offset, parameter 1 is COUNT, parameter 2 is OFFSET.
            if (count($params) >= 2) {
                $lastIdx = count($params) - 1;
                $secondLast = count($params) - 2;
                $tmp = $params[$secondLast];
                $params[$secondLast] = $params[$lastIdx];
                $params[$lastIdx] = $tmp;
            }
        } elseif (preg_match('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', $sql, $m)) {
            $sql = preg_replace('/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', 'LIMIT ' . $m[2] . ' OFFSET ' . $m[1], $sql);
        }

        // Strip "LIMIT 1" on DELETE if any
        if (stripos($sql, 'DELETE FROM') !== false && preg_match('/\bLIMIT\s+\d+\b/i', $sql)) {
            $sql = preg_replace('/\bLIMIT\s+\d+\b/i', '', $sql);
        }

        // For INSERT into tables with auto-increment id, append RETURNING id if not present
        if (preg_match('/^\s*INSERT\s+INTO\s+(\w+)/i', $sql, $m)) {
            if (stripos($sql, 'RETURNING') === false && strtolower($m[1]) !== 'settings') {
                $sql = rtrim($sql, '; ') . ' RETURNING id';
            }
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function executeSql(string $sql, array $params = []): SupabaseResult|bool {
        $this->error = '';
        $this->errno = 0;
        $this->insert_id = 0;
        $this->affected_rows = 0;

        // Engine 1: Direct PostgreSQL PDO
        if ($this->pdo) {
            return $this->executePdo($sql, $params);
        }

        // Engine 2: Supabase Management Query API
        return $this->executeApi($sql, $params);
    }

    private function executePdo(string $sql, array $params): SupabaseResult|bool {
        try {
            $stmt = $this->pdo->prepare($sql);
            $exec = $stmt->execute($params);
            if (!$exec) {
                $err = $stmt->errorInfo();
                $this->error = $err[2] ?? 'PDO Execution Error';
                $this->errno = 1001;
                return false;
            }
            if ($stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (stripos($sql, 'RETURNING id') !== false && !empty($rows[0]['id'])) {
                    $this->insert_id = (int)$rows[0]['id'];
                }
                return new SupabaseResult($rows);
            }
            $this->affected_rows = $stmt->rowCount();
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errno = 1002;
            return false;
        }
    }

    private function executeApi(string $sql, array $params): SupabaseResult|bool {
        // Substitute ? placeholders safely with properly escaped literals
        if (!empty($params)) {
            $interpolated = '';
            $chunks = explode('?', $sql);
            for ($i = 0; $i < count($chunks); $i++) {
                $interpolated .= $chunks[$i];
                if ($i < count($params)) {
                    $val = $params[$i];
                    if ($val === null) {
                        $interpolated .= 'NULL';
                    } elseif (is_bool($val)) {
                        $interpolated .= $val ? 'TRUE' : 'FALSE';
                    } elseif (is_int($val) || is_float($val)) {
                        $interpolated .= $val;
                    } else {
                        $interpolated .= "'" . str_replace("'", "''", (string)$val) . "'";
                    }
                }
            }
            $sql = $interpolated;
        }

        $isSelect = (bool)preg_match('/^\s*(SELECT|WITH|SHOW)\b/i', $sql);
        $cacheKey = $isSelect ? md5($sql) : null;

        // In-memory query cache check
        if ($cacheKey && isset($this->queryCache[$cacheKey])) {
            return new SupabaseResult($this->queryCache[$cacheKey]);
        }

        // Reuse persistent cURL handle for Keep-Alive / connection reuse
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init("https://api.supabase.com/v1/projects/{$this->projectRef}/database/query");
            curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curlHandle, CURLOPT_POST, true);
            curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->token}",
                "Content-Type: application/json"
            ]);
            curl_setopt($this->curlHandle, CURLOPT_TIMEOUT, 12);
            curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPIDLE, 120);
            curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPINTVL, 60);
        }

        curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, json_encode(["query" => $sql]));

        $raw = curl_exec($this->curlHandle);
        $code = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($this->curlHandle);

        if ($curlErr) {
            $this->error = "cURL error: " . $curlErr;
            $this->errno = 1003;
            $this->connect_error = $this->error;
            // Reset handle on network error so next attempt tries a fresh connection
            @curl_close($this->curlHandle);
            $this->curlHandle = null;
            return false;
        }

        $json = json_decode($raw, true);

        if ($code >= 400 || (isset($json['message']) && !is_array($json))) {
            $this->error = $json['message'] ?? "Supabase HTTP error {$code}";
            $this->errno = $code;
            return false;
        }

        if (is_array($json)) {
            if (stripos($sql, 'RETURNING id') !== false && !empty($json[0]['id'])) {
                $this->insert_id = (int)$json[0]['id'];
            }
            if ($isSelect || stripos($sql, 'RETURNING') !== false) {
                if ($cacheKey) {
                    $this->queryCache[$cacheKey] = $json;
                }
                return new SupabaseResult($json);
            }
            $this->affected_rows = count($json);
            // Invalidate cache on write operations
            $this->queryCache = [];
            return true;
        }

        // Invalidate cache on non-select writes
        $this->queryCache = [];
        return true;
    }
}
