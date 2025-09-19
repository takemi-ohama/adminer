<?php
namespace Adminer;

// Import required BigQuery classes
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Table;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\ServiceException;

if (function_exists('Adminer\\add_driver')) {
    add_driver("bigquery", "Google BigQuery");
}

if (isset($_GET["bigquery"])) {
	define('Adminer\DRIVER', "bigquery");

/**
 * BigQuery Connection Pool for client reuse
 * 接続オブジェクトの再利用により初期化時間を大幅短縮
 */
class BigQueryConnectionPool {
    /** @var array Connection pool storage */
    private static $pool = [];
    
    /** @var int Maximum connections to keep in pool */
    private static $maxConnections = 3;
    
    /** @var array Connection usage timestamps for LRU eviction */
    private static $usageTimestamps = [];
    
    /** @var array Connection creation times for debugging */
    private static $creationTimes = [];

    /**
     * Get a BigQuery client from pool or create new one
     *
     * @param string $key Connection identifier
     * @param array $config Connection configuration
     * @return BigQueryClient
     */
    public static function getConnection($key, $config) {
        // Check if connection exists in pool
        if (isset(self::$pool[$key])) {
            self::$usageTimestamps[$key] = time();
            $age = time() - self::$creationTimes[$key];
            error_log("BigQuery ConnectionPool: Reusing connection (age: {$age}s, pool size: " . count(self::$pool) . ")");
            return self::$pool[$key];
        }

        // Clean up pool if it's at capacity
        if (count(self::$pool) >= self::$maxConnections) {
            self::evictOldestConnection();
        }

        // Create new connection
        $startTime = microtime(true);
        
        $clientConfig = [
            'projectId' => $config['projectId'],
            'location' => $config['location']
        ];
        
        // Add credentials path if provided
        if (isset($config['credentialsPath'])) {
            $clientConfig['keyFilePath'] = $config['credentialsPath'];
        }

        $client = new BigQueryClient($clientConfig);
        
        $creationTime = microtime(true) - $startTime;
        
        // Store in pool
        self::$pool[$key] = $client;
        self::$usageTimestamps[$key] = time();
        self::$creationTimes[$key] = time();
        
        error_log("BigQuery ConnectionPool: Created new connection in {$creationTime}s (pool size: " . count(self::$pool) . ")");
        
        return $client;
    }

    /**
     * Evict the least recently used connection
     */
    private static function evictOldestConnection() {
        if (empty(self::$usageTimestamps)) {
            return;
        }

        // Find LRU connection
        $oldestKey = array_keys(self::$usageTimestamps, min(self::$usageTimestamps))[0];
        
        // Remove from all tracking arrays
        unset(self::$pool[$oldestKey]);
        unset(self::$usageTimestamps[$oldestKey]);
        unset(self::$creationTimes[$oldestKey]);
        
        error_log("BigQuery ConnectionPool: Evicted LRU connection '$oldestKey' (pool size: " . count(self::$pool) . ")");
    }

    /**
     * Clear all connections from pool
     */
    public static function clearPool() {
        $count = count(self::$pool);
        self::$pool = [];
        self::$usageTimestamps = [];
        self::$creationTimes = [];
        error_log("BigQuery ConnectionPool: Cleared all connections ($count removed)");
    }

    /**
     * Get pool statistics for debugging
     */
    public static function getStats() {
        $stats = [
            'pool_size' => count(self::$pool),
            'max_size' => self::$maxConnections,
            'connections' => []
        ];
        
        foreach (self::$pool as $key => $client) {
            $stats['connections'][] = [
                'key' => substr($key, 0, 8) . '...',
                'age' => time() - self::$creationTimes[$key],
                'last_used' => time() - self::$usageTimestamps[$key]
            ];
        }
        
        return $stats;
    }
}

/**
 * BigQuery Configuration and Constants
 * 設定値とマッピング定数を管理
 */
class BigQueryConfig {
    /** @var array データ型マッピング（Map化） */
    public const TYPE_MAPPING = [
        'STRING' => ['type' => 'varchar', 'length' => null],
        'BYTES' => ['type' => 'varchar', 'length' => null],
        'INT64' => ['type' => 'bigint', 'length' => null],
        'INTEGER' => ['type' => 'bigint', 'length' => null],
        'FLOAT64' => ['type' => 'double', 'length' => null],
        'FLOAT' => ['type' => 'double', 'length' => null],
        'NUMERIC' => ['type' => 'decimal', 'length' => null],
        'BIGNUMERIC' => ['type' => 'decimal', 'length' => null],
        'BOOLEAN' => ['type' => 'tinyint', 'length' => 1],
        'BOOL' => ['type' => 'tinyint', 'length' => 1],
        'DATE' => ['type' => 'date', 'length' => null],
        'TIME' => ['type' => 'time', 'length' => null],
        'DATETIME' => ['type' => 'datetime', 'length' => null],
        'TIMESTAMP' => ['type' => 'timestamp', 'length' => null],
        'GEOGRAPHY' => ['type' => 'geometry', 'length' => null],
        'JSON' => ['type' => 'json', 'length' => null],
        'ARRAY' => ['type' => 'text', 'length' => null],
        'STRUCT' => ['type' => 'text', 'length' => null],
        'RECORD' => ['type' => 'text', 'length' => null],
    ];

    /** @var array 危険なSQLパターン（Map化） */
    public const DANGEROUS_SQL_PATTERNS = [
        'ddl_dml' => '/;\\s*(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\\s+/i',
        'union_injection' => '/UNION\\s+(ALL\\s+)?SELECT/i',
        'block_comments' => '/\\/\\*.*?\\*\\//i',
        'line_comments' => '/--[^\\r\\n]*/i',
        'execute_commands' => '/\\b(EXEC|EXECUTE|SP_)\\b/i',
    ];

    /** @var array サポート機能リスト（Map化） */
    public const SUPPORTED_FEATURES = [
        'database' => true,
        'table' => true,
        'columns' => true,
        'sql' => true,
        'view' => true,
        'materializedview' => true,
    ];

    /** @var array 非サポート機能リスト（Map化） */
    public const UNSUPPORTED_FEATURES = [
        'foreignkeys' => false,
        'indexes' => false,
        'processlist' => false,
        'kill' => false,
        'transaction' => false,
        'comment' => false,
        'drop_col' => false,
        'dump' => false,
        'event' => false,
        'move_col' => false,
        'privileges' => false,
        'procedure' => false,
        'routine' => false,
        'sequence' => false,
        'status' => false,
        'trigger' => false,
        'type' => false,
        'variables' => false,
        'descidx' => false,
        'check' => false,
        'schema' => false,
    ];

    /** @var array キャッシュ設定（Map化） */
    public const CACHE_CONFIG = [
        'credentials_ttl' => 10,      // 認証情報キャッシュ（秒）
        'location_ttl' => 86400,     // 位置情報キャッシュ（秒）
        'databases_ttl' => 300,      // データベース一覧キャッシュ（秒）
        'tables_ttl' => 300,         // テーブル一覧キャッシュ（秒）
        'fields_ttl' => 600,         // フィールド情報キャッシュ（秒）
        'apcu_shm_size' => '64M',    // APCu共有メモリサイズ
        'connection_pool_max' => 3,   // 接続プール最大数
    ];

    /**
     * BigQuery型をAdminer型にマッピング
     * @param string $bigQueryType
     * @return array
     */
    public static function mapType(string $bigQueryType): array {
        $baseType = strtoupper(preg_replace('/\\(.*\\)/', '', $bigQueryType));
        return self::TYPE_MAPPING[$baseType] ?? ['type' => 'text', 'length' => null];
    }

    /**
     * 危険なSQLパターンをチェック
     * @param string $query
     * @return bool
     */
    public static function isDangerousQuery(string $query): bool {
        foreach (self::DANGEROUS_SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 機能サポート状況を取得
     * @param string $feature
     * @return bool
     */
    public static function isFeatureSupported(string $feature): bool {
        return self::SUPPORTED_FEATURES[$feature] ?? 
               (self::UNSUPPORTED_FEATURES[$feature] ?? false);
    }
}

/**
 * BigQuery Cache Manager
 * キャッシュ機能を統合管理
 */
class BigQueryCacheManager {
    /** @var array 静的キャッシュストレージ */
    private static array $staticCache = [];
    
    /** @var array キャッシュタイムスタンプ */
    private static array $cacheTimestamps = [];

    /**
     * キャッシュから値を取得
     * @param string $key
     * @param int $ttl
     * @return mixed
     */
    public static function get(string $key, int $ttl = 300) {
        // APCu優先
        if (function_exists('apcu_exists') && apcu_exists($key)) {
            return apcu_fetch($key);
        }
        
        // 静的キャッシュフォールバック
        if (isset(self::$staticCache[$key]) && 
            (time() - (self::$cacheTimestamps[$key] ?? 0)) < $ttl) {
            return self::$staticCache[$key];
        }
        
        return false;
    }

    /**
     * キャッシュに値を設定
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return bool
     */
    public static function set(string $key, $value, int $ttl = 300): bool {
        $success = false;
        
        // APCu優先
        if (function_exists('apcu_store')) {
            $success = apcu_store($key, $value, $ttl);
        }
        
        // 静的キャッシュも更新
        self::$staticCache[$key] = $value;
        self::$cacheTimestamps[$key] = time();
        
        return $success;
    }

    /**
     * キャッシュをクリア
     * @param string|null $pattern
     * @return void
     */
    public static function clear(?string $pattern = null): void {
        if ($pattern === null) {
            // 全クリア
            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }
            self::$staticCache = [];
            self::$cacheTimestamps = [];
        } else {
            // パターンマッチでクリア
            foreach (array_keys(self::$staticCache) as $key) {
                if (strpos($key, $pattern) !== false) {
                    unset(self::$staticCache[$key]);
                    unset(self::$cacheTimestamps[$key]);
                }
            }
        }
    }

    /**
     * キャッシュ統計を取得
     * @return array
     */
    public static function getStats(): array {
        $apcuInfo = function_exists('apcu_cache_info') ? apcu_cache_info() : [];
        
        return [
            'static_cache_size' => count(self::$staticCache),
            'apcu_available' => function_exists('apcu_exists'),
            'apcu_info' => $apcuInfo,
            'cache_keys' => array_keys(self::$staticCache)
        ];
    }
}

/**
 * BigQuery Utilities
 * 共通ユーティリティ機能
 */
class BigQueryUtils {
    /**
     * プロジェクトID検証
     * @param string $projectId
     * @return bool
     */
    public static function validateProjectId(string $projectId): bool {
        return preg_match('/^[a-z0-9][a-z0-9\\-]{4,28}[a-z0-9]$/i', $projectId) && 
               strlen($projectId) <= 30;
    }

    /**
     * 識別子をエスケープ
     * @param string $identifier
     * @return string
     */
    public static function escapeIdentifier(string $identifier): string {
        return "`" . str_replace("`", "``", $identifier) . "`";
    }

    /**
     * クエリを安全にログ出力
     * @param string $query
     * @param string $context
     * @return void
     */
    public static function logQuerySafely(string $query, string $context = "QUERY"): void {
        // セキュリティ情報のサニタイズ処理をまとめて実行
        $sanitizers = [
            '/([\\\'"])[^\\\'\"]*\\1/' => '$1***REDACTED***$1',
            '/\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Z|a-z]{2,}\\b/' => '***EMAIL_REDACTED***'
        ];

        $safeQuery = preg_replace(array_keys($sanitizers), array_values($sanitizers), $query);
        
        if (strlen($safeQuery) > 200) {
            $safeQuery = substr($safeQuery, 0, 200) . '... [TRUNCATED]';
        }
        
        error_log("BigQuery $context: $safeQuery");
    }

    /**
     * WHERE条件をBigQuery形式に変換
     * @param string $condition
     * @return string
     * @throws InvalidArgumentException
     */
    public static function convertWhereCondition(string $condition): string {
        if (!is_string($condition) || strlen($condition) > 1000) {
            throw new InvalidArgumentException('Invalid WHERE condition format');
        }

        if (BigQueryConfig::isDangerousQuery($condition)) {
            error_log("BigQuery: Blocked suspicious WHERE condition: " . substr($condition, 0, 100) . "...");
            throw new InvalidArgumentException('WHERE condition contains prohibited SQL patterns');
        }

        // MySQL形式バッククォートをBigQuery形式に変換
        $condition = preg_replace('/`([^`]+)`/', '`$1`', $condition);
        
        // 文字列リテラルの安全なエスケープ
        return preg_replace_callback("/'([^']*)'/", function($matches) {
            $escaped = str_replace("'", "\\'", $matches[1]);
            $escaped = str_replace("\\", "\\\\", $escaped);
            return "'" . $escaped . "'";
        }, $condition);
    }

    /**
     * 複雑な型データを表示用に変換（統一された型処理）
     * @param mixed $value
     * @param array $field
     * @return mixed
     */
    public static function formatComplexValue($value, array $field) {
        if ($value === null) {
            return null;
        }

        $fieldType = strtolower($field['type'] ?? 'text');

        // 型別処理のMap化（効率化とメンテナンス性向上）
        $typePatterns = [
            'json' => ['json', 'struct', 'record', 'array'],
            'geography' => ['geography'],
            'binary' => ['bytes', 'blob'],
        ];

        foreach ($typePatterns as $handlerType => $patterns) {
            if (self::matchesTypePattern($fieldType, $patterns)) {
                return self::handleTypeConversion($value, $handlerType);
            }
        }

        return $value;
    }

    /**
     * 型パターンマッチング
     * @param string $fieldType
     * @param array $patterns
     * @return bool
     */
    private static function matchesTypePattern(string $fieldType, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (strpos($fieldType, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 型変換処理（統一化）
     * @param mixed $value
     * @param string $handlerType
     * @return mixed
     */
    private static function handleTypeConversion($value, string $handlerType) {
        switch ($handlerType) {
            case 'json':
                return is_string($value) && (substr($value, 0, 1) === '{' || substr($value, 0, 1) === '[')
                    ? $value : json_encode($value);
            
            case 'geography':
            case 'binary':
                return is_string($value) ? $value : (string)$value;
                
            default:
                return $value;
        }
    }

    /**
     * フィールド変換用SQL生成（統一化）
     * @param array $field
     * @return string|null
     */
    public static function generateFieldConversion(array $field): ?string {
        $fieldName = self::escapeIdentifier($field['field']);
        $fieldType = strtolower($field['type'] ?? '');

        // 変換が必要な型のマッピング
        $conversions = [
            'geography' => "ST_AsText($fieldName)",
            'json' => "TO_JSON_STRING($fieldName)"
        ];

        foreach ($conversions as $typePattern => $conversion) {
            if (strpos($fieldType, $typePattern) !== false) {
                return $conversion;
            }
        }

        return null;
    }
}

/**
 * BigQuery database connection handler
 */
class Db {
    /** @var Db */ 
    static $instance;

    /** @var BigQueryClient */
    public $bigQueryClient;

    /** @var string Current project ID */
    public $projectId;

    /** @var string Current dataset ID */
    public $datasetId = '';

    /** @var array Connection configuration */
    public $config = [];

    /** @var string Database flavor/version info */
    public $flavor = 'BigQuery';

    /** @var string Server version info */
    public $server_info = 'Google Cloud BigQuery';

    /** @var string Extension name */
    public $extension = 'BigQuery Driver';

    /**
     * Establish connection to BigQuery
     *
     * @param string $server GCP Project ID (optionally with location)
     * @param string $username Not used for BigQuery
     * @param string $password Not used for BigQuery
     * @return bool Connection success
     */
    public function connect($server, $username, $password) {
        
        try {
            // 1. プロジェクトIDの検証と解析
            $this->projectId = $this->validateAndParseProjectId($server);
            
            // 2. 位置情報の決定
            $location = $this->determineLocation($server, $this->projectId);
            
            // 3. 認証設定の取得
            $credentialsPath = $this->getCredentialsPath();
            if (!$credentialsPath) {
                throw new Exception('BigQuery authentication not configured. Set GOOGLE_APPLICATION_CREDENTIALS environment variable or provide credentials file path.');
            }

            // 4. 設定の初期化
            $this->initializeConfiguration($location);

            // 5. BigQueryクライアントの作成
            $this->createBigQueryClient($credentialsPath, $location);

            // 6. バックグラウンド位置検出のスケジューリング
            if (!$this->isLocationExplicitlySet($server)) {
                $this->scheduleLocationDetection($this->projectId, $location);
            }

            error_log("BigQuery: Connected to project '{$this->projectId}' with location '{$this->config['location']}'");

            return true;

        } catch (ServiceException $e) {
            $this->logConnectionError($e, 'ServiceException');
            return false;
        } catch (Exception $e) {
            $this->logConnectionError($e, 'Exception');
            return false;
        }
    }

    /**
     * プロジェクトIDの検証と解析
     * @param string $server
     * @return string
     * @throws Exception
     */
    private function validateAndParseProjectId($server) {
        if (empty($server)) {
            throw new Exception('Project ID is required');
        }

        $parts = explode(':', $server);
        $projectId = trim($parts[0]);

        if (!BigQueryUtils::validateProjectId($projectId)) {
            throw new Exception('Invalid GCP Project ID format');
        }

        return $projectId;
    }

    /**
     * 位置情報の決定
     * @param string $server
     * @param string $projectId
     * @return string
     */
    private function determineLocation($server, $projectId) {
        $parts = explode(':', $server);
        
        // 1. サーバーパラメータから位置を取得
        if (isset($parts[1]) && !empty($parts[1])) {
            return $parts[1];
        }
        
        // 2. 環境変数から取得
        if (getenv('BIGQUERY_LOCATION')) {
            return getenv('BIGQUERY_LOCATION');
        }
        
        // 3. キャッシュから取得
        $cachedLocation = $this->getCachedLocation($projectId);
        if ($cachedLocation) {
            error_log("BigQuery: Using cached location '$cachedLocation' for project '$projectId'");
            return $cachedLocation;
        }
        
        // 4. デフォルト
        return 'US';
    }

    /**
     * 位置が明示的に設定されているかチェック
     * @param string $server
     * @return bool
     */
    private function isLocationExplicitlySet($server) {
        return strpos($server, ':') !== false || getenv('BIGQUERY_LOCATION');
    }

    /**
     * 設定の初期化
     * @param string $location
     */
    private function initializeConfiguration($location) {
        $this->config = [
            'projectId' => $this->projectId,
            'location' => $location
        ];
    }

    /**
     * BigQueryクライアントの作成
     * @param string $credentialsPath
     * @param string $location
     */
    private function createBigQueryClient($credentialsPath, $location) {
        $clientKey = md5($this->projectId . $credentialsPath . $location);
        $this->bigQueryClient = BigQueryConnectionPool::getConnection($clientKey, [
            'projectId' => $this->projectId,
            'location' => $location,
            'credentialsPath' => $credentialsPath
        ]);

        error_log("BigQuery: Using connection pool for project '{$this->projectId}' (key: " . substr($clientKey, 0, 8) . "...)");
    }

    /**
     * 接続エラーのログ出力
     * @param Exception $e
     * @param string $type
     */
    private function logConnectionError($e, $type) {
        $errorMessage = $e->getMessage();
        $safeMessage = preg_replace('/project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+/i', 'project: [REDACTED]', $errorMessage);
        error_log("BigQuery $type: " . $safeMessage);
        
        // 特定エラーの診断情報
        if (strpos($errorMessage, 'UNAUTHENTICATED') !== false || strpos($errorMessage, '401') !== false) {
            error_log("BigQuery: Authentication failed. Check service account credentials.");
        } elseif (strpos($errorMessage, 'OpenSSL') !== false) {
            error_log("BigQuery: Invalid private key in service account file.");
        }
    }

    /**
     * 永続キャッシュから位置情報を取得
     *
     * @param string $projectId プロジェクトID
     * @return string|null キャッシュされた位置情報
     */
    private function getCachedLocation($projectId) {
        $cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
        
        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData && isset($cacheData['location']) && isset($cacheData['expires'])) {
                if (time() < $cacheData['expires']) {
                    return $cacheData['location'];
                } else {
                    @unlink($cacheFile);
                }
            }
        }
        
        return null;
    }

    /**
     * 位置情報をキャッシュに保存
     *
     * @param string $projectId プロジェクトID
     * @param string $location 位置情報
     */
    private function setCachedLocation($projectId, $location) {
        $cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
        $cacheData = [
            'location' => $location,
            'expires' => time() + 86400  // 24時間後
        ];
        
        @file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
    }

    /**
     * 認証パス取得の最適化（キャッシュ付き）
     *
     * @return string|null 認証ファイルパス
     * @throws Exception 認証設定エラー
     */
    private function getCredentialsPath() {
        static $credentialsCache = null;
        static $lastCheckTime = 0;
        
        // キャッシュが有効な場合（10秒間）
        if ($credentialsCache !== null && (time() - $lastCheckTime) < 10) {
            return $credentialsCache;
        }

        $customCredentialsPath = $_POST['auth']['credentials'] ?? null;
        $credentialsPath = null;
        
        if ($customCredentialsPath && !empty($customCredentialsPath)) {
            $credentialsPath = $customCredentialsPath;
            putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $credentialsPath);
            $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;
        } else {
            $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        }

        if (!$credentialsPath && !getenv('GOOGLE_CLOUD_PROJECT')) {
            $credentialsCache = null;
            $lastCheckTime = time();
            return null;
        }
        
        if ($credentialsPath) {
            $this->validateCredentialsFile($credentialsPath);
        }
        
        $credentialsCache = $credentialsPath;
        $lastCheckTime = time();
        
        return $credentialsPath;
    }

    /**
     * 認証ファイルの検証
     * @param string $credentialsPath
     * @throws Exception
     */
    private function validateCredentialsFile($credentialsPath) {
        $fileInfo = @stat($credentialsPath);
        if ($fileInfo === false) {
            throw new Exception("Service account file not found: {$credentialsPath}");
        }
        
        if (!($fileInfo['mode'] & 0444)) {
            throw new Exception("Service account file not readable: {$credentialsPath}");
        }
    }

    /**
     * 非ブロッキング位置検出のスケジュール（最適化版）
     *
     * @param string $projectId プロジェクトID
     * @param string $defaultLocation デフォルト位置
     */
    private function scheduleLocationDetection($projectId, $defaultLocation) {
        if ($this->getCachedLocation($projectId)) {
            return;
        }

        $detectionFunc = function() use ($projectId, $defaultLocation) {
            $this->performLightweightLocationDetection($projectId, $defaultLocation);
        };

        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function() use ($detectionFunc) {
                fastcgi_finish_request();
                $detectionFunc();
            });
        } else {
            register_shutdown_function($detectionFunc);
        }
    }

    /**
     * 軽量な位置検出処理（非ブロッキング用）
     *
     * @param string $projectId プロジェクトID
     * @param string $defaultLocation デフォルト位置
     */
    private function performLightweightLocationDetection($projectId, $defaultLocation) {
        try {
            $datasets = $this->bigQueryClient->datasets(['maxResults' => 1]);
            
            foreach ($datasets as $dataset) {
                try {
                    $datasetInfo = $dataset->info();
                    $detectedLocation = $datasetInfo['location'] ?? $defaultLocation;
                    
                    if ($detectedLocation !== $defaultLocation) {
                        $this->config['location'] = $detectedLocation;
                        $this->setCachedLocation($projectId, $detectedLocation);
                        error_log("BigQuery: Ultra-fast location detection: '$detectedLocation' for project '$projectId'");
                    }
                    
                    break;
                    
                } catch (Exception $e) {
                    error_log("BigQuery: Lightweight location detection failed: " . $e->getMessage());
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("BigQuery: Background location detection failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a SELECT query
     *
     * @param string $query SQL query to execute
     * @return Result|false Query result or false on error
     */
    public function query($query) {
        try {
            $this->validateReadOnlyQuery($query);
            $queryLocation = $this->determineQueryLocation();
            
            $queryJob = $this->bigQueryClient->query($query)
                ->useLegacySql(false)
                ->location($queryLocation)
                ->useQueryCache(true);

            $job = $this->bigQueryClient->runQuery($queryJob);

            if (!$job->isComplete()) {
                $job->waitUntilComplete();
            }

            $this->checkJobStatus($job);
            
            error_log("BigQuery: Query executed successfully in location '$queryLocation'");
            return new Result($job);

        } catch (ServiceException $e) {
            BigQueryUtils::logQuerySafely($e->getMessage(), 'SERVICE_ERROR');
            return false;
        } catch (Exception $e) {
            BigQueryUtils::logQuerySafely($e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * ジョブステータスのチェック
     * @param object $job
     * @throws Exception
     */
    private function checkJobStatus($job) {
        $jobInfo = $job->info();
        if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
            $errorResult = $jobInfo['status']['errorResult'] ?? null;
            if ($errorResult) {
                throw new Exception("BigQuery job failed: " . ($errorResult['message'] ?? 'Unknown error'));
            }
        }
    }

    /**
     * Validate if query is safe for read-only access
     *
     * @param string $query SQL query to validate
     * @return bool True if query is safe
     * @throws Exception If query contains unsafe operations
     */
    private function validateReadOnlyQuery($query) {
        $cleanQuery = preg_replace('/--.*$/m', '', $query);
        $cleanQuery = preg_replace('/\/\*.*?\*\//s', '', $cleanQuery);
        $cleanQuery = trim($cleanQuery);

        if (!preg_match('/^\s*SELECT\s+/i', $cleanQuery)) {
            throw new Exception('Only SELECT queries are supported in read-only mode');
        }

        $dangerousPatterns = [
            '/\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE)\b/i',
            '/\b(GRANT|REVOKE)\b/i',
            '/\bCALL\s+/i',
            '/\bEXEC(UTE)?\s+/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $cleanQuery)) {
                throw new Exception('DDL/DML operations are not allowed in read-only mode');
            }
        }

        return true;
    }

    /**
     * Determine the best location for query execution
     *
     * @return string Location to use for query execution
     */
    private function determineQueryLocation() {
        // 優先順位：選択されたデータセット位置 > 設定位置 > 環境変数 > US
        if (!empty($this->datasetId)) {
            try {
                $dataset = $this->bigQueryClient->dataset($this->datasetId);
                $datasetInfo = $dataset->info();
                $datasetLocation = $datasetInfo['location'] ?? null;
                
                if ($datasetLocation && $datasetLocation !== ($this->config['location'] ?? '')) {
                    error_log("BigQuery: Using dataset location '$datasetLocation' for query execution");
                    $this->config['location'] = $datasetLocation;
                    return $datasetLocation;
                }
            } catch (Exception $e) {
                error_log("BigQuery: Failed to get dataset location, falling back to config location");
            }
        }

        return $this->config['location'] ?? 'US';
    }

    /**
     * Select a dataset (database in Adminer terms)
     *
     * @param string $database Dataset ID
     * @return bool Success
     */
    public function select_db($database) {
        try {
            $dataset = $this->bigQueryClient->dataset($database);
            $dataset->reload();
            
            $datasetInfo = $dataset->info();
            $datasetLocation = $datasetInfo['location'] ?? 'US';
            
            $previousLocation = $this->config['location'] ?? 'US';
            if ($datasetLocation !== $previousLocation) {
                error_log("BigQuery: Dataset '$database' is in location '$datasetLocation', updating connection from '$previousLocation'");
                $this->config['location'] = $datasetLocation;
            }
            
            $this->datasetId = $database;
            error_log("BigQuery: Successfully selected dataset '$database' in location '$datasetLocation'");
            return true;
            
        } catch (ServiceException $e) {
            $this->logDatasetError($e, $database);
            return false;
        } catch (Exception $e) {
            BigQueryUtils::logQuerySafely($e->getMessage(), 'DATASET_ERROR');
            return false;
        }
    }

    /**
     * データセットエラーのログ出力
     * @param ServiceException $e
     * @param string $database
     */
    private function logDatasetError($e, $database) {
        $message = $e->getMessage();
        if (strpos($message, '404') !== false || strpos($message, 'Not found') !== false) {
            error_log("BigQuery: Dataset '$database' not found in project '{$this->projectId}'");
        } elseif (strpos($message, 'permission') !== false || strpos($message, '403') !== false) {
            error_log("BigQuery: Access denied to dataset '$database'");
        } else {
            BigQueryUtils::logQuerySafely($message, 'DATASET_ERROR');
        }
    }

    /**
     * Quote identifier for BigQuery
     *
     * @param string $idf Identifier to quote
     * @return string Quoted identifier
     */
    public function quote($idf) {
        return BigQueryUtils::escapeIdentifier($idf);
    }

    /**
     * Get last error message
     *
     * @return string Error message
     */
    public function error() {
        return "Check server logs for detailed error information";
    }
}

/**
 * Query result wrapper for BigQuery
 */
class Result {
    /** @var \Google\Cloud\BigQuery\QueryResults */
    private $queryResults;

    /** @var array Current row data */
    private $currentRow = null;

    /** @var int Current row number */
    private $rowNumber = 0;

    /** @var array Field information cache */
    private $fieldsCache = null;

    /** @var Iterator Result iterator */
    private $iterator = null;

    /** @var bool Is iterator initialized */
    private $isIteratorInitialized = false;

    public function __construct($queryResults) {
        $this->queryResults = $queryResults;
    }

    /**
     * Fetch next row as associative array
     *
     * @return array|false Row data or false if no more rows
     */
    public function fetch_assoc() {
        try {
            if (!$this->isIteratorInitialized) {
                $this->iterator = $this->queryResults->getIterator();
                $this->isIteratorInitialized = true;
            }

            if ($this->iterator && $this->iterator->valid()) {
                $row = $this->iterator->current();
                $this->iterator->next();
                
                // Convert array values to strings for Adminer compatibility
                $processedRow = [];
                foreach ($row as $key => $value) {
                    if (is_array($value)) {
                        // Convert complex types (STRUCT, ARRAY, etc.) to JSON string
                        $processedRow[$key] = json_encode($value);
                    } elseif (is_object($value)) {
                        // Handle DateTime objects specifically
                        if ($value instanceof \DateTime) {
                            $processedRow[$key] = $value->format('Y-m-d H:i:s');
                        } elseif ($value instanceof \DateTimeInterface) {
                            $processedRow[$key] = $value->format('Y-m-d H:i:s');
                        } elseif (method_exists($value, 'format')) {
                            // For Google Cloud DateTime objects that have format method
                            try {
                                $processedRow[$key] = $value->format('Y-m-d H:i:s');
                            } catch (Exception $e) {
                                // Fallback to string conversion
                                $processedRow[$key] = (string) $value;
                            }
                        } elseif (method_exists($value, '__toString')) {
                            // Use __toString if available
                            $processedRow[$key] = (string) $value;
                        } else {
                            // Last resort: serialize or convert to string representation
                            $processedRow[$key] = json_encode($value);
                        }
                    } elseif (is_null($value)) {
                        // Keep null as is
                        $processedRow[$key] = null;
                    } else {
                        // Keep scalar values as is
                        $processedRow[$key] = $value;
                    }
                }
                
                $this->currentRow = $processedRow;
                $this->rowNumber++;
                return $processedRow;
            }
            return false;
        } catch (Exception $e) {
            error_log("Result fetch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch next row as indexed array
     *
     * @return array|false Row data or false if no more rows
     */
    public function fetch_row() {
        $assoc = $this->fetch_assoc();
        return $assoc ? array_values($assoc) : false;
    }

    /**
     * Get number of fields in result
     *
     * @return int Number of fields
     */
    public function num_fields() {
        if ($this->fieldsCache === null) {
            $this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? [];
        }
        return count($this->fieldsCache);
    }

    /**
     * Get field information
     *
     * @param int $offset Field offset
     * @return object|false Field information
     */
    public function fetch_field($offset = 0) {
        if ($this->fieldsCache === null) {
            $this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? [];
        }

        if (!isset($this->fieldsCache[$offset])) {
            return false;
        }

        $field = $this->fieldsCache[$offset];

        // Create field object compatible with Adminer
        return (object) [
            'name' => $field['name'],
            'type' => $this->mapBigQueryType($field['type']),
            'length' => null,
            'flags' => ($field['mode'] ?? 'NULLABLE') === 'REQUIRED' ? 'NOT NULL' : ''
        ];
    }

    /**
     * Map BigQuery data types to MySQL-compatible types
     *
     * @param string $bigQueryType BigQuery field type
     * @return string Compatible type name
     */
    private function mapBigQueryType($bigQueryType) {
        $typeMap = [
            'STRING' => 'varchar',
            'INT64' => 'bigint',
            'INTEGER' => 'bigint',
            'FLOAT64' => 'double',
            'FLOAT' => 'double',
            'BOOL' => 'boolean',
            'BOOLEAN' => 'boolean',
            'NUMERIC' => 'decimal',
            'BIGNUMERIC' => 'decimal',
            'DATETIME' => 'datetime',
            'DATE' => 'date',
            'TIME' => 'time',
            'TIMESTAMP' => 'timestamp',
            'JSON' => 'json',
            'GEOGRAPHY' => 'text',
            'BYTES' => 'blob',
            'RECORD' => 'text',
            'STRUCT' => 'text',
            'ARRAY' => 'text'
        ];

        return $typeMap[strtoupper($bigQueryType)] ?? 'text';
    }
}

/**
 * Main BigQuery driver class
 */
class Driver {
    /** @var Driver */
    static $instance;

    /** @var array Supported file extensions */
    static $extensions = ["BigQuery"];

    /** @var string Syntax highlighting identifier */
    static $jush = "sql";

    /** @var array Supported operators */
    static $operators = array(
        "=", "!=", "<>", "<", "<=", ">", ">=",
        "IN", "NOT IN", "IS NULL", "IS NOT NULL",
        "LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
    );

    /**
     * Connect to BigQuery
     *
     * @param string $server Project ID
     * @param string $username Not used
     * @param string $password Not used
     * @return Db|false Database connection
     */
    static function connect($server, $username, $password) {
        $db = new Db();
        if ($db->connect($server, $username, $password)) {
            return $db;
        }
        return false;
    }

    /**
     * Get table help URL (not applicable for BigQuery)
     *
     * @param string $name Table name
     * @param bool $is_view Whether table is a view
     * @return string|null Help URL or null if not available
     */
    function tableHelp($name, $is_view = false) {
        // BigQuery doesn't have built-in help URLs in Adminer
        return null;
    }

    /**
     * Get structured types (not applicable for BigQuery)
     *
     * @return array Empty array as BigQuery doesn't use traditional structured types
     */
    function structuredTypes() {
        return [];
    }

    /**
     * Check inheritance relationship (not applicable for BigQuery)
     *
     * @param string $table Table name
     * @return array Empty array as BigQuery doesn't support table inheritance
     */
    function inheritsFrom($table) {
        return [];
    }

    /**
     * Get tables that inherit from the given table (not applicable for BigQuery)
     *
     * @param string $table Table name
     * @return array Empty array as BigQuery doesn't support table inheritance
     */
    function inheritedTables($table) {
        return [];
    }

    /**
     * Execute a SELECT query (delegating to global function)
     * This method is required by Adminer's Driver interface
     *
     * @param string $table Table name
     * @param array $select Selected columns (* for all)
     * @param array $where WHERE conditions
     * @param array $group GROUP BY columns  
     * @param array $order ORDER BY specifications
     * @param int $limit LIMIT count
     * @param int $page Page number (for OFFSET calculation)
     * @param bool $print Whether to print query
     * @return Result|false Query result or false on error
     */
    function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
        // Delegate to the global select function which contains all the enhanced security and validation
        return select($table, $select, $where, $group, $order, $limit, $page, $print);
    }


    /**
     * Format field value for display and processing
     *
     * @param mixed $val Field value
     * @param array $field Field information
     * @return mixed Formatted value
     */
    function value($val, array $field) {
        // 統一された型処理を使用
        return BigQueryUtils::formatComplexValue($val, $field);
    }

	/** Convert field in select and edit
	* @param array $field
	* @return string|void
	*/
	function convert_field(array $field) {
		// 統一されたフィールド変換処理を使用
		return BigQueryUtils::generateFieldConversion($field);
	}

	// Removed idf_escape method - using namespace-level function instead
}

/**
 * Escape identifier for BigQuery
 *
 * @param string $idf Identifier to escape
 * @return string Escaped identifier
 */
function idf_escape($idf) {
    return "`" . str_replace("`", "``", $idf) . "`";
}

// Global support function for BigQuery features
function support($feature) {
    // Define supported features for READ-ONLY MVP
    $supportedFeatures = [
        'database', 'table', 'columns', 'sql', 'view', 'materializedview'
    ];

    // Features explicitly not supported
    $unsupportedFeatures = [
        'foreignkeys', 'indexes', 'processlist', 'kill', 'transaction',
        'comment', 'drop_col', 'dump', 'event', 'move_col', 'privileges',
        'procedure', 'routine', 'sequence', 'status', 'trigger',
        'type', 'variables', 'descidx', 'check', 'schema'
    ];

    if (in_array($feature, $supportedFeatures)) {
        return true;
    }

    if (in_array($feature, $unsupportedFeatures)) {
        return false;
    }

    // Default to false for unknown features
    return false;
}

/**
 * Get supported SQL operators for BigQuery
 *
 * @return array List of supported operators
 */
function operators() {
    return array(
        "=", "!=", "<>", "<", "<=", ">", ">=",
        "IN", "NOT IN", "IS NULL", "IS NOT NULL",
        "LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
    );
}

/**
 * Get available collations (BigQuery doesn't use collations like traditional SQL databases)
 * 
 * @return array Empty array as BigQuery handles collation automatically
 */
function collations() {
    // BigQuery handles collation automatically based on data types and locale settings
    // Return empty array as traditional collation management is not applicable
    return array();
}

/**
 * Get database collation (not applicable for BigQuery)
 * 
 * @param string $db Database name
 * @return string Empty string as BigQuery doesn't use collations
 */
function db_collation($db) {
    // BigQuery does not use traditional database collations
    return "";
}

/**
 * Get information schema database name (not applicable for BigQuery)
 * 
 * @param string $db Database name  
 * @return string Empty string as BigQuery doesn't have information_schema like traditional databases
 */
function information_schema($db) {
    // BigQuery has its own metadata structure, not information_schema
    return "";
}

/**
 * Check if a table is a view (BigQuery has views, tables, and materialized views)
 * 
 * @param array $table_status Table status information
 * @return bool True if table is a view
 */
function is_view($table_status) {
    // In BigQuery context, check if table type indicates it's a view
    return isset($table_status["Engine"]) && 
           (strtolower($table_status["Engine"]) === "view" || 
            strtolower($table_status["Engine"]) === "materialized view");
}

/**
 * Check if foreign key support is available (BigQuery doesn't support foreign keys)
 * 
 * @param array $table_status Table status information
 * @return bool False as BigQuery doesn't support foreign keys
 */
function fk_support($table_status) {
    // BigQuery does not support foreign keys
    return false;
}

/**
 * Get table indexes (BigQuery doesn't use traditional indexes)
 * 
 * @param string $table Table name
 * @param mixed $connection2 Optional connection parameter
 * @return array Empty array as BigQuery doesn't support traditional indexes
 */
function indexes($table, $connection2 = null) {
    // BigQuery does not use traditional database indexes
    return [];
}

/**
 * Get foreign keys for a table (BigQuery doesn't support foreign keys)
 * 
 * @param string $table Table name
 * @return array Empty array as BigQuery doesn't support foreign keys
 */
function foreign_keys($table) {
    // BigQuery does not support foreign keys
    return [];
}

// Global functions for database operations
function logged_user() {
    return "BigQuery Service Account";
}

function get_databases($flush = false) {
    global $connection;
    
    // 【永続キャッシュ】APCuによるプロセス間キャッシュ共有
    $cacheKey = 'bq_databases_' . ($connection ? $connection->projectId : 'default');
    $cacheTime = 300; // 5分間キャッシュ
    
    // APCuキャッシュから取得を試行
    if (!$flush && function_exists('apcu_exists') && apcu_exists($cacheKey)) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            error_log("get_databases: Using APCu cached result (" . count($cached) . " datasets)");
            return $cached;
        }
    }
    
    // 【フォールバック】静的キャッシュ
    static $staticCache = [];
    $staticKey = $connection ? $connection->projectId : 'default';
    
    if (!$flush && isset($staticCache[$staticKey])) {
        error_log("get_databases: Using static cached result (" . count($staticCache[$staticKey]) . " datasets)");
        return $staticCache[$staticKey];
    }

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        // 【最適化】maxResultsで制限して大量取得を避ける
        $datasets = [];
        $datasetsIterator = $connection->bigQueryClient->datasets([
            'maxResults' => 100  // 一度に大量取得を避ける
        ]);
        
        foreach ($datasetsIterator as $dataset) {
            $datasets[] = $dataset->id();
        }
        sort($datasets);
        
        // 【永続キャッシュ】APCuに保存
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $datasets, $cacheTime);
            error_log("get_databases: Stored to APCu cache (" . count($datasets) . " datasets)");
        }
        
        // 静的キャッシュにも保存（フォールバック用）
        $staticCache[$staticKey] = $datasets;
        
        error_log("get_databases: Retrieved and cached " . count($datasets) . " datasets");

        return $datasets;
    } catch (Exception $e) {
        error_log("Error listing datasets: " . $e->getMessage());
        return [];
    }
}


function tables_list($database = '') {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        // In BigQuery context: $database = dataset name
        $actualDatabase = '';
        
        if (!empty($database)) {
            $actualDatabase = $database;
        } else {
            // Try to get from URL parameters or connection
            $actualDatabase = $_GET['db'] ?? $connection->datasetId ?? '';
        }
        
        if (empty($actualDatabase)) {
            error_log("tables_list: No database (dataset) context available");
            return [];
        }
        
        // 【永続キャッシュ】APCuによるプロセス間キャッシュ共有
        $cacheKey = 'bq_tables_' . $connection->projectId . '_' . $actualDatabase;
        $cacheTime = 300; // 5分間キャッシュ
        
        // APCuキャッシュから取得を試行
        if (function_exists('apcu_exists') && apcu_exists($cacheKey)) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                error_log("tables_list: Using APCu cached result for dataset '$actualDatabase' (" . count($cached) . " tables)");
                return $cached;
            }
        }
        
        // 【フォールバック】静的キャッシュ
        static $staticCache = [];
        if (isset($staticCache[$actualDatabase])) {
            error_log("tables_list: Using static cached result for dataset '$actualDatabase' (" . count($staticCache[$actualDatabase]) . " tables)");
            return $staticCache[$actualDatabase];
        }
        
        error_log("tables_list called with database: '$database', using actual: '$actualDatabase'");
        
        $dataset = $connection->bigQueryClient->dataset($actualDatabase);
        $tables = [];

        // 【最適化】ページネーション付きで取得してN+1問題を回避
        $pageToken = null;
        do {
            $options = ['maxResults' => 100];
            if ($pageToken) {
                $options['pageToken'] = $pageToken;
            }

            $result = $dataset->tables($options);
            foreach ($result as $table) {
                $tables[$table->id()] = 'table';
            }
            $pageToken = $result->nextResultToken();
        } while ($pageToken);

        // 【永続キャッシュ】APCuに保存
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $tables, $cacheTime);
            error_log("tables_list: Stored to APCu cache (" . count($tables) . " tables)");
        }
        
        // 静的キャッシュにも保存（フォールバック用）
        $staticCache[$actualDatabase] = $tables;
        
        error_log("tables_list: Retrieved and cached " . count($tables) . " tables for dataset '$actualDatabase'");

        return $tables;
    } catch (Exception $e) {
        error_log("Error listing tables for database '$database' (actual: '$actualDatabase'): " . $e->getMessage());
        return [];
    }
}

