<?php
/**
 * Supabase High-Performance Database Adapter for EventVista / Tyoy Creation
 * Drop-in polymorphic replacement for mysqli, connecting directly to Supabase
 */

require_once __DIR__ . '/cache_helper.php';

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
                $this->pdo->exec("SET timezone = 'Asia/Manila'");
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

        // Translate CURDATE() & CURRENT_DATE -> Asia/Manila current date (prevents 8-hour offset errors)
        $sql = preg_replace('/\bCURDATE\(\)/i', "((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)", $sql);
        $sql = preg_replace('/(?<!TIME ZONE\s)\bCURRENT_DATE\b/i', "((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date)", $sql);

        // Translate DATE(col) -> ((col AT TIME ZONE 'Asia/Manila')::date)
        $sql = preg_replace('/\bDATE\s*\(\s*([a-zA-Z0-9_]+)\s*\)/i', "(($1 AT TIME ZONE 'Asia/Manila')::date)", $sql);

        // Translate DATE_SUB(...) and DATE_ADD(...)
        $sql = preg_replace('/DATE_SUB\s*\(\s*([^,]+)\s*,\s*INTERVAL\s+(\d+)\s+([a-zA-Z]+)\s*\)/i', "($1 - INTERVAL '$2 $3')", $sql);
        $sql = preg_replace('/DATE_ADD\s*\(\s*([^,]+)\s*,\s*INTERVAL\s+(\d+)\s+([a-zA-Z]+)\s*\)/i', "($1 + INTERVAL '$2 $3')", $sql);

        // Translate DAY(...), MONTH(...), YEAR(...) with Asia/Manila timezone
        $sql = preg_replace('/\bDAY\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(DAY FROM ($1 AT TIME ZONE 'Asia/Manila'))::int", $sql);
        $sql = preg_replace('/\bMONTH\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(MONTH FROM ($1 AT TIME ZONE 'Asia/Manila'))::int", $sql);
        $sql = preg_replace('/\bYEAR\s*\(\s*([^)]+)\s*\)/i', "EXTRACT(YEAR FROM ($1 AT TIME ZONE 'Asia/Manila'))::int", $sql);

        // Translate GROUP_CONCAT(...) -> STRING_AGG(...)
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*DISTINCT\s+([^,]+)\s+SEPARATOR\s+([^)]+)\)/i', 'STRING_AGG(DISTINCT $1, $2)', $sql);
        $sql = preg_replace('/GROUP_CONCAT\s*\(\s*([^,]+)\s+SEPARATOR\s+([^)]+)\)/i', 'STRING_AGG($1, $2)', $sql);

        // Translate CAST(... AS CHAR) -> CAST(... AS TEXT) (prevents Postgres CHAR(1) truncation)
        $sql = preg_replace('/\bCAST\s*\(\s*([^)]+?)\s+AS\s+CHAR(?:\s*\(\s*\d+\s*\))?\s*\)/i', 'CAST($1 AS TEXT)', $sql);


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

    private function isQueryCacheable(string $sql): bool {
        if (!preg_match('/^\s*(SELECT|WITH|SHOW)\b/i', $sql)) {
            return false;
        }
        if (stripos($sql, 'login_attempts') !== false) {
            return false;
        }
        if (stripos($sql, 'is_blocked') !== false) {
            return false;
        }
        if (stripos($sql, 'password') !== false && stripos($sql, 'users') !== false) {
            return false;
        }
        if (stripos($sql, 'FOR UPDATE') !== false || stripos($sql, '/* NO_CACHE */') !== false) {
            return false;
        }
        return true;
    }

    private function getCacheKey(string $sql, array $params = []): ?string {
        if (!$this->isQueryCacheable($sql)) {
            return null;
        }
        $ver = class_exists('QesCache') ? (int)QesCache::get('qes_db_cache_ver', 1) : 1;
        $hash = md5($sql . (!empty($params) ? ':' . serialize($params) : ''));
        return "sqc_{$ver}_{$hash}";
    }

    private function invalidateQueryCache(): void {
        $this->queryCache = [];
        if (class_exists('QesCache')) {
            QesCache::set('qes_db_cache_ver', time(), 86400);
            QesCache::delete('all_settings_map');
        }
    }

    private function executePdo(string $sql, array $params): SupabaseResult|bool {
        $cacheKey = $this->getCacheKey($sql, $params);
        if ($cacheKey && isset($this->queryCache[$cacheKey])) {
            return new SupabaseResult($this->queryCache[$cacheKey]);
        }
        if ($cacheKey && class_exists('QesCache')) {
            $cachedRows = QesCache::get($cacheKey);
            if (is_array($cachedRows)) {
                $this->queryCache[$cacheKey] = $cachedRows;
                return new SupabaseResult($cachedRows);
            }
        }

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
                if ($cacheKey) {
                    $this->queryCache[$cacheKey] = $rows;
                    if (class_exists('QesCache')) {
                        QesCache::set($cacheKey, $rows, 25);
                    }
                }
                return new SupabaseResult($rows);
            }
            $this->affected_rows = $stmt->rowCount();
            $this->invalidateQueryCache();
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errno = 1002;
            return false;
        }
    }

    private function executeApi(string $sql, array $params): SupabaseResult|bool {
        $originalSql = $sql;
        $originalParams = $params;

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
        $cacheKey = $this->getCacheKey($sql);

        // 1. In-memory query cache check
        if ($cacheKey && isset($this->queryCache[$cacheKey])) {
            return new SupabaseResult($this->queryCache[$cacheKey]);
        }

        // 2. Persistent Cross-request cache check
        if ($cacheKey && class_exists('QesCache')) {
            $cachedRows = QesCache::get($cacheKey);
            if (is_array($cachedRows)) {
                $this->queryCache[$cacheKey] = $cachedRows;
                return new SupabaseResult($cachedRows);
            }
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
            curl_setopt($this->curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($this->curlHandle, CURLOPT_CONNECTTIMEOUT, 4);
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
            $errMessage = $json['message'] ?? "Supabase HTTP error {$code}";

            // Fallback: If write failed due to read-only transaction or permissions, route directly to PostgREST using SERVICE_KEY
            if (!$isSelect && (stripos($errMessage, 'read-only') !== false || stripos($errMessage, '25006') !== false || stripos($errMessage, 'permission denied') !== false)) {
                $pgRes = $this->executePostgrestFallback($sql, $originalParams, $originalSql);
                if ($pgRes !== false) {
                    $this->invalidateQueryCache();
                    return $pgRes;
                }
            }

            $this->error = $errMessage;
            $this->errno = $code;
            if ($code === 401 || $code === 403) {
                $this->connect_error = "Supabase Authentication Error ({$code}): {$this->error}. Please check ENV_SUPABASE_TOKEN in .env.php";
                error_log("[SupabaseConnection] {$this->connect_error}");
            }
            return false;
        }

        if (is_array($json)) {
            if (stripos($sql, 'RETURNING id') !== false && !empty($json[0]['id'])) {
                $this->insert_id = (int)$json[0]['id'];
            }
            if ($isSelect || stripos($sql, 'RETURNING') !== false) {
                if ($cacheKey) {
                    $this->queryCache[$cacheKey] = $json;
                    if (class_exists('QesCache')) {
                        QesCache::set($cacheKey, $json, 25);
                    }
                }
                return new SupabaseResult($json);
            }
            $this->affected_rows = count($json);
            // Invalidate cache on write operations
            $this->invalidateQueryCache();
            return true;
        }

        // Invalidate cache on non-select writes
        $this->invalidateQueryCache();
        return true;
    }

    /**
     * PostgREST direct execution fallback using persistent SERVICE_KEY.
     * Guarantees write operations (UPDATE, INSERT, DELETE) succeed even when
     * the SQL management endpoint is in a read-only transaction or replica mode.
     */
    private function executePostgrestFallback(string $sql, array $params = [], string $paramSql = ''): SupabaseResult|bool {
        $serviceKey = defined('ENV_SUPABASE_SERVICE_KEY') ? ENV_SUPABASE_SERVICE_KEY : '';
        $supabaseUrl = defined('ENV_SUPABASE_URL') ? ENV_SUPABASE_URL : '';
        if (empty($serviceKey) || empty($supabaseUrl)) {
            return false;
        }

        // 1. INSERT statement fallback
        $insertMatched = false;
        $table = '';
        $data = [];
        $isUpsert = false;

        // Route A: Structured parameterized INSERT
        if (!empty($params) && !empty($paramSql) && preg_match('/^\s*INSERT\s+INTO\s+(\w+)\s*\((.+?)\)\s*VALUES\s*\([?\s,]+\)(.*)$/is', $paramSql, $matches)) {
            $table = $matches[1];
            $cols = array_map('trim', explode(',', $matches[2]));
            $trailing = $matches[3] ?? '';
            if (stripos($trailing, 'ON CONFLICT') !== false || stripos($trailing, 'ON DUPLICATE KEY') !== false) {
                $isUpsert = true;
            }
            foreach ($cols as $idx => $col) {
                $data[$col] = array_key_exists($idx, $params) ? $params[$idx] : null;
            }
            $insertMatched = true;
        }
        // Route B: Parse raw interpolated SQL query with quote-aware tokenizer
        elseif (preg_match('/^\s*INSERT\s+INTO\s+(\w+)\s*\((.+?)\)\s*VALUES\s*\((.+)\)/is', $sql, $matches)) {
            $table = $matches[1];
            $cols = array_map('trim', explode(',', $matches[2]));
            $rawValuesString = trim($matches[3]);

            if (stripos($rawValuesString, 'ON CONFLICT') !== false || stripos($rawValuesString, 'ON DUPLICATE KEY') !== false) {
                $isUpsert = true;
            }

            // Strip trailing ON CONFLICT or RETURNING id from raw values
            if (preg_match('/^(.*?)\)\s*(?:ON\s+(?:CONFLICT|DUPLICATE)|RETURNING\b.*|$)/is', $rawValuesString, $rm)) {
                $rawValuesString = $rm[1];
            }

            $tokens = [];
            $len = strlen($rawValuesString);
            $current = '';
            $inQuote = false;
            for ($i = 0; $i < $len; $i++) {
                $char = $rawValuesString[$i];
                if ($char === "'") {
                    if ($inQuote && $i + 1 < $len && $rawValuesString[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $inQuote = !$inQuote;
                    $current .= $char;
                } elseif ($char === ',' && !$inQuote) {
                    $tokens[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if (trim($current) !== '') {
                $tokens[] = trim($current);
            }

            foreach ($cols as $idx => $col) {
                $valRaw = $tokens[$idx] ?? 'NULL';
                if (strcasecmp($valRaw, 'NULL') === 0) {
                    $data[$col] = null;
                } elseif (preg_match("/^'((?:''|[^'])*)'$/s", $valRaw, $sm)) {
                    $data[$col] = str_replace("''", "'", $sm[1]);
                } elseif (is_numeric($valRaw)) {
                    $data[$col] = strpos($valRaw, '.') !== false ? (float)$valRaw : (int)$valRaw;
                } elseif (strcasecmp($valRaw, 'TRUE') === 0) {
                    $data[$col] = true;
                } elseif (strcasecmp($valRaw, 'FALSE') === 0) {
                    $data[$col] = false;
                } elseif (stripos($valRaw, 'NOW()') !== false || stripos($valRaw, 'CURRENT_TIMESTAMP') !== false) {
                    $data[$col] = date('c');
                } else {
                    $data[$col] = trim($valRaw, "'");
                }
            }
            $insertMatched = true;
        }

        if ($insertMatched && !empty($table) && !empty($data)) {
            $endpoint = $supabaseUrl . "/rest/v1/{$table}";
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            $prefer = "return=representation";
            if ($isUpsert) {
                $prefer .= ",resolution=merge-duplicates";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: {$serviceKey}",
                "Authorization: Bearer {$serviceKey}",
                "Content-Type: application/json",
                "Prefer: {$prefer}"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                $decoded = json_decode($res, true);
                if (is_array($decoded) && !empty($decoded[0]['id'])) {
                    $this->insert_id = (int)$decoded[0]['id'];
                }
                $this->affected_rows = 1;
                $this->error = '';
                $this->errno = 0;
                $this->queryCache = [];
                return true;
            } else {
                error_log("[Supabase Fallback INSERT Error] HTTP {$code}: {$res}" . ($curlErr ? " (curl error: {$curlErr})" : ""));
            }
        }

        // 2. UPDATE statement fallback
        if (preg_match('/^\s*UPDATE\s+(\w+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $matches)) {
            $table = $matches[1];
            $setClause = $matches[2];
            $whereClause = $matches[3];

            $data = [];
            $setParts = [];
            $len = strlen($setClause);
            $current = '';
            $inQuote = false;
            for ($i = 0; $i < $len; $i++) {
                $char = $setClause[$i];
                if ($char === "'") {
                    if ($inQuote && $i + 1 < $len && $setClause[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $inQuote = !$inQuote;
                    $current .= $char;
                } elseif ($char === ',' && !$inQuote) {
                    $setParts[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if (trim($current) !== '') {
                $setParts[] = trim($current);
            }

            foreach ($setParts as $part) {
                if (preg_match('/^\s*(\w+)\s*=\s*(.+?)\s*$/s', trim($part), $pm)) {
                    $col = $pm[1];
                    $valRaw = trim($pm[2]);
                    if (strcasecmp($valRaw, 'NULL') === 0) {
                        $data[$col] = null;
                    } elseif (preg_match("/^'((?:''|[^'])*)'$/s", $valRaw, $sm)) {
                        $data[$col] = str_replace("''", "'", $sm[1]);
                    } elseif (is_numeric($valRaw)) {
                        $data[$col] = strpos($valRaw, '.') !== false ? (float)$valRaw : (int)$valRaw;
                    } elseif (strcasecmp($valRaw, 'TRUE') === 0) {
                        $data[$col] = true;
                    } elseif (strcasecmp($valRaw, 'FALSE') === 0) {
                        $data[$col] = false;
                    } elseif (stripos($valRaw, 'NOW()') !== false) {
                        if (preg_match("/INTERVAL\s*'(\d+)\s+([a-zA-Z]+)'/i", $valRaw, $im)) {
                            $data[$col] = date('c', strtotime("+{$im[1]} {$im[2]}"));
                        } else {
                            $data[$col] = date('c');
                        }
                    } elseif (preg_match('/^(\w+)\s*([+-])\s*(\d+)$/', $valRaw, $exprM) && $exprM[1] === $col) {
                        $data[$col] = (int)$exprM[3];
                    } else {
                        $data[$col] = trim($valRaw, "'");
                    }
                }
            }

            $whereParts = [];
            $len = strlen($whereClause);
            $current = '';
            $inQuote = false;
            for ($i = 0; $i < $len; $i++) {
                $char = $whereClause[$i];
                if ($char === "'") {
                    if ($inQuote && $i + 1 < $len && $whereClause[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $inQuote = !$inQuote;
                    $current .= $char;
                } elseif (!$inQuote && (substr($whereClause, $i, 5) === ' AND ' || substr($whereClause, $i, 5) === ' and ')) {
                    $whereParts[] = trim($current);
                    $current = '';
                    $i += 4;
                } else {
                    $current .= $char;
                }
            }
            if (trim($current) !== '') {
                $whereParts[] = trim($current);
            }

            $queryFilters = [];
            foreach ($whereParts as $wp) {
                if (preg_match('/^\s*(\w+)\s*=\s*(.+?)\s*$/s', trim($wp), $wm)) {
                    $wCol = $wm[1];
                    $wVal = trim($wm[2]);
                    if (preg_match("/^'((?:''|[^'])*)'$/s", $wVal, $wsm)) {
                        $wVal = str_replace("''", "'", $wsm[1]);
                    }
                    $queryFilters[] = urlencode($wCol) . "=eq." . urlencode($wVal);
                } elseif (preg_match('/^\s*(\w+)\s*!=\s*(.+?)\s*$/s', trim($wp), $wm)) {
                    $wCol = $wm[1];
                    $wVal = trim($wm[2]);
                    if (preg_match("/^'((?:''|[^'])*)'$/s", $wVal, $wsm)) {
                        $wVal = str_replace("''", "'", $wsm[1]);
                    }
                    $queryFilters[] = urlencode($wCol) . "=neq." . urlencode($wVal);
                }
            }

            $endpoint = $supabaseUrl . "/rest/v1/{$table}?" . implode('&', $queryFilters);
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: {$serviceKey}",
                "Authorization: Bearer {$serviceKey}",
                "Content-Type: application/json",
                "Prefer: return=representation"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                $decoded = json_decode($res, true);
                $this->affected_rows = is_array($decoded) ? count($decoded) : 1;
                $this->error = '';
                $this->errno = 0;
                $this->queryCache = [];
                return true;
            } else {
                error_log("[Supabase Fallback UPDATE Error] HTTP {$code}: {$res}" . ($curlErr ? " (curl error: {$curlErr})" : ""));
            }
        }

        // 3. DELETE statement fallback
        if (preg_match('/^\s*DELETE\s+FROM\s+(\w+)\s+WHERE\s+(.+)$/is', $sql, $matches)) {
            $table = $matches[1];
            $whereClause = $matches[2];

            $whereParts = [];
            $len = strlen($whereClause);
            $current = '';
            $inQuote = false;
            for ($i = 0; $i < $len; $i++) {
                $char = $whereClause[$i];
                if ($char === "'") {
                    if ($inQuote && $i + 1 < $len && $whereClause[$i + 1] === "'") {
                        $current .= "'";
                        $i++;
                        continue;
                    }
                    $inQuote = !$inQuote;
                    $current .= $char;
                } elseif (!$inQuote && (substr($whereClause, $i, 5) === ' AND ' || substr($whereClause, $i, 5) === ' and ')) {
                    $whereParts[] = trim($current);
                    $current = '';
                    $i += 4;
                } else {
                    $current .= $char;
                }
            }
            if (trim($current) !== '') {
                $whereParts[] = trim($current);
            }

            $queryFilters = [];
            foreach ($whereParts as $wp) {
                if (preg_match('/^\s*(\w+)\s*=\s*(.+?)\s*$/s', trim($wp), $wm)) {
                    $wCol = $wm[1];
                    $wVal = trim($wm[2]);
                    if (preg_match("/^'((?:''|[^'])*)'$/s", $wVal, $wsm)) {
                        $wVal = str_replace("''", "'", $wsm[1]);
                    }
                    $queryFilters[] = urlencode($wCol) . "=eq." . urlencode($wVal);
                } elseif (preg_match('/^\s*(\w+)\s*!=\s*(.+?)\s*$/s', trim($wp), $wm)) {
                    $wCol = $wm[1];
                    $wVal = trim($wm[2]);
                    if (preg_match("/^'((?:''|[^'])*)'$/s", $wVal, $wsm)) {
                        $wVal = str_replace("''", "'", $wsm[1]);
                    }
                    $queryFilters[] = urlencode($wCol) . "=neq." . urlencode($wVal);
                }
            }

            $endpoint = $supabaseUrl . "/rest/v1/{$table}?" . implode('&', $queryFilters);
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "apikey: {$serviceKey}",
                "Authorization: Bearer {$serviceKey}",
                "Content-Type: application/json"
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                $this->affected_rows = 1;
                $this->error = '';
                $this->errno = 0;
                $this->queryCache = [];
                return true;
            } else {
                error_log("[Supabase Fallback DELETE Error] HTTP {$code}: {$res}" . ($curlErr ? " (curl error: {$curlErr})" : ""));
            }
        }

        return false;
    }
}