function table_status($name = '', $fast = false) {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        error_log("table_status: No connection available, returning empty array");
        return [];
    }

    try {
        // Get the dataset from URL parameter
        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        
        if (empty($database)) {
            error_log("table_status: No database (dataset) context available, returning empty array");
            return [];
        }
        
        error_log("table_status called with name param: '$name', fast: " . ($fast ? 'true' : 'false') . ", using database: '$database'");
        
        $dataset = $connection->bigQueryClient->dataset($database);
        $tables = [];

        if ($name) {
            // Get info for specific table only - return as indexed array for Adminer compatibility
            try {
                $table = $dataset->table($name);
                $tableInfo = $table->info();
                $result = [
                    'Name' => $table->id(),
                    'Engine' => 'BigQuery',
                    'Rows' => $tableInfo['numRows'] ?? 0,
                    'Data_length' => $tableInfo['numBytes'] ?? 0,
                    'Comment' => $tableInfo['description'] ?? '',
                    'Type' => $tableInfo['type'] ?? 'TABLE',
                    // Add additional fields that Adminer may expect
                    'Collation' => '',
                    'Auto_increment' => '',
                    'Create_time' => $tableInfo['creationTime'] ?? '',
                    'Update_time' => $tableInfo['lastModifiedTime'] ?? '',
                    'Check_time' => '',
                    'Data_free' => 0,
                    'Index_length' => 0,
                    'Max_data_length' => 0,
                    'Avg_row_length' => $tableInfo['numRows'] > 0 ? intval(($tableInfo['numBytes'] ?? 0) / $tableInfo['numRows']) : 0,
                ];
                // Return as indexed array with table name as key for Adminer compatibility
                $tables[$table->id()] = $result;
                error_log("table_status: returning specific table '$name' info as indexed array");
            } catch (Exception $e) {
                error_log("Error getting specific table '$name' info: " . $e->getMessage() . ", returning empty array");
                return [];
            }
        } else {
            // Get info for all tables in the dataset (but limit to fast fields if $fast = true)
            foreach ($dataset->tables() as $table) {
                $tableInfo = $table->info();
                $result = [
                    'Name' => $table->id(),
                    'Engine' => 'BigQuery', 
                    'Comment' => $tableInfo['description'] ?? '',
                ];
                
                // Add additional fields only if not in fast mode
                if (!$fast) {
                    $result += [
                        'Rows' => $tableInfo['numRows'] ?? 0,
                        'Data_length' => $tableInfo['numBytes'] ?? 0,
                        'Type' => $tableInfo['type'] ?? 'TABLE',
                        'Collation' => '',
                        'Auto_increment' => '',
                        'Create_time' => $tableInfo['creationTime'] ?? '',
                        'Update_time' => $tableInfo['lastModifiedTime'] ?? '',
                        'Check_time' => '',
                        'Data_free' => 0,
                        'Index_length' => 0,
                        'Max_data_length' => 0,
                        'Avg_row_length' => $tableInfo['numRows'] > 0 ? intval(($tableInfo['numBytes'] ?? 0) / $tableInfo['numRows']) : 0,
                    ];
                }
                
                // Use table name as key for Adminer compatibility
                $tables[$table->id()] = $result;
            }
            error_log("table_status: returning " . count($tables) . " tables as indexed array (fast: " . ($fast ? 'true' : 'false') . ")");
        }

        // Ensure we always return an array, never null
        $result = is_array($tables) ? $tables : [];
        error_log("table_status: final result type: " . gettype($result) . ", count: " . count($result) . ", keys: " . implode(',', array_keys($result)));
        return $result;
        
    } catch (Exception $e) {
        error_log("Error getting table status for name '$name' (database: '$database'): " . $e->getMessage() . ", returning empty array");
        return [];
    }
}


/**
 * Convert Adminer WHERE condition format to BigQuery SQL format
 * Enhanced with security validations to prevent SQL injection
 *
 * @param string $condition Adminer WHERE condition (e.g., "`column` = 'value'")
 * @return string BigQuery compatible WHERE condition
 * @throws InvalidArgumentException If condition contains suspicious patterns
 */
function convertAdminerWhereToBigQuery($condition) {
    // Input validation for security
    if (!is_string($condition)) {
        throw new InvalidArgumentException('WHERE condition must be a string');
    }
    
    if (strlen($condition) > 1000) {
        throw new InvalidArgumentException('WHERE condition exceeds maximum length');
    }
    
    // Check for suspicious SQL injection patterns
    $suspiciousPatterns = [
        '/;\s*(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\s+/i',
        '/UNION\s+(ALL\s+)?SELECT/i',
        '/\/\*.*?\*\//i', // Block comments
        '/--[^\r\n]*/i',  // Line comments
        '/\bEXEC\b/i',
        '/\bEXECUTE\b/i',
        '/\bSP_/i'
    ];
    
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $condition)) {
            error_log("BigQuery: Blocked suspicious WHERE condition pattern: " . substr($condition, 0, 100) . "...");
            throw new InvalidArgumentException('WHERE condition contains prohibited SQL patterns');
        }
    }
    
    // Handle basic operators and quoted identifiers
    // Convert MySQL-style backticks to BigQuery format if needed
    $condition = preg_replace('/`([^`]+)`/', '`$1`', $condition);
    
    // Handle string literals - ensure proper escaping for BigQuery
    $condition = preg_replace_callback("/'([^']*)'/", function($matches) {
        // Additional escaping for BigQuery safety
        $escaped = str_replace("'", "\\'", $matches[1]);
        $escaped = str_replace("\\", "\\\\", $escaped);
        return "'" . $escaped . "'";
    }, $condition);
    
    return $condition;
}

// 重複削除: logQuerySafely関数 -> BigQueryUtils::logQuerySafely()に統一

// 重複削除: mapBigQueryTypeToAdminer関数 -> BigQueryConfig::mapType()に統一

function fields($table) {
    global $connection;


    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        // Get database (dataset) from URL parameters or connection
        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        
        if (empty($database)) {
            error_log("fields: No database (dataset) context available for table '$table'");
            return [];
        }

        // 【永続キャッシュ】APCuによるプロセス間キャッシュ共有
        $cacheKey = 'bq_fields_' . $connection->projectId . '_' . $database . '_' . $table;
        $cacheTime = 600; // 10分間キャッシュ（フィールド情報は変更頻度が低い）
        
        // APCuキャッシュから取得を試行
        if (function_exists('apcu_exists') && apcu_exists($cacheKey)) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                error_log("fields: Using APCu cached result for table '$table' (" . count($cached) . " fields)");
                return $cached;
            }
        }
        
        // 【フォールバック】静的キャッシュ
        static $staticFieldCache = [];
        static $typeCache = [];
        $staticKey = "$database.$table";
        
        if (isset($staticFieldCache[$staticKey])) {
            error_log("fields: Using static cached result for table '$table' (" . count($staticFieldCache[$staticKey]) . " fields)");
            return $staticFieldCache[$staticKey];
        }

        error_log("fields called for table: '$table' in database: '$database'");

        $dataset = $connection->bigQueryClient->dataset($database);
        $tableObj = $dataset->table($table);
        
        // 【最適化】不要なexists()チェックを削除し、直接info()を取得
        // テーブルが存在しない場合はExceptionでキャッチ
        try {
            $tableInfo = $tableObj->info();
        } catch (Exception $e) {
            error_log("Table '$table' does not exist in dataset '$database' or access error: " . $e->getMessage());
            return [];
        }

        if (!isset($tableInfo['schema']['fields'])) {
            error_log("No schema fields found for table '$table'");
            return [];
        }

        $fields = [];
        foreach ($tableInfo['schema']['fields'] as $field) {
            // 【最適化】型変換結果をキャッシュ
            $bigQueryType = $field['type'] ?? 'STRING';
            if (!isset($typeCache[$bigQueryType])) {
                $typeCache[$bigQueryType] = BigQueryConfig::mapType($bigQueryType);
            }
            $adminerTypeInfo = $typeCache[$bigQueryType];
            
            // Extract length information if present in BigQuery type
            $length = null;
            if (preg_match('/\((\d+(?:,\d+)?)\)/', $bigQueryType, $matches)) {
                $length = $matches[1];
            }
            
            // Build type string in Adminer expected format
            $typeStr = $adminerTypeInfo['type'];
            if ($length !== null) {
                $typeStr .= "($length)";
            } elseif (isset($adminerTypeInfo['length']) && $adminerTypeInfo['length'] !== null) {
                $typeStr .= "(" . $adminerTypeInfo['length'] . ")";
            }
            
            $fields[$field['name']] = [
                'field' => $field['name'],
                'type' => $typeStr,
                'full_type' => $typeStr,
                'null' => ($field['mode'] ?? 'NULLABLE') !== 'REQUIRED',
                'default' => null,
                'auto_increment' => false,
                'comment' => $field['description'] ?? '',
                'privileges' => ['select' => 1, 'insert' => 1, 'update' => 1, 'where' => 1, 'order' => 1]
            ];
        }

        // 【永続キャッシュ】APCuに保存
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $fields, $cacheTime);
            error_log("fields: Stored to APCu cache (" . count($fields) . " fields)");
        }
        
        // 静的キャッシュにも保存（フォールバック用）
        $staticFieldCache[$staticKey] = $fields;
        
        error_log("fields: Successfully retrieved and cached " . count($fields) . " fields for table '$table'");

        return $fields;
    } catch (Exception $e) {
        error_log("Error getting table fields for '$table': " . $e->getMessage());
        return [];
    }
}

/**
 * Global select function for BigQuery driver
 * Executes SELECT queries in Adminer
 *
 * @param string $table Table name
 * @param array $select Selected columns (* for all)
 * @param array $where WHERE conditions
 * @param array $group GROUP BY columns  
 * @param array $order ORDER BY specifications
 * @param int $limit LIMIT count
 * @param int $page Page number (for OFFSET calculation)
 * @param bool $print Whether to print query
 * @return Result|false Query result or false on error
 */
function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
    global $connection;
    
    if (!$connection || !$connection->bigQueryClient) {
        return false;
    }

    try {
        // Build BigQuery-compatible SELECT statement
        $selectClause = ($select == array("*")) ? "*" : implode(", ", array_map(function($col) {
            return "`" . str_replace("`", "``", $col) . "`";
        }, $select));

        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        if (empty($database)) {
            return false;
        }

        // Construct fully qualified table name for BigQuery
        $fullTableName = "`" . $connection->projectId . "`.`" . $database . "`.`" . $table . "`";
        
        $query = "SELECT $selectClause FROM $fullTableName";

        // Add WHERE conditions
        if (!empty($where)) {
            $whereClause = [];
            foreach ($where as $condition) {
                // Convert Adminer WHERE format to BigQuery format
                $whereClause[] = convertAdminerWhereToBigQuery($condition);
            }
            $query .= " WHERE " . implode(" AND ", $whereClause);
        }

        // Add GROUP BY
        if (!empty($group)) {
            $query .= " GROUP BY " . implode(", ", array_map(function($col) {
                return "`" . str_replace("`", "``", $col) . "`";
            }, $group));
        }

        // Add ORDER BY
        if (!empty($order)) {
            $orderClause = [];
            foreach ($order as $orderSpec) {
                // Handle "column DESC" format
                if (preg_match('/^(.+?)\s+(DESC|ASC)$/i', $orderSpec, $matches)) {
                    $orderClause[] = "`" . str_replace("`", "``", $matches[1]) . "` " . $matches[2];
                } else {
                    $orderClause[] = "`" . str_replace("`", "``", $orderSpec) . "`";
                }
            }
            $query .= " ORDER BY " . implode(", ", $orderClause);
        }

        // Add LIMIT and OFFSET
        if ($limit > 0) {
            $query .= " LIMIT " . (int)$limit;
            if ($page > 0) {
                $offset = $page * $limit;
                $query .= " OFFSET " . (int)$offset;
            }
        }

        if ($print) {
            // Use Adminer's h() function for XSS protection if available, otherwise fallback
            if (function_exists('h')) {
                echo "<p><code>" . h($query) . "</code></p>";
            } else {
                echo "<p><code>" . htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code></p>";
            }
        }

        BigQueryUtils::logQuerySafely($query, "SELECT");
        
        // Execute query using the connection's query method
        return $connection->query($query);

    } catch (Exception $e) {
        error_log("BigQuery select error: " . $e->getMessage());
        return false;
    }
}

/** Convert field in select and edit (global function for Adminer)
* @param array $field
* @return string|void
*/
if (!function_exists('convert_field')) {
    function convert_field(array $field) {
        // 統一されたフィールド変換処理を使用
        return BigQueryUtils::generateFieldConversion($field);
    }
}


/** Get escaped error message (global function for Adminer) */
if (!function_exists('error')) {
    function error() {
        global $connection;
        if ($connection) {
            $errorMsg = $connection->error();
            // Use Adminer's h() function for XSS protection if available, otherwise fallback
            if (function_exists('h')) {
                return h($errorMsg);
            } else {
                return htmlspecialchars($errorMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        return '';
    }
}

/** Get approximate number of rows (global function for Adminer) */
if (!function_exists('found_rows')) {
    /**
     * Get approximate number of rows
     * @param array $table_status Table status information
     * @param array $where WHERE conditions
     * @return int|null Approximate row count or null if not available
     */
    function found_rows($table_status, $where) {
        // If WHERE conditions are present, we can't provide a meaningful estimate
        // without running a potentially expensive COUNT query
        if (!empty($where)) {
            return null;
        }

        // Return row count from table metadata if available
        if (isset($table_status['Rows']) && is_numeric($table_status['Rows'])) {
            return (int)$table_status['Rows'];
        }

        // For BigQuery, getting exact row counts can be expensive
        // Return null to indicate count is not readily available
        return null;
    }



}

// Close the if block for BigQuery driver
}