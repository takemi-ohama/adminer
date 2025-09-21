<?php

namespace Adminer;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\ServiceException;
use Exception;
use InvalidArgumentException;

if (function_exists('Adminer\\add_driver')) {
	add_driver("bigquery", "Google BigQuery");
}

if (isset($_GET["bigquery"])) {
	define('Adminer\DRIVER', "bigquery");
	/**
	 * BigQuery connection pool manager for efficient connection reuse
	 */
	class BigQueryConnectionPool
	{
		/** @var array Active BigQuery client connections indexed by connection key */
		private static $pool = array();

		/** @var int Maximum number of concurrent connections in pool */
		private static $maxConnections = 3;

		/** @var array Last usage timestamps for LRU eviction policy */
		private static $usageTimestamps = array();

		/** @var array Connection creation timestamps for age tracking */
		private static $creationTimes = array();
		static function getConnection($key, $config)
		{
			if (isset(self::$pool[$key])) {
				self::$usageTimestamps[$key] = time();
				$age = time() - self::$creationTimes[$key];
				return self::$pool[$key];
			}
			if (count(self::$pool) >= self::$maxConnections) {
				self::evictOldestConnection();
			}
			$startTime = microtime(true);
			$clientConfig = array(
				'projectId' => $config['projectId'],
				'location' => $config['location']
			);
			if (isset($config['credentialsPath'])) {
				$clientConfig['keyFilePath'] = $config['credentialsPath'];
			}
			$client = new BigQueryClient($clientConfig);
			$creationTime = microtime(true) - $startTime;
			self::$pool[$key] = $client;
			self::$usageTimestamps[$key] = time();
			self::$creationTimes[$key] = time();
			return $client;
		}
		private static function evictOldestConnection()
		{
			if (empty(self::$usageTimestamps)) {
				return;
			}
			$oldestKey = array_keys(self::$usageTimestamps, min(self::$usageTimestamps))[0];
			unset(self::$pool[$oldestKey]);
			unset(self::$usageTimestamps[$oldestKey]);
			unset(self::$creationTimes[$oldestKey]);
		}
		function clearPool()
		{
			$count = count(self::$pool);
			self::$pool = array();
			self::$usageTimestamps = array();
			self::$creationTimes = array();
		}
		function getStats()
		{
			$stats = array(
				'pool_size' => count(self::$pool),
				'max_size' => self::$maxConnections,
				'connections' => array()
			);
			foreach (array_keys(self::$pool) as $key) {
				$stats['connections'][] = array(
					'key' => substr($key, 0, 8) . '...',
					'age' => time() - self::$creationTimes[$key],
					'last_used' => time() - self::$usageTimestamps[$key]
				);
			}
			return $stats;
		}

		/**
		 * プールから既存の接続を取得（shutdown時用）
		 * @param string $key
		 * @return BigQueryClient|null
		 */
		static function getConnectionFromPool($key)
		{
			if (isset(self::$pool[$key])) {
				self::$usageTimestamps[$key] = time();
				return self::$pool[$key];
			}
			return null;
		}
	}
	class BigQueryConfig
	{

		public const TYPE_MAPPING = array(
			'STRING' => array('type' => 'varchar', 'length' => null),
			'BYTES' => array('type' => 'varchar', 'length' => null),
			'INT64' => array('type' => 'bigint', 'length' => null),
			'INTEGER' => array('type' => 'bigint', 'length' => null),
			'FLOAT64' => array('type' => 'double', 'length' => null),
			'FLOAT' => array('type' => 'double', 'length' => null),
			'NUMERIC' => array('type' => 'decimal', 'length' => null),
			'BIGNUMERIC' => array('type' => 'decimal', 'length' => null),
			'BOOLEAN' => array('type' => 'tinyint', 'length' => 1),
			'BOOL' => array('type' => 'tinyint', 'length' => 1),
			'DATE' => array('type' => 'date', 'length' => null),
			'TIME' => array('type' => 'time', 'length' => null),
			'DATETIME' => array('type' => 'datetime', 'length' => null),
			'TIMESTAMP' => array('type' => 'timestamp', 'length' => null),
			'GEOGRAPHY' => array('type' => 'geometry', 'length' => null),
			'JSON' => array('type' => 'json', 'length' => null),
			'ARRAY' => array('type' => 'text', 'length' => null),
			'STRUCT' => array('type' => 'text', 'length' => null),
			'RECORD' => array('type' => 'text', 'length' => null),
		);
		public const DANGEROUS_SQL_PATTERNS = array(
			'ddl_dml' => '/;\\s*(?:DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\\s+/i',
			'union_injection' => '/UNION\\s+(?:ALL\\s+)?SELECT/i',
			'block_comments' => '/\/\*.*?\*\//s',
			'line_comments' => '/--[^\\r\\n]*/i',
			'execute_commands' => '/\\b(?:EXEC|EXECUTE|SP_)\\b/i',
		);
		public const SUPPORTED_FEATURES = array(
			'database' => true,
			'table' => true,
			'columns' => true,
			'sql' => true,
			'view' => true,
			'materializedview' => true,
		);
		public const UNSUPPORTED_FEATURES = array(
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
		);
		public const CACHE_CONFIG = array(
			'credentials_ttl' => 10,
			'location_ttl' => 86400,
			'databases_ttl' => 300,
			'tables_ttl' => 300,
			'fields_ttl' => 600,
			'apcu_shm_size' => '64M',
			'connection_pool_max' => 3,
		);
		static function mapType($bigQueryType)
		{
			$baseType = strtoupper(preg_replace('/\\(.*\\)/', '', $bigQueryType));
			return self::TYPE_MAPPING[$baseType] ?? array('type' => 'text', 'length' => null);
		}
		static function isDangerousQuery($query)
		{
			foreach (self::DANGEROUS_SQL_PATTERNS as $pattern) {
				if (preg_match($pattern, $query)) {
					return true;
				}
			}
			return false;
		}
		static function isFeatureSupported($feature)
		{
			return self::SUPPORTED_FEATURES[$feature] ??
				(self::UNSUPPORTED_FEATURES[$feature] ?? false);
		}
	}
	class BigQueryCacheManager
	{

		private static array $staticCache = array();
		private static array $cacheTimestamps = array();
		private static ?bool $apcuAvailable = null;
		private static function isApcuAvailable()
		{
			if (self::$apcuAvailable === null) {
				self::$apcuAvailable = extension_loaded('apcu')
					&& function_exists('\apcu_exists')
					&& function_exists('\apcu_fetch')
					&& function_exists('\apcu_store');
			}
			return self::$apcuAvailable;
		}
		static function get($key, $ttl = 300)
		{
			if (self::isApcuAvailable()) {
				return \apcu_fetch($key);
			}
			if (
				isset(self::$staticCache[$key]) &&
				(time() - (self::$cacheTimestamps[$key] ?? 0)) < $ttl
			) {
				return self::$staticCache[$key];
			}
			return false;
		}
		static function set($key, $value, $ttl = 300)
		{
			$success = false;
			if (self::isApcuAvailable()) {
				$success = \apcu_store($key, $value, $ttl);
			}
			self::$staticCache[$key] = $value;
			self::$cacheTimestamps[$key] = time();
			return $success;
		}
		static function clear($pattern = null)
		{
			if ($pattern === null) {
				if (self::isApcuAvailable()) {
					\apcu_clear_cache();
				}
				self::$staticCache = array();
				self::$cacheTimestamps = array();
			} else {
				foreach (array_keys(self::$staticCache) as $key) {
					if (strpos($key, $pattern) !== false) {
						unset(self::$staticCache[$key]);
						unset(self::$cacheTimestamps[$key]);
					}
				}
			}
		}
		static function getStats()
		{
			$apcuInfo = self::isApcuAvailable() ? \apcu_cache_info() : array();
			return array(
				'static_cache_size' => count(self::$staticCache),
				'apcu_available' => self::isApcuAvailable(),
				'apcu_info' => $apcuInfo,
				'cache_keys' => array_keys(self::$staticCache)
			);
		}
	}
	class BigQueryUtils
	{

		static function validateProjectId($projectId)
		{
			return preg_match('/^[a-z0-9][a-z0-9\\-]{4,28}[a-z0-9]$/i', $projectId) &&
				strlen($projectId) <= 30;
		}
		static function escapeIdentifier($identifier)
		{
			// 既にバッククォートで囲まれている場合は、そのまま返す
			if (preg_match('/^`[^`]*`$/', $identifier)) {
				return $identifier;
			}

			// バッククォートを含む場合は、重複を防ぐため一度除去してから再エスケープ
			$cleanIdentifier = trim($identifier, '`');
			return "`" . str_replace("`", "``", $cleanIdentifier) . "`";
		}
		static function logQuerySafely($query, $context = "QUERY")
		{
			$sanitizers = array(
				'/([\'"])[^\'\"]*\\1/' => '$1***REDACTED***$1',
				'/\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Z|a-z]{2,}\\b/' => '***EMAIL_REDACTED***'
			);
			$safeQuery = preg_replace(array_keys($sanitizers), array_values($sanitizers), $query);
			if (strlen($safeQuery) > 200) {
				$safeQuery = substr($safeQuery, 0, 200) . '... [TRUNCATED]';
			}
			error_log("BigQuery $context: $safeQuery");
		}

		/**
		 * BigQuery用値型変換ヘルパー関数
		 * @param mixed $value 変換する値
		 * @param string $fieldType フィールド型
		 * @return string BigQuery用にフォーマットされた値
		 */
		static function convertValueForBigQuery($value, $fieldType)
		{
			// NULL値の処理（空文字列とは区別する）
			if ($value === null) {
				return 'NULL';
			}

			// 値から既存のバッククォートを除去してからエスケープ
			$cleanValue = trim(str_replace('`', '', $value));
			$fieldType = strtolower($fieldType);

			if (strpos($fieldType, 'timestamp') !== false) {
				return "TIMESTAMP('" . str_replace("'", "''", $cleanValue) . "')";
			} elseif (strpos($fieldType, 'datetime') !== false) {
				return "DATETIME('" . str_replace("'", "''", $cleanValue) . "')";
			} elseif (strpos($fieldType, 'date') !== false) {
				return "DATE('" . str_replace("'", "''", $cleanValue) . "')";
			} elseif (strpos($fieldType, 'time') !== false) {
				return "TIME('" . str_replace("'", "''", $cleanValue) . "')";
			} elseif (strpos($fieldType, 'int') !== false || strpos($fieldType, 'float') !== false || strpos($fieldType, 'numeric') !== false || strpos($fieldType, 'decimal') !== false) {
				// 数値型の場合は検証してから返す
				if (is_numeric($cleanValue)) {
					return $cleanValue;
				} else {
					throw new InvalidArgumentException('Invalid numeric value: ' . $cleanValue);
				}
			} elseif (strpos($fieldType, 'bool') !== false) {
				return (strtolower($cleanValue) === 'true' || $cleanValue === '1') ? 'TRUE' : 'FALSE';
			} else {
				return "'" . str_replace("'", "''", $cleanValue) . "'";
			}
		}
		static function convertWhereCondition($condition)
		{
			if (!is_string($condition) || strlen($condition) > 1000) {
				throw new InvalidArgumentException('Invalid WHERE condition format');
			}
			if (BigQueryConfig::isDangerousQuery($condition)) {
				error_log("BigQuery: Blocked suspicious WHERE condition: " . substr($condition, 0, 100) . "...");
				throw new InvalidArgumentException('WHERE condition contains prohibited SQL patterns');
			}
			$condition = preg_replace('/`([^`]+)`/', '`$1`', $condition);
			return preg_replace_callback("/'([^']*)'/", function ($matches) {
				$escaped = str_replace("'", "\\'", $matches[1]);
				$escaped = str_replace("\\", "\\\\", $escaped);
				return "'" . $escaped . "'";
			}, $condition);
		}
		static function formatComplexValue($value, $field)
		{
			$fieldType = strtolower($field['type'] ?? 'text');
			$typePatterns = array(
				'json' => array('json', 'struct', 'record', 'array'),
				'geography' => array('geography'),
				'binary' => array('bytes', 'blob'),
			);
			foreach ($typePatterns as $handlerType => $patterns) {
				if (self::matchesTypePattern($fieldType, $patterns)) {
					return self::handleTypeConversion($value, $handlerType);
				}
			}
			return $value;
		}
		private static function matchesTypePattern($fieldType, $patterns)
		{
			foreach ($patterns as $pattern) {
				if (strpos($fieldType, $pattern) !== false) {
					return true;
				}
			}
			return false;
		}
		private static function handleTypeConversion($value, $handlerType)
		{
			switch ($handlerType) {
				case 'json':
					return is_string($value) && (substr($value, 0, 1) === '{' || substr($value, 0, 1) === '[')
						? $value : json_encode($value);
				case 'geography':
				case 'binary':
					return is_string($value) ? $value : (string) $value;
				default:
					return $value;
			}
		}
		static function generateFieldConversion($field)
		{
			$fieldName = self::escapeIdentifier($field['field']);
			$fieldType = strtolower($field['type'] ?? '');
			$conversions = array(
				'geography' => "ST_AsText($fieldName)",
				'json' => "TO_JSON_STRING($fieldName)"
			);
			foreach ($conversions as $typePattern => $conversion) {
				if (strpos($fieldType, $typePattern) !== false) {
					return $conversion;
				}
			}
			return null;
		}

		/**
		 * Build full table name with project and dataset qualifiers
		 * @param string $table テーブル名
		 * @param string $database データセット名
		 * @param string $projectId プロジェクトID
		 * @return string 完全修飾テーブル名
		 */
		static function buildFullTableName($table, $database, $projectId)
		{
			return "`" . $projectId . "`.`" . $database . "`.`" . $table . "`";
		}
	}

	// Adminerコアとの互換性のためのidf_escape()関数
	if (!function_exists('Adminer\\idf_escape')) {
		function idf_escape($idf)
		{
			return BigQueryUtils::escapeIdentifier($idf);
		}
	}
	/**
	 * BigQuery Database Connection Class
	 */
	class Db
	{
		/**
		 * BigQuery unsupported feature error messages
		 * Extracted for better maintainability and localization support
		 */
		const UNSUPPORTED_FEATURE_MESSAGES = array(
			'move_tables' => 'BigQuery does not support moving tables between datasets directly. Use CREATE TABLE AS SELECT + DROP TABLE instead.',
			'schema' => 'BigQuery uses datasets instead of schemas. Please use the dataset view for schema information.',
			'import' => 'BigQuery import functionality is not yet implemented. Please use the BigQuery console or API for bulk imports.',
			'export' => 'BigQuery export functionality is not yet implemented. Please use the BigQuery console or API for exports.',
			'analyze' => 'BigQuery does not support ANALYZE TABLE operations as it automatically optimizes queries.',
			'optimize' => 'BigQuery automatically optimizes storage and query performance.',
			'check' => 'BigQuery does not support CHECK TABLE operations as data integrity is automatically maintained.',
			'repair' => 'BigQuery does not support REPAIR TABLE operations as storage is managed automatically.',
			'search_tables' => 'Cross-table search is not yet implemented for BigQuery.',
		);
		/** @var Db|null Singleton instance */
		static $instance;

		/** @var BigQueryClient BigQuery client instance */
		public $bigQueryClient;

		/** @var string GCP Project ID */
		public $projectId;

		/** @var string Current dataset ID */
		public $datasetId = '';

		/** @var array Connection configuration */
		public $config = array();

		/** @var string Database flavor identifier */
		public $flavor = 'BigQuery';

		/** @var string Server information string */
		public $server_info = 'Google Cloud BigQuery';

		/** @var string Extension name */
		public $extension = 'BigQuery Driver';

		/** @var string Last error message */
		public $error = '';

		/** @var int Number of affected rows from last DML operation */
		public $affected_rows = 0;

		/** @var string Additional info from last operation */
		public $info = '';

		/** @var mixed Last query result for store_result() method */
		public $last_result = null;
		function connect($server, $username, $password)
		{
			try {
				$this->projectId = $this->validateAndParseProjectId($server);
				$location = $this->determineLocation($server, $this->projectId);
				$credentialsPath = $this->getCredentialsPath();
				if (!$credentialsPath) {
					throw new Exception('BigQuery authentication not configured. Set GOOGLE_APPLICATION_CREDENTIALS environment variable or provide credentials file path.');
				}
				$this->initializeConfiguration($location);
				$this->createBigQueryClient($credentialsPath, $location);
				if (!$this->isLocationExplicitlySet($server)) {
					$this->scheduleLocationDetection($this->projectId, $location);
				}
				return true;
			} catch (ServiceException $e) {
				$this->logConnectionError($e, 'ServiceException');
				return false;
			} catch (Exception $e) {
				$this->logConnectionError($e, 'Exception');
				return false;
			}
		}
		private function validateAndParseProjectId($server)
		{
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
		private function determineLocation($server, $projectId)
		{
			$parts = explode(':', $server);
			if (isset($parts[1])) {
				return $parts[1];
			}
			if (getenv('BIGQUERY_LOCATION')) {
				return getenv('BIGQUERY_LOCATION');
			}
			$cachedLocation = $this->getCachedLocation($projectId);
			if ($cachedLocation) {
				return $cachedLocation;
			}
			return 'US';
		}
		private function isLocationExplicitlySet($server)
		{
			return strpos($server, ':') !== false || getenv('BIGQUERY_LOCATION');
		}
		private function initializeConfiguration($location)
		{
			$this->config = array(
				'projectId' => $this->projectId,
				'location' => $location
			);
		}
		private function createBigQueryClient($credentialsPath, $location)
		{
			$clientKey = md5($this->projectId . $credentialsPath . $location);
			$this->bigQueryClient = BigQueryConnectionPool::getConnection($clientKey, array(
				'projectId' => $this->projectId,
				'location' => $location,
				'credentialsPath' => $credentialsPath
			));
		}
		private function logConnectionError($e, $type)
		{
			$errorMessage = $e->getMessage();
			$safeMessage = preg_replace('/project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+/i', 'project: [REDACTED]', $errorMessage);
			error_log("BigQuery $type: " . $safeMessage);
			if (strpos($errorMessage, 'UNAUTHENTICATED') !== false || strpos($errorMessage, '401') !== false) {
				error_log("BigQuery: Authentication failed. Check service account credentials.");
			} elseif (strpos($errorMessage, 'OpenSSL') !== false) {
				error_log("BigQuery: Invalid private key in service account file.");
			}
		}
		private function getCachedLocation($projectId)
		{
			$cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
			if (file_exists($cacheFile)) {
				$cacheData = json_decode(file_get_contents($cacheFile), true);
				if ($cacheData && isset($cacheData['location'], $cacheData['expires'])) {
					if (time() < $cacheData['expires']) {
						return $cacheData['location'];
					} else {
						@unlink($cacheFile);
					}
				}
			}
			return null;
		}
		private function setCachedLocation($projectId, $location)
		{
			$cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
			$cacheData = array(
				'location' => $location,
				'expires' => time() + 86400
			);
			@file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
		}
		private function getCredentialsPath()
		{
			static $credentialsCache = null;
			static $lastCheckTime = 0;
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
		private function validateCredentialsFile($credentialsPath)
		{
			$fileInfo = @stat($credentialsPath);
			if ($fileInfo === false) {
				throw new Exception("Service account file not found: {$credentialsPath}");
			}
			if (!($fileInfo['mode'] & 0444)) {
				throw new Exception("Service account file not readable: {$credentialsPath}");
			}
		}
		private function scheduleLocationDetection($projectId, $defaultLocation)
		{
			if ($this->getCachedLocation($projectId)) {
				return;
			}

			// 静的データとして必要な情報を保存
			$clientKey = md5($projectId . ($this->config['credentialsPath'] ?? '') . ($this->config['location'] ?? ''));

			if (function_exists('fastcgi_finish_request')) {
				register_shutdown_function(function () use ($projectId, $defaultLocation, $clientKey) {
					fastcgi_finish_request();
					self::performBackgroundLocationDetection($projectId, $defaultLocation, $clientKey);
				});
			} else {
				register_shutdown_function(function () use ($projectId, $defaultLocation, $clientKey) {
					self::performBackgroundLocationDetection($projectId, $defaultLocation, $clientKey);
				});
			}
		}
		private function performLightweightLocationDetection($projectId, $defaultLocation)
		{
			try {
				$datasets = $this->bigQueryClient->datasets(['maxResults' => 1]);
				foreach ($datasets as $dataset) {
					try {
						$datasetInfo = $dataset->info();
						$detectedLocation = $datasetInfo['location'] ?? $defaultLocation;
						if ($detectedLocation !== $defaultLocation) {
							$this->config['location'] = $detectedLocation;
							$this->setCachedLocation($projectId, $detectedLocation);
						}
						break;
					} catch (Exception $e) {
						// Location detection failed - continue silently
						break;
					}
				}
			} catch (Exception $e) {
				// Location detection failed - continue with default location
			}
		}

		/**
		 * 背景位置検出の静的実行（shutdown時の安全な実行）
		 * @param string $projectId
		 * @param string $defaultLocation
		 * @param string $clientKey
		 */
		private static function performBackgroundLocationDetection($projectId, $defaultLocation, $clientKey)
		{
			try {
				// 接続プールから既存のクライアントを取得
				$client = BigQueryConnectionPool::getConnectionFromPool($clientKey);
				if (!$client) {
					return; // クライアントが利用できない場合は静かに終了
				}

				$datasets = $client->datasets(['maxResults' => 1]);
				foreach ($datasets as $dataset) {
					try {
						$datasetInfo = $dataset->info();
						$detectedLocation = $datasetInfo['location'] ?? $defaultLocation;
						if ($detectedLocation !== $defaultLocation) {
							self::setCachedLocationStatic($projectId, $detectedLocation);
						}
						break;
					} catch (Exception $e) {
						break;
					}
				}
			} catch (Exception $e) {
				// Background location detection failed - continue silently
			}
		}

		/**
		 * 静的キャッシュ保存（shutdown時用）
		 * @param string $projectId
		 * @param string $location
		 */
		private static function setCachedLocationStatic($projectId, $location)
		{
			$cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
			$cacheData = array(
				'location' => $location,
				'expires' => time() + 86400
			);
			@file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
		}
		function query($query)
	{
		try {
			// テーブル操作クエリの特別処理
			if (preg_match('/^\s*(ANALYZE|OPTIMIZE|CHECK|REPAIR)\s+TABLE\s+/i', $query, $matches)) {
				$operation = strtolower($matches[1]);
				switch ($operation) {
					case 'analyze':
						$this->error = 'BigQuery does not support ANALYZE TABLE operations as it automatically optimizes queries.';
						break;
					case 'optimize':
						$this->error = 'BigQuery automatically optimizes storage and query performance.';
						break;
					case 'check':
						$this->error = 'BigQuery does not support CHECK TABLE operations as data integrity is automatically maintained.';
						break;
					case 'repair':
						$this->error = 'BigQuery does not support REPAIR TABLE operations as storage is managed automatically.';
						break;
				}
				return false;
			}

			// Dataset ID validation and retrieval from URL parameter
			if (empty($this->datasetId) && isset($_GET['db']) && !empty($_GET['db'])) {
				// Validate dataset name: BigQuery dataset IDs must be 1-1024 characters, letters, numbers, and underscores only
				if (preg_match('/^[A-Za-z0-9_]{1,1024}$/', $_GET['db'])) {
					$this->datasetId = $_GET['db'];
				} else {
					error_log("BigQuery: Invalid dataset name provided: " . $_GET['db']);
					$this->error = 'Invalid dataset name. Dataset names must contain only letters, numbers, and underscores (1-1024 characters).';
					return false;
				}
			}

			// READ-ONLYモード制限の設定確認
			if (getenv('BIGQUERY_READONLY_MODE') === 'true') {
				$this->validateReadOnlyQuery($query);
			}

			$queryLocation = $this->determineQueryLocation();

			$queryJob = $this->bigQueryClient->query($query)
				->useLegacySql(false)
				->location($queryLocation);
			$job = $this->bigQueryClient->runQuery($queryJob);
			if (!$job->isComplete()) {
				$job->waitUntilComplete();
			}
			$this->checkJobStatus($job);

			// Store result for store_result() method
			return $this->last_result = new Result($job);
		} catch (ServiceException $e) {
			$errorMessage = $e->getMessage();
			$errorCode = $e->getCode();

			// 400 error - query validation failed (validation failed)

			BigQueryUtils::logQuerySafely($e->getMessage(), 'SERVICE_ERROR');
			$this->last_result = false;
			return false;
		} catch (Exception $e) {
			error_log("BigQuery General Error: " . $e->getMessage());
			BigQueryUtils::logQuerySafely($e->getMessage(), 'ERROR');
			$this->last_result = false;
			return false;
		}
	}
		private function checkJobStatus($job)
		{
			$jobInfo = $job->info();
			if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
				$errorResult = $jobInfo['status']['errorResult'] ?? null;
				if ($errorResult) {
					throw new Exception("BigQuery job failed: " . ($errorResult['message'] ?? 'Unknown error'));
				}
			}
		}
		private function validateReadOnlyQuery($query)
		{
			$cleanQuery = preg_replace('/--.*$/m', '', $query);
			$cleanQuery = preg_replace('/\/\*.*?\*\//s', '', $cleanQuery);
			$cleanQuery = trim($cleanQuery);
			if (!preg_match('/^\s*SELECT\s+/i', $cleanQuery)) {
				throw new Exception('Only SELECT queries are supported in read-only mode');
			}
			$dangerousPatterns = array(
				'/\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE)\b/i',
				'/\b(GRANT|REVOKE)\b/i',
				'/\bCALL\s+/i',
				'/\bEXEC(UTE)?\s+/i',
			);
			foreach ($dangerousPatterns as $pattern) {
				if (preg_match($pattern, $cleanQuery)) {
					throw new Exception('DDL/DML operations are not allowed in read-only mode');
				}
			}
			return true;
		}
		private function determineQueryLocation()
		{
			if (!empty($this->datasetId)) {
				try {
					$dataset = $this->bigQueryClient->dataset($this->datasetId);
					$datasetInfo = $dataset->info();
					$datasetLocation = $datasetInfo['location'] ?? null;
					if ($datasetLocation && $datasetLocation !== ($this->config['location'] ?? '')) {
						$this->config['location'] = $datasetLocation;
						return $datasetLocation;
					}
				} catch (Exception) {
					// Failed to get dataset location
				}
			}
			return $this->config['location'] ?? 'US';
		}
		function select_db($database)
		{
			try {
				$dataset = $this->bigQueryClient->dataset($database);
				$dataset->reload();
				$datasetInfo = $dataset->info();
				$datasetLocation = $datasetInfo['location'] ?? 'US';
				$previousLocation = $this->config['location'] ?? 'US';
				if ($datasetLocation !== $previousLocation) {
					$this->config['location'] = $datasetLocation;
				}
				$this->datasetId = $database;
				return true;
			} catch (ServiceException $e) {
				$this->logDatasetError($e, $database);
				return false;
			} catch (Exception $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'DATASET_ERROR');
				return false;
			}
		}
		private function logDatasetError($e, $database)
		{
			$message = $e->getMessage();
			if (strpos($message, '404') !== false || strpos($message, 'Not found') !== false) {
				error_log("BigQuery: Dataset '$database' not found in project '{$this->projectId}'");
			} elseif (strpos($message, 'permission') !== false || strpos($message, '403') !== false) {
				error_log("BigQuery: Access denied to dataset '$database'");
			} else {
				BigQueryUtils::logQuerySafely($message, 'DATASET_ERROR');
			}
		}
		function quote($idf)
		{
			return BigQueryUtils::escapeIdentifier($idf);
		}
		function error()
	{
		// 特定のエラーメッセージが設定されている場合はそれを返す
		if (!empty($this->error)) {
			return $this->error;
		}
		
		return "Check server logs for detailed error information";
	}

		function multi_query($query)
		{
			// BigQueryは複数クエリをサポートしないため、単一クエリとして処理
			return $this->query($query);
		}

		function store_result()
	{
		// 保存されたクエリ結果を返す
		return $this->last_result;
	}

		function next_result()
		{
			// BigQueryは複数結果セットをサポートしないため、falseを返す
			return false;
		}
	}
	class Result
	{

		private $queryResults;
		private $rowNumber = 0;
		private $fieldsCache = null;
		private $iterator = null;
		private $isIteratorInitialized = false;
	public $num_rows = 0;
		function __construct($queryResults)
	{
		$this->queryResults = $queryResults;
		
		// BigQuery query results row count retrieval
		try {
			$jobInfo = $queryResults->info();
			$this->num_rows = (int)($jobInfo['totalRows'] ?? 0);
		} catch (Exception $e) {
			// Default to 0 if row count retrieval fails
			$this->num_rows = 0;
		}
	}
		function fetch_assoc()
		{
			try {
				if (!$this->isIteratorInitialized) {
					$this->iterator = $this->queryResults->getIterator();
					$this->isIteratorInitialized = true;
				}
				if ($this->iterator && $this->iterator->valid()) {
					$row = $this->iterator->current();
					$this->iterator->next();
					$processedRow = array();
					foreach ($row as $key => $value) {
						if (is_array($value)) {
							$processedRow[$key] = json_encode($value);
						} elseif (is_object($value)) {
							if ($value instanceof \DateTime) {
								$processedRow[$key] = $value->format('Y-m-d H:i:s');
							} elseif ($value instanceof \DateTimeInterface) {
								$processedRow[$key] = $value->format('Y-m-d H:i:s');
							} elseif (method_exists($value, 'format')) {
								try {
									$processedRow[$key] = $value->format('Y-m-d H:i:s');
								} catch (Exception $e) {
									$processedRow[$key] = (string) $value;
								}
							} elseif (method_exists($value, '__toString')) {
								$processedRow[$key] = (string) $value;
							} else {
								$processedRow[$key] = json_encode($value);
							}
						} elseif (is_null($value)) {
							$processedRow[$key] = null;
						} else {
							$processedRow[$key] = $value;
						}
					}
					$this->rowNumber++;
					return $processedRow;
				}
				return false;
			} catch (Exception $e) {
				error_log("Result fetch error: " . $e->getMessage());
				return false;
			}
		}
		function fetch_row()
		{
			$assoc = $this->fetch_assoc();
			return $assoc ? array_values($assoc) : false;
		}
		function num_fields()
		{
			if ($this->fieldsCache === null) {
				$this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? array();
			}
			return count($this->fieldsCache);
		}
		function fetch_field($offset = 0)
	{
		if ($this->fieldsCache === null) {
			$this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? array();
		}
		if (!isset($this->fieldsCache[$offset])) {
			return false;
		}
		$field = $this->fieldsCache[$offset];
		return (object) array(
			'name' => $field['name'],
			'type' => $this->mapBigQueryType($field['type']),
			'length' => null,
			'flags' => ($field['mode'] ?? 'NULLABLE') === 'REQUIRED' ? 'NOT NULL' : '',
			'charsetnr' => $this->getBigQueryCharsetNr($field['type']),
			'orgname' => $field['name'],
			'orgtable' => ''
		);
	}
		private function mapBigQueryType($bigQueryType)
		{
			$typeMap = array(
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
			);
			return $typeMap[strtoupper($bigQueryType)] ?? 'text';
		}

	private function getBigQueryCharsetNr($bigQueryType)
	{
		$baseType = strtoupper(preg_replace('/\([^)]*\)/', '', $bigQueryType));
		
		// BigQuery data types mapped to appropriate MySQL charset numbers
		switch ($baseType) {
			case 'BYTES':
				// Binary data - MySQL charset 63 (binary)
				return 63;
			case 'STRING':
			case 'JSON':
				// Text data - MySQL charset 33 (UTF-8)
				return 33;
			case 'INT64':
			case 'INTEGER':
			case 'FLOAT64':
			case 'FLOAT':
			case 'NUMERIC':
			case 'BIGNUMERIC':
			case 'BOOLEAN':
			case 'BOOL':
			case 'DATE':
			case 'TIME':
			case 'DATETIME':
			case 'TIMESTAMP':
				// Numeric, date, and boolean data - binary charset 63
				return 63;
			case 'ARRAY':
			case 'STRUCT':
			case 'RECORD':
			case 'GEOGRAPHY':
				// Complex data types - text charset 33
				return 33;
			default:
				// Default to text charset
				return 33;
		}
	}
	}
	class Driver
	{

		static $instance;
		static $extensions = array("BigQuery");
		static $jush = "sql";
		static $operators = array(
			"=",
			"!=",
			"<>",
			"<",
			"<=",
			">",
			">=",
			"IN",
			"NOT IN",
			"IS NULL",
			"IS NOT NULL",
			"LIKE",
			"NOT LIKE",
			"REGEXP",
			"NOT REGEXP"
		);
		/**
		 * @var array BigQuery partitioning columns (not supported, so empty)
		 */
		public $partitionBy = array();

		/**
		 * @var array BigQuery unsigned integer types (not applicable, so empty)
		 */
		public $unsigned = array();

		/**
		 * @var array BigQuery generated columns (not supported, so empty)
		 */
		public $generated = array();

		/**
		 * @var array BigQuery enum type lengths (not supported, so empty)
		 */
		public $enumLength = array();

		/**
		 * @var array BigQuery insert functions (empty - only standard input supported)
		 */
		public $insertFunctions = array();

		/**
		 * @var array BigQuery edit functions (empty - only standard editing supported)
		 */
		public $editFunctions = array();

		// BigQuery用データ型定義（Adminer互換形式）
		protected $types = array(
			array("INT64" => 0, "INTEGER" => 0, "FLOAT64" => 0, "FLOAT" => 0, "NUMERIC" => 0, "BIGNUMERIC" => 0),
			array("STRING" => 0, "BYTES" => 0),
			array("DATE" => 0, "TIME" => 0, "DATETIME" => 0, "TIMESTAMP" => 0),
			array("BOOLEAN" => 0, "BOOL" => 0),
			array("ARRAY" => 0, "STRUCT" => 0, "JSON" => 0, "GEOGRAPHY" => 0)
		);

		static function connect($server, $username, $password)
		{
			$db = new Db();
			if ($db->connect($server, $username, $password)) {
				return $db;
			}
			return false;
		}
		function tableHelp($name, $is_view = false)
		{
			return null;
		}
		function structuredTypes()
		{
			$allTypes = array();
			foreach ($this->types as $typeGroup) {
				$allTypes = array_merge($allTypes, array_keys($typeGroup));
			}
			return $allTypes;
		}
		function inheritsFrom($table)
		{
			return array();
		}
		function inheritedTables($table)
		{
			return array();
		}
		function select($table, $select, $where, $group, $order = array(), $limit = 1, $page = 0, $print = false)
		{
			return select($table, $select, $where, $group, $order, $limit, $page, $print);
		}
		function value($val, $field)
		{
			return BigQueryUtils::formatComplexValue($val, $field);
		}
		function convert_field(array $field)
		{
			return BigQueryUtils::generateFieldConversion($field);
		}

		function hasCStyleEscapes(): bool
		{
			return false; // BigQuery does not support C-style escapes
		}

		function warnings()
		{
			// BigQueryはクエリ警告をサポートしないため、空配列を返す
			return array();
		}


		/**
		 * BigQueryストレージエンジン（固定値）
		 * @return array BigQueryはストレージエンジンとして'BigQuery'のみサポート
		 */
		function engines()
		{
			return array('BigQuery');
		}

		/**
		 * BigQuery用データ型定義（Driverクラスメソッド）
		 * Adminerが要求するDriver::types()メソッド
		 * @return array BigQueryでサポートされるデータ型
		 */
		function types()
		{
			return array(
				'Numbers' => array(
					'INT64' => 0,
					'INTEGER' => 0,
					'FLOAT64' => 0,
					'FLOAT' => 0,
					'NUMERIC' => 0,
					'BIGNUMERIC' => 0
				),
				'Strings' => array(
					'STRING' => 0,
					'BYTES' => 0
				),
				'Date and time' => array(
					'DATE' => 0,
					'TIME' => 0,
					'DATETIME' => 0,
					'TIMESTAMP' => 0
				),
				'Boolean' => array(
					'BOOLEAN' => 0,
					'BOOL' => 0
				),
				'Complex' => array(
					'ARRAY' => 0,
					'STRUCT' => 0,
					'JSON' => 0,
					'GEOGRAPHY' => 0
				)
			);
		}

		/**
		 * BigQuery用enum長取得メソッド
		 * BigQueryはenum型をサポートしないため、常に空配列を返す
		 * @param array $field フィールド定義
		 * @return array 空配列（BigQueryにはenum型が存在しない）
		 */
		function enumLength($field)
		{
			// BigQueryはenum型をサポートしないため、常に空配列を返す
			return array();
		}

		/**
		 * BigQuery用値逆変換関数取得メソッド
		 * BigQueryは特別な値変換をサポートしないため、常にnullを返す
		 * @param array $field フィールド定義
		 * @return null BigQueryでは逆変換関数は使用しない
		 */
		function unconvertFunction($field)
		{
			// BigQueryは特別な値変換をサポートしないため、nullを返す
			return null;
		}

		/**
		 * BigQuery用データ挿入メソッド
		 * グローバルinsert()関数を呼び出す
		 * @param string $table テーブル名
		 * @param array $set 挿入データ（フィールド名 => 値の配列）
		 * @return bool 成功時true
		 */
		function insert($table, $set)
		{
			// グローバルinsert()関数を呼び出し
			return insert($table, $set);
		}

		/**
		 * BigQuery用データ更新メソッド
		 * グローバルupdate()関数を呼び出す
		 * @param string $table テーブル名
		 * @param array $set 更新データ（フィールド名 => 値の配列）
		 * @param string $queryWhere WHERE条件
		 * @param int $limit 制限行数
		 * @return bool 成功時true
		 */
		function update($table, $set, $queryWhere = '', $limit = 0)
		{
			// グローバルupdate()関数を呼び出し
			return update($table, $set, $queryWhere, $limit);
		}

		/**
		 * BigQuery用データ削除メソッド
		 * グローバルdelete()関数を呼び出す
		 * @param string $table テーブル名
		 * @param string $queryWhere WHERE条件
		 * @param int $limit 制限行数
		 * @return bool 成功時true
		 */
		function delete($table, $queryWhere = '', $limit = 0)
		{
			// グローバルdelete()関数を呼び出し
			return delete($table, $queryWhere, $limit);
		}

		/**
		 * BigQuery用全フィールド取得メソッド
		 * Database schemaページで使用される
		 * @return array<string, array<array{field:string, null:bool, type:string, length:?string}>>
		 */
		function allFields(): array
		{
			$return = array();
			try {
				foreach (tables_list() as $table => $type) {
					$tableFields = fields($table);
					foreach ($tableFields as $field) {
						$return[$table][] = $field;
					}
				}
				return $return;
			} catch (Exception $e) {
				error_log("BigQuery allFields error: " . $e->getMessage());
				return array();
			}
		}

		/**
		 * BigQuery用検索条件変換メソッド
		 * 検索条件のフィールド識別子を適切に変換する
		 * @param string $idf フィールド識別子
		 * @param array $val 検索値（op, val）
		 * @param array $field フィールド定義
		 * @return string 変換済み識別子
		 */
		function convertSearch(string $idf, array $val, array $field): string
		{
			// BigQueryでは特別な変換は不要で、識別子をそのまま返す
			// 必要に応じて将来的にBigQuery固有の検索最適化を追加可能
			return $idf;
		}

		/**
		 * BigQuery テーブル削除機能
		 * @param array $tables 削除するテーブル名の配列
		 * @return bool 成功時true、失敗時false
		 */
		function dropTables($tables)
		{
			global $connection;

			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			$errors = array();
			$successCount = 0;

			try {
				// 現在のデータセットを取得
				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
				if (empty($database)) {
					return false;
				}

				foreach ($tables as $table) {
					if (empty($table)) {
						continue;
					}

					try {
						// BigQueryでのDROP TABLE実行
						$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
						$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
						$query = "DROP TABLE $fullTableName";
						
						BigQueryUtils::logQuerySafely($query, "DROP_TABLE");
						$result = $connection->query($query);
						
						if ($result !== false) {
							$successCount++;
						} else {
							$errors[] = "Failed to drop table: $table";
						}
					} catch (Exception $e) {
						$errors[] = "Drop table '$table' failed: " . $e->getMessage();
						BigQueryUtils::logQuerySafely($e->getMessage(), 'DROP_TABLE_ERROR');
					}
				}

				// エラーがある場合は接続エラーとして記録
				if (!empty($errors) && $connection) {
					$connection->error = implode('; ', $errors);
				}

				return $successCount > 0;
			} catch (Exception $e) {
				if ($connection) {
					$connection->error = "DROP TABLES failed: " . $e->getMessage();
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), 'DROP_TABLES_ERROR');
				return false;
			}
		}

	/**
	 * BigQuery EXPLAIN機能
	 * @param string $query 実行クエリ
	 * @return mixed クエリ結果オブジェクト（成功時）、失敗時はfalse
	 */
	function explain($query)
	{
		global $connection;
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}
		
		try {
			// BigQueryのEXPLAIN文を実行
			$explainQuery = "EXPLAIN " . $query;
			BigQueryUtils::logQuerySafely($explainQuery, "EXPLAIN");
			$result = $connection->query($explainQuery);
			return $result;
		} catch (Exception $e) {
			BigQueryUtils::logQuerySafely($e->getMessage(), 'EXPLAIN_ERROR');
			return false;
		}
	}

	/**
	 * BigQuery用CSS出力メソッド
	 * BigQueryでサポートされていない機能のUI要素を非表示にする
	 * Truncate、Dropは利用可能なため除外
	 * @return string CSS文字列
	 */
	function css()
	{
		return "
		<style>
		/* BigQuery非対応機能を非表示 - より強い優先度で適用 */
		
		/* Database画面のSearch data in tables機能を非表示 */
		.search-tables {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Analyze機能を非表示 */
		.analyze,
		input[value='Analyze'],
		input[type='submit'][value='Analyze'],
		a[href*='analyze'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Optimize機能を非表示 */
		.optimize,
		input[value='Optimize'],
		input[type='submit'][value='Optimize'],
		a[href*='optimize'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Repair機能を非表示 */
		.repair,
		input[value='Repair'],
		input[type='submit'][value='Repair'],
		a[href*='repair'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Check機能を非表示 */
		.check,
		input[value='Check'],
		input[type='submit'][value='Check'],
		a[href*='check'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Move機能を非表示 */
		.move,
		input[value='Move'],
		input[type='submit'][value='Move'],
		a[href*='move'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Copy機能を非表示 */
		.copy,
		input[value='Copy'],
		input[type='submit'][value='Copy'],
		a[href*='copy'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Import機能を非表示 */
		.import,
		input[value='Import'],
		input[type='submit'][value='Import'],
		a[href*='import'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Export機能（一部）を非表示 */
		select[name='format'] option[value='csv+excel'],
		select[name='format'] option[value='xml'] {
			display: none !important;
		}
		
		/* Index関連機能を非表示 */
		.indexes,
		a[href*='indexes'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Foreign key関連機能を非表示 */
		.foreign-keys,
		a[href*='foreign'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Trigger関連機能を非表示 */
		.triggers,
		a[href*='trigger'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Event関連機能を非表示 */
		.events,
		a[href*='event'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Routine関連機能を非表示 */
		.routines,
		a[href*='routine'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Sequence関連機能を非表示 */
		.sequences,
		a[href*='sequence'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* User types関連機能を非表示 */
		.user-types,
		a[href*='type'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Auto increment機能を非表示 */
		input[name*='auto_increment'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Comment機能を非表示（テーブルレベル） */
		input[name='Comment'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Collation機能を非表示 */
		select[name*='collation'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* FullText検索機能を非表示 */
		input[type='submit'][value*='Fulltext'] {
			display: none !important;
			visibility: hidden !important;
		}
		
		/* Truncate/Dropボタンの明示的な表示 */
		input[value='Truncate'],
		input[type='submit'][value='Truncate'],
		input[name='truncate'] {
			display: inline-block !important;
			visibility: visible !important;
		}

		input[value='Drop'],
		input[type='submit'][value='Drop'],
		input[name='drop'] {
			display: inline-block !important;
			visibility: visible !important;
		}
		
		/* BigQuery対応機能のラベル改善 */
		body.bigquery .h2 {
			position: relative;
		}
		
		body.bigquery .h2:after {
			content: ' (BigQuery)';
			font-size: 0.8em;
			color: #666;
		}
		</style>
		<script>
		// *** BigQuery強制表示機能 - 複数タイミングで実行 ***
		function forceBigQueryButtonsDisplay() {
			console.log('BigQuery強制表示実行開始');
			
			// BigQueryドライバー使用時にbody要素にクラス追加
			if (document.querySelector('title') && document.querySelector('title').textContent.includes('BigQuery')) {
				document.body.classList.add('bigquery');
			}
			
			// 非対応ボタンを非表示（TruncateとDropは除外）
			var buttonsToHide = [
				'input[value=\"Analyze\"]',
				'input[value=\"Optimize\"]', 
				'input[value=\"Repair\"]',
				'input[value=\"Check\"]',
				'input[value=\"Move\"]',
				'input[value=\"Copy\"]',
				'input[value=\"Import\"]'
			];
			
			buttonsToHide.forEach(function(selector) {
				var elements = document.querySelectorAll(selector);
				elements.forEach(function(element) {
					element.style.display = 'none';
					element.style.visibility = 'hidden';
				});
			});
			
			// *** 重要：Selected フィールドセットの強制表示 ***
			var selectedFieldsets = document.querySelectorAll('fieldset');
			selectedFieldsets.forEach(function(fieldset) {
				var legend = fieldset.querySelector('legend');
				if (legend && legend.textContent.includes('Selected')) {
					console.log('Selected fieldset found, forcing display');
					fieldset.style.setProperty('display', 'block', 'important');
					fieldset.style.setProperty('visibility', 'visible', 'important');
					fieldset.style.setProperty('opacity', '1', 'important');

					// fieldset内のdivも強制表示
					var divs = fieldset.querySelectorAll('div');
					divs.forEach(function(div) {
						div.style.setProperty('display', 'block', 'important');
						div.style.setProperty('visibility', 'visible', 'important');
						div.style.setProperty('opacity', '1', 'important');
					});
				}
			});

			// Truncate/Dropボタンの最強レベルでの強制表示
			var buttonsToShow = [
				'input[name=\"truncate\"]',
				'input[name=\"drop\"]'
			];

			buttonsToShow.forEach(function(selector) {
				var elements = document.querySelectorAll(selector);
				console.log('Found buttons for', selector, ':', elements.length);
				elements.forEach(function(element) {
					// ボタン自体を最強レベルで表示
					element.style.setProperty('display', 'inline-block', 'important');
					element.style.setProperty('visibility', 'visible', 'important');
					element.style.setProperty('opacity', '1', 'important');

					// 親要素チェーンも最強レベルで表示
					var parent = element.parentElement;
					while (parent && parent.tagName !== 'BODY') {
						if (parent.tagName === 'FIELDSET' || parent.tagName === 'DIV') {
							parent.style.setProperty('display', parent.tagName === 'FIELDSET' ? 'block' : 'block', 'important');
							parent.style.setProperty('visibility', 'visible', 'important');
							parent.style.setProperty('opacity', '1', 'important');
						}
						parent = parent.parentElement;
					}
				});
			});
			
			console.log('BigQuery強制表示実行完了');
		}
		
		// 複数のタイミングで確実に実行
		// 1. DOMContentLoaded（通常のタイミング）
		document.addEventListener('DOMContentLoaded', forceBigQueryButtonsDisplay);
		
		// 2. window.load（全リソース読み込み完了後）
		window.addEventListener('load', forceBigQueryButtonsDisplay);
		
		// 3. 即座実行（既にDOMが読み込まれている場合）
		if (document.readyState === 'loading') {
			// まだ読み込み中
		} else {
			// 既に読み込み完了
			forceBigQueryButtonsDisplay();
		}
		
		// 4. 遅延実行（最後の保険）
		setTimeout(forceBigQueryButtonsDisplay, 500);
		setTimeout(forceBigQueryButtonsDisplay, 1000);
		</script>
		";
	}
	}
	function support($feature)
	{
		$supportedFeatures = array(
			'database',
			'table',
			'columns',
			'sql',
			'view',
			'materializedview',
			// CRUD操作サポート追加
			'create_db',      // データセット作成
			'create_table',   // テーブル作成
			'insert',         // データ挿入
			'update',         // データ更新
			'delete',         // データ削除
			'drop_table',     // テーブル削除
			'truncate',       // テーブル内容全削除（BigQueryサポート）
			'drop',           // オブジェクト削除（BigQueryサポート）
			'select',         // データ選択
			'export',         // データエクスポート
		);
		$unsupportedFeatures = array(
			'foreignkeys',
			'indexes',
			'processlist',
			'kill',
			'transaction',
			'comment',
			'drop_col',
			'dump',
			'event',
			'move_col',
			'move_tables',    // Table move (not supported by BigQuery)
			'privileges',
			'procedure',
			'routine',
			'sequence',
			'status',
			'trigger',
			'type',
			'variables',
			'descidx',
			'check',
			'schema',
			// BigQueryで非対応の機能を追加（UI非表示用）
			'analyze',        // ANALYZE TABLE機能
			'optimize',       // OPTIMIZE TABLE機能
			'repair',         // REPAIR TABLE機能
			'search_tables',  // Search data in tables機能
		);
		if (in_array($feature, $supportedFeatures)) {
			return true;
		}
		if (in_array($feature, $unsupportedFeatures)) {
			return false;
		}
		return false;
	}
	function operators()
	{
		return array(
			"=",
			"!=",
			"<>",
			"<",
			"<=",
			">",
			">=",
			"IN",
			"NOT IN",
			"IS NULL",
			"IS NOT NULL",
			"LIKE",
			"NOT LIKE",
			"REGEXP",
			"NOT REGEXP"
		);
	}
	function collations()
	{
		return array();
	}
	function db_collation($db)
	{
		return "";
	}
	function information_schema($db)
	{
		return "";
	}
	function is_view($table_status)
	{
		return isset($table_status["Engine"]) &&
			(strtolower($table_status["Engine"]) === "view" ||
				strtolower($table_status["Engine"]) === "materialized view");
	}
	function fk_support($table_status)
	{
		return false;
	}
	function indexes($table, $connection2 = null)
	{
		return array();
	}
	function foreign_keys($table)
	{
		return array();
	}
	function logged_user()
	{
		return "BigQuery Service Account";
	}
	function get_databases($flush = false)
	{
		global $connection;
		$cacheKey = 'bq_databases_' . ($connection && isset($connection->projectId) ? $connection->projectId : 'default');
		$cacheTime = 300;
		if (!$flush) {
			$cached = BigQueryCacheManager::get($cacheKey, $cacheTime);
			if ($cached !== false) {
				return $cached;
			}
		}
		try {
			$datasets = array();
			$datasetsIterator = ($connection && isset($connection->bigQueryClient)) ? $connection->bigQueryClient->datasets(array(
				'maxResults' => 100
			)) : array();
			foreach ($datasetsIterator as $dataset) {
				$datasets[] = $dataset->id();
			}
			sort($datasets);
			BigQueryCacheManager::set($cacheKey, $datasets, $cacheTime);
			return $datasets;
		} catch (Exception $e) {
			error_log("Error listing datasets: " . $e->getMessage());
			return array();
		}
	}
	function tables_list($database = '')
	{
		global $connection;
		try {
			$actualDatabase = '';
			if (!empty($database)) {
				$actualDatabase = $database;
			} else {
				$actualDatabase = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			}
			if (empty($actualDatabase)) {
				error_log("tables_list: No database (dataset) context available");
				return array();
			}
			$cacheKey = 'bq_tables_' . ($connection && isset($connection->projectId) ? $connection->projectId : 'default') . '_' . $actualDatabase;
			$cacheTime = 300;
			$cached = BigQueryCacheManager::get($cacheKey, $cacheTime);
			if ($cached !== false) {
				return $cached;
			}
			$dataset = ($connection && isset($connection->bigQueryClient)) ? $connection->bigQueryClient->dataset($actualDatabase) : null;
			$tables = array();
			$pageToken = null;
			do {
				$options = array('maxResults' => 100);
				if ($pageToken) {
					$options['pageToken'] = $pageToken;
				}
				$result = $dataset->tables($options);
				foreach ($result as $table) {
					$tables[$table->id()] = 'table';
				}
				$pageToken = $result->nextResultToken();
			} while ($pageToken);
			BigQueryCacheManager::set($cacheKey, $tables, $cacheTime);
			return $tables;
		} catch (Exception $e) {
			error_log("Error listing tables for database '$database' (actual: '$actualDatabase'): " . $e->getMessage());
			return array();
		}
	}
	function table_status($name = '', $fast = false)
	{
		global $connection;
		try {
			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database)) {
				error_log("table_status: No database (dataset) context available, returning empty array");
				return array();
			}
			$dataset = ($connection && isset($connection->bigQueryClient)) ? $connection->bigQueryClient->dataset($database) : null;
			$tables = array();
			if ($name) {
				try {
					$table = $dataset->table($name);
					$tableInfo = $table->info();
					$result = array(
						'Name' => $table->id(),
						'Engine' => 'BigQuery',
						'Rows' => $tableInfo['numRows'] ?? 0,
						'Data_length' => $tableInfo['numBytes'] ?? 0,
						'Comment' => $tableInfo['description'] ?? '',
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
					);
					$tables[$table->id()] = $result;
				} catch (Exception $e) {
					error_log("Error getting specific table '$name' info: " . $e->getMessage() . ", returning empty array");
					return array();
				}
			} else {
				foreach ($dataset->tables() as $table) {
					$tableInfo = $table->info();
					$result = array(
						'Name' => $table->id(),
						'Engine' => 'BigQuery',
						'Comment' => $tableInfo['description'] ?? '',
					);
					if (!$fast) {
						$result += array(
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
						);
					}
					$tables[$table->id()] = $result;
				}
			}
			$result = is_array($tables) ? $tables : array();
			return $result;
		} catch (Exception $e) {
			error_log("Error getting table status for name '$name' (database: '$database'): " . $e->getMessage() . ", returning empty array");
			return array();
		}
	}
	function convertAdminerWhereToBigQuery($condition)
	{
		if (!is_string($condition)) {
			throw new InvalidArgumentException('WHERE condition must be a string');
		}
		if (strlen($condition) > 1000) {
			throw new InvalidArgumentException('WHERE condition exceeds maximum length');
		}
		$suspiciousPatterns = array(
			'/;\s*(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\s+/i',
			'/UNION\s+(ALL\s+)?SELECT/i',
			'/\/\*.*?\*\//s',
			'/--[^\r\n]*/i',
			'/\bEXEC\b/i',
			'/\bEXECUTE\b/i',
			'/\bSP_/i'
		);
		foreach ($suspiciousPatterns as $pattern) {
			if (preg_match($pattern, $condition)) {
				error_log("BigQuery: Blocked suspicious WHERE condition pattern: " . substr($condition, 0, 100) . "...");
				throw new InvalidArgumentException('WHERE condition contains prohibited SQL patterns');
			}
		}

		// Convert value backticks to proper quotes in comparison operations
		// Pattern: `column` = `value` -> `column` = 'value' (for strings) or `column` = value (for numbers)
		$condition = preg_replace_callback('/(`[^`]+`)\\s*=\\s*`([^`]+)`/', function ($matches) {
			$column = $matches[1];  // Keep column backticks: `id`
			$value = $matches[2];   // The value inside backticks: 123, Test Record Name, etc.

			// Check if value is numeric with enhanced validation
			if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
				// Numeric values don't need quotes
				return $column . ' = ' . $value;
			} else {
				// String values need single quotes and proper escaping
				$escaped = str_replace("'", "''", $value);  // BigQuery uses '' to escape single quotes
				return $column . " = '" . $escaped . "'";
			}
		}, $condition);

		// Handle COLLATE clauses - remove them as BigQuery doesn't support MySQL COLLATE syntax
		$condition = preg_replace('/\\s+COLLATE\\s+\\w+/i', '', $condition);

		return $condition;
	}
	function fields($table)
	{
		global $connection;
		try {
			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database)) {
				error_log("fields: No database (dataset) context available for table '$table'");
				return array();
			}
			$cacheKey = 'bq_fields_' . ($connection && isset($connection->projectId) ? $connection->projectId : 'default') . '_' . $database . '_' . $table;
			$cacheTime = 600;
			$cached = BigQueryCacheManager::get($cacheKey, $cacheTime);
			if ($cached !== false) {
				return $cached;
			}
			$dataset = ($connection && isset($connection->bigQueryClient)) ? $connection->bigQueryClient->dataset($database) : null;
			$tableObj = $dataset->table($table);
			try {
				$tableInfo = $tableObj->info();
			} catch (Exception $e) {
				error_log("Table '$table' does not exist in dataset '$database' or access error: " . $e->getMessage());
				return array();
			}
			if (!isset($tableInfo['schema']['fields'])) {
				error_log("No schema fields found for table '$table'");
				return array();
			}

			$schemaFields = $tableInfo['schema']['fields'];
			$fieldCount = count($schemaFields);

			// BigQueryのシステムテーブル（INFORMATION_SCHEMAなど）の大量フィールド対策
			// max_input_vars制限を回避するため、フィールド数を制限
			$maxFields = 1000; // 元の制限値に戻す
			if ($fieldCount > $maxFields) {
				// Field count limit warning - keeping for operational monitoring
				$schemaFields = array_slice($schemaFields, 0, $maxFields);
			}

			$fields = array();
			static $typeCache = array();
			foreach ($schemaFields as $field) {
				$bigQueryType = $field['type'] ?? 'STRING';
				if (!isset($typeCache[$bigQueryType])) {
					$typeCache[$bigQueryType] = BigQueryConfig::mapType($bigQueryType);
				}
				$adminerTypeInfo = $typeCache[$bigQueryType];
				$length = null;
				if (preg_match('/\((\d+(?:,\d+)?)\)/', $bigQueryType, $matches)) {
					$length = $matches[1];
				}
				$typeStr = $adminerTypeInfo['type'];
				if ($length !== null) {
					$typeStr .= "($length)";
				} elseif (isset($adminerTypeInfo['length']) && $adminerTypeInfo['length'] !== null) {
					$typeStr .= "(" . $adminerTypeInfo['length'] . ")";
				}
				$fields[$field['name']] = array(
					'field' => $field['name'],
					'type' => $typeStr,
					'full_type' => $typeStr,
					'null' => ($field['mode'] ?? 'NULLABLE') !== 'REQUIRED',
					'default' => null,
					'auto_increment' => false,
					'comment' => $field['description'] ?? '',
					'privileges' => array('select' => 1, 'insert' => 1, 'update' => 1, 'where' => 1, 'order' => 1)
				);
			}

			BigQueryCacheManager::set($cacheKey, $fields, $cacheTime);
			return $fields;
		} catch (Exception $e) {
			error_log("Error getting table fields for '$table': " . $e->getMessage());
			return array();
		}
	}
	function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false)
	{
		global $connection;
		try {
			$selectClause = ($select == array("*")) ? "*" : implode(", ", array_map(function ($col) {
				return BigQueryUtils::escapeIdentifier($col);
			}, $select));
			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database)) {
				return false;
			}
			$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
			$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
			$query = "SELECT $selectClause FROM $fullTableName";
			if (!empty($where)) {
				$whereClause = array();
				foreach ($where as $condition) {
					$processedCondition = convertAdminerWhereToBigQuery($condition);
					$whereClause[] = $processedCondition;
				}
				$query .= " WHERE " . implode(" AND ", $whereClause);
			}
			if (!empty($group)) {
				$query .= " GROUP BY " . implode(", ", array_map(function ($col) {
					return BigQueryUtils::escapeIdentifier($col);
				}, $group));
			}
			if (!empty($order)) {
				$orderClause = array();
				foreach ($order as $orderSpec) {
					if (preg_match('/^(.+?)\s+(DESC|ASC)$/i', $orderSpec, $matches)) {
						$orderClause[] = BigQueryUtils::escapeIdentifier($matches[1]) . " " . $matches[2];
					} else {
						$orderClause[] = BigQueryUtils::escapeIdentifier($orderSpec);
					}
				}
				$query .= " ORDER BY " . implode(", ", $orderClause);
			}
			if ($limit > 0) {
				$query .= " LIMIT " . (int) $limit;
				if ($page > 0) {
					$offset = $page * $limit;
					$query .= " OFFSET " . (int) $offset;
				}
			}
			if ($print) {
				if (function_exists('h')) {
					echo "<p><code>" . h($query) . "</code></p>";
				} else {
					echo "<p><code>" . htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code></p>";
				}
			}
			BigQueryUtils::logQuerySafely($query, "SELECT");
			return ($connection && method_exists($connection, 'query')) ? $connection->query($query) : false;
		} catch (Exception $e) {
			error_log("BigQuery select error: " . $e->getMessage());
			return false;
		}
	}
	if (!function_exists('convert_field')) {
		function convert_field(array $field)
		{
			return BigQueryUtils::generateFieldConversion($field);
		}
	}
	if (!function_exists('unconvert_field')) {
		function unconvert_field(array $field, $value)
		{
			// BigQueryは特別な値変換をサポートしないため、値をそのまま返す
			return $value;
		}
	}
	if (!function_exists('error')) {
		function error()
		{
			global $connection;
			if ($connection) {
				$errorMsg = ($connection && method_exists($connection, 'error')) ? $connection->error() : 'Connection error';
				if (function_exists('h')) {
					return h($errorMsg);
				} else {
					return htmlspecialchars($errorMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				}
			}
			return '';
		}
	}
	if (!function_exists('found_rows')) {
		function found_rows($table_status, $where)
		{
			if (!empty($where)) {
				return null;
			}
			if (isset($table_status['Rows']) && is_numeric($table_status['Rows'])) {
				return (int) $table_status['Rows'];
			}
			return null;
		}

		/**
		 * BigQuery INSERT文実行
		 * @param string $table テーブル名
		 * @param array $set 挿入データ（フィールド名 => 値の配列）
		 * @return bool 成功時true
		 */
		function insert($table, $set)
		{
			global $connection;
			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
				if (empty($database) || empty($table)) {
					return false;
				}

				// テーブルスキーマ情報を取得してフィールド型を確認
				$tableFields = fields($table);

				// フィールド名と値を分離
				$fields = array();
				$values = array();
				foreach ($set as $field => $value) {
					// フィールド名から既存のバッククォートを除去してから再エスケープ
					$cleanFieldName = trim(str_replace('`', '', $field));
					$cleanField = BigQueryUtils::escapeIdentifier($cleanFieldName);
					$fields[] = $cleanField;

					// 共通の型変換ヘルパーを使用
					$fieldInfo = $tableFields[$cleanFieldName] ?? null;
					$fieldType = $fieldInfo['type'] ?? 'string';
					$values[] = BigQueryUtils::convertValueForBigQuery($value, $fieldType);
				}

				// INSERT文組み立て
				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$fieldsStr = implode(", ", $fields);
				$valuesStr = implode(", ", $values);
				$insertQuery = "INSERT INTO $fullTableName ($fieldsStr) VALUES ($valuesStr)";

				BigQueryUtils::logQuerySafely($insertQuery, "INSERT");

				// BigQuery接続でクエリ実行
				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($insertQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				// ジョブステータス確認
				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery INSERT failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

					// 影響行数を記録
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 1;
					return true;
				}

				return false;
			} catch (ServiceException $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'INSERT_SERVICE_ERROR');
				return false;
			} catch (Exception $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'INSERT_ERROR');
				return false;
			}
		}

		/**
		 * BigQuery UPDATE文実行
		 * @param string $table テーブル名
		 * @param array $set 更新データ（フィールド名 => 値の配列）
		 * @param string $queryWhere WHERE条件（Adminer形式）
		 * @return bool 成功時true
		 */
		function update($table, $set, $queryWhere = '', $limit = 0)
		{
			global $connection;
			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
				if (empty($database) || empty($table)) {
					return false;
				}

				// テーブルスキーマ情報を取得してフィールド型を確認
				$tableFields = fields($table);

				// SET句の構築
				$setParts = array();
				foreach ($set as $field => $value) {
					// フィールド名から既存のバッククォートを除去してから再エスケープ
					$cleanFieldName = trim(str_replace('`', '', $field));
					$cleanField = BigQueryUtils::escapeIdentifier($cleanFieldName);

					// 共通の型変換ヘルパーを使用
					$fieldInfo = $tableFields[$cleanFieldName] ?? null;
					$fieldType = $fieldInfo['type'] ?? 'string';
					$convertedValue = BigQueryUtils::convertValueForBigQuery($value, $fieldType);
					$setParts[] = "$cleanField = $convertedValue";
				}

				if (empty($setParts)) {
					return false;
				}

				// WHERE句の処理（AdminerのqueryWhereをBigQueryに変換）
				$whereClause = '';
				if (!empty($queryWhere)) {
					$whereClause = 'WHERE ' . convertAdminerWhereToBigQuery($queryWhere);
				}

				// UPDATE文組み立て
				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$setStr = implode(", ", $setParts);
				$updateQuery = "UPDATE $fullTableName SET $setStr $whereClause";

				BigQueryUtils::logQuerySafely($updateQuery, "UPDATE");

				// BigQuery接続でクエリ実行
				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($updateQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				// ジョブステータス確認
				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery UPDATE failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

					// 影響行数を記録
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 0;
					return true;
				}

				return false;
			} catch (ServiceException $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'UPDATE_SERVICE_ERROR');
				return false;
			} catch (Exception $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'UPDATE_ERROR');
				return false;
			}
		}

		/**
		 * BigQuery DELETE文実行
		 * @param string $table テーブル名
		 * @param string $queryWhere WHERE条件（Adminer形式）
		 * @param int $limit 削除する行数制限
		 * @return bool 成功時true
		 */
		function delete($table, $queryWhere = '', $limit = 0)
		{
			global $connection;
			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
				if (empty($database) || empty($table)) {
					return false;
				}

				// WHERE句の処理（AdminerのqueryWhereをBigQueryに変換）
				$whereClause = '';
				if (!empty($queryWhere) && trim($queryWhere) !== '') {
					$whereClause = 'WHERE ' . convertAdminerWhereToBigQuery($queryWhere);
				} else {
					// WHERE句がない場合は安全のため削除を制限
					throw new InvalidArgumentException("BigQuery: DELETE without WHERE clause is not allowed. Please specify WHERE conditions to avoid accidental data deletion.");
				}

				// DELETE文組み立て
				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$deleteQuery = "DELETE FROM $fullTableName $whereClause";

				BigQueryUtils::logQuerySafely($deleteQuery, "DELETE");

				// BigQuery接続でクエリ実行
				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($deleteQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				// ジョブステータス確認
				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery DELETE failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

					// 影響行数を記録
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 0;
					return true;
				}

				return false;
			} catch (ServiceException $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'DELETE_SERVICE_ERROR');
				return false;
			} catch (Exception $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'DELETE_ERROR');
				return false;
			}
		}

		/**
		 * BigQuery用最後に挿入されたID取得
		 * BigQueryにはAUTO_INCREMENTがないため、常にnullを返す
		 * @return null
		 */
		function last_id()
		{
			// BigQueryにはAUTO_INCREMENTの概念がないため、nullを返す
			return null;
		}

		/**
		 * BigQuery データセット作成
		 * @param string $database データセット名
		 * @param string $collation 照合順序（BigQueryでは未使用）
		 * @return bool 作成成功時true
		 */
		function create_database($database, $collation)
		{
			global $connection;
			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				// BigQueryのデータセット作成

				// 正しいBigQuery PHP SDKのAPIを使用
				$dataset = $connection->bigQueryClient->createDataset($database, [
					'location' => $connection->config['location'] ?? 'US'
				]);

				return true;

			} catch (ServiceException $e) {
				$message = $e->getMessage();
				if (strpos($message, 'Already Exists') !== false) {
					error_log("BigQuery: Dataset '$database' already exists");
					return false;
				}
				error_log("BigQuery: Dataset creation failed - " . $message);
				return false;
			} catch (Exception $e) {
				error_log("BigQuery: Dataset creation error - " . $e->getMessage());
				return false;
			}
		}

		/**
		 * BigQuery テーブル作成・変更関数
		 * @param string $table 既存テーブル名（空の場合は新規作成）
		 * @param string $name 新しいテーブル名
		 * @param array $fields フィールド定義配列
		 * @param array $foreign 外部キー（BigQueryでは未サポート）
		 * @param string $comment テーブルコメント
		 * @param string $engine エンジン（BigQueryでは固定）
		 * @param string $collation 照合順序（BigQueryでは未サポート）
		 * @param string $auto_increment 自動増分（BigQueryでは未サポート）
		 * @param string $partitioning パーティショニング（BigQueryでは未サポート）
		 * @return bool 作成成功時true
		 */
		function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning)
		{
			global $connection;

			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				// 新規テーブル作成の場合（$table が空）
				if ($table == "") {
					// 現在のデータセットを取得
					$database = $_GET['db'] ?? $connection->datasetId ?? '';
					if (empty($database)) {
						return false;
					}

					$dataset = $connection->bigQueryClient->dataset($database);

					// フィールド定義をBigQueryスキーマ形式に変換
					$schemaFields = array();
					foreach ($fields as $field) {
						if (isset($field[1]) && is_array($field[1])) {
							// BigQuery用にフィールド名からバッククオートを削除
							$fieldName = trim(str_replace('`', '', $field[1][0] ?? ''));
							$fieldType = trim($field[1][1] ?? 'STRING');
							$fieldMode = ($field[1][3] ?? false) ? 'REQUIRED' : 'NULLABLE';

							if (!empty($fieldName)) {
								$schemaFields[] = array(
									'name' => $fieldName,
									'type' => strtoupper($fieldType),
									'mode' => $fieldMode
								);
							}
						}
					}

					if (empty($schemaFields)) {
						return false;
					}

					// テーブル作成オプション
					$tableOptions = array(
						'schema' => array('fields' => $schemaFields)
					);

					// コメントがある場合は追加
					if (!empty($comment)) {
						$tableOptions['description'] = $comment;
					}

					// BigQuery用にテーブル名からバッククオートを削除
					$cleanTableName = trim(str_replace('`', '', $name));

					// BigQueryテーブル作成実行
					$table = $dataset->createTable($cleanTableName, $tableOptions);

					return true;

				} else {
					// テーブル変更（既存テーブルの更新）
					// Table modification not yet implemented
					return false;
				}

			} catch (ServiceException $e) {
				$message = $e->getMessage();
				if (strpos($message, 'Already Exists') !== false) {
					error_log("BigQuery: Table '$name' already exists");
					return false;
				}
				error_log("BigQuery: Table creation failed - " . $message);
				return false;
			} catch (Exception $e) {
				error_log("BigQuery: Table creation error - " . $e->getMessage());
				return false;
			}
		}

		/**
		 * BigQuery テーブル移動機能（未対応）
		 * BigQueryは異なるデータセット間でのテーブル移動をサポートしていない
		 * @param array $tables テーブル一覧
		 * @param array $views ビュー一覧
		 * @param string $target 移動先データセット
		 * @return bool 常にfalse（未対応）
		 */
		function move_tables($tables, $views, $target)
		{
			// BigQueryは異なるデータセット間でのテーブル移動を直接サポートしていない
			// CREATE TABLE AS SELECT + DROP TABLE の組み合わせが必要だが、
			// 複雑な操作となるため現在は未実装
			return false;
		}



		// search_tables関数は削除（Adminerコアと重複のため）
// 代わりにDriverクラス内で処理

		// analyze_table は グローバル関数として定義済み

		// optimize_table は グローバル関数として定義済み

	}
}

if (!function_exists('show_unsupported_feature_message')) {
	/**
	 * BigQuery未対応機能のエラー表示（グローバル関数版）
	 * @param string $feature 機能名
	 * @param string $reason 対応していない理由
	 * @return void
	 */
	function show_unsupported_feature_message($feature, $reason = '')
	{
		// BigQuery未対応機能メッセージの定義
		$unsupported_messages = array(
			'move_tables' => 'BigQuery does not support moving tables between datasets directly. Use CREATE TABLE AS SELECT + DROP TABLE instead.',
			'schema' => 'BigQuery uses datasets instead of schemas. Please use the dataset view for schema information.',
			'import' => 'BigQuery import functionality is not yet implemented. Please use the BigQuery console or API for bulk imports.',
			'export' => 'BigQuery export functionality is not yet implemented. Please use the BigQuery console or API for exports.',
			'analyze' => 'BigQuery does not support ANALYZE TABLE operations as it automatically optimizes queries.',
			'optimize' => 'BigQuery automatically optimizes storage and query performance.',
			'check' => 'BigQuery does not support CHECK TABLE operations as data integrity is automatically maintained.',
			'repair' => 'BigQuery does not support REPAIR TABLE operations as storage is managed automatically.',
			'search_tables' => 'Cross-table search is not yet implemented for BigQuery.',
		);

		$message = $reason ?: ($unsupported_messages[$feature] ?? 'This feature is not supported in BigQuery driver.');

		echo '<div class="error">';
		echo '<h3>Feature Not Supported: ' . htmlspecialchars($feature) . '</h3>';
		echo '<p>' . htmlspecialchars($message) . '</p>';
		echo '<p><a href="javascript:history.back()">← Go Back</a></p>';
		echo '</div>';
	}
}

if (!function_exists('query')) {
	function query($query)
	{
		global $connection;
		if ($connection && method_exists($connection, 'query')) {
			return $connection->query($query);
		}
		return false;
	}
}


if (!function_exists('schema')) {
	/**
	 * BigQuery Database schema機能
	 * BigQueryではschemaの代わりにデータセット情報を表示
	 */
	function schema()
	{
		show_unsupported_feature_message('schema', 'BigQuery uses datasets instead of traditional schemas. Dataset information is available in the main database view.');
		return;
	}
}


if (!function_exists('import_sql')) {
	/**
	 * BigQuery Import機能
	 * SQLファイルインポート（BigQueryでは未実装）
	 */
	function import_sql($file)
	{
		show_unsupported_feature_message('import', 'BigQuery import functionality is not yet implemented. Please use the BigQuery console or API for bulk imports.');
		return false;
	}


	/**
	 * テーブル削除機能 (TRUNCATE TABLE)
	 * @param string $table テーブル名
	 * @return bool 成功時true、失敗時false
	 */
	function truncate_table($table)
	{
		global $connection;

		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		try {
			// 現在のデータセットを取得
			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database) || empty($table)) {
				return false;
			}

			// BigQueryでは TRUNCATE TABLE がサポートされているため実装
			$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
			$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
			$query = "TRUNCATE TABLE $fullTableName";
			
			BigQueryUtils::logQuerySafely($query, "TRUNCATE");
			$result = $connection->query($query);
			return $result !== false;
		} catch (Exception $e) {
			if ($connection) {
				$connection->error = "TRUNCATE TABLE failed: " . $e->getMessage();
			}
			BigQueryUtils::logQuerySafely($e->getMessage(), 'TRUNCATE_ERROR');
			return false;
		}
	}
}

// drop_tables関数は既にAdminerコアで定義されているため、BigQuery固有の実装は不要
// 必要に応じてDriverクラス内でカスタム実装する

if (!function_exists('check_table')) {
	/**
	 * テーブルチェック機能
	 * @param string $table テーブル名
	 * @return bool 成功時true、失敗時false (BigQueryでは未対応)
	 */
	function check_table($table)
	{
		show_unsupported_feature_message('check');
		return false;
	}
}

if (!function_exists('optimize_table')) {
	/**
	 * テーブル最適化機能
	 * @param string $table テーブル名
	 * @return bool 成功時true、失敗時false (BigQueryでは未対応)
	 */
	function optimize_table($table)
	{
		show_unsupported_feature_message('optimize');
		return false;
	}
}

if (!function_exists('repair_table')) {
	/**
	 * テーブル修復機能
	 * @param string $table テーブル名
	 * @return bool 成功時true、失敗時false (BigQueryでは未対応)
	 */
	function repair_table($table)
	{
		show_unsupported_feature_message('repair');
		return false;
	}
}

if (!function_exists('analyze_table')) {

	/**
	 * テーブル分析機能
	 * @param string $table テーブル名
	 * @return bool 成功時true、失敗時false (BigQueryでは未対応)
	 */
	function analyze_table($table)
	{
		show_unsupported_feature_message('analyze');
		return false;
	}
}

// dump_csv関数はAdminerコアに存在するため、BigQuery用のカスタム実装は不要
// エクスポート機能が必要な場合は、support()でexportをfalseに設定することで無効化される

/**
 * ====================================
 * CONSOLIDATED PLUGIN CLASSES
 * ====================================
 *
 * The following classes were originally separate plugin files:
 * - AdminerLoginBigQuery (from plugins/login-bigquery.php)
 * - AdminerBigQueryCSS (from plugins/bigquery-css.php)
 *
 * They have been consolidated into this single file for better organization.
 */

/**
 * BigQuery Login Plugin
 * Handles BigQuery authentication and form customization
 */
class AdminerLoginBigQuery extends Adminer\Plugin
{
	protected $config;

	function __construct($config = array())
	{
		$this->config = $config;
		$this->initializeDriverSelection();
	}

	private function initializeDriverSelection()
	{
		if (!isset($_POST["auth"])) {
			return;
		}

		$_POST["auth"]["driver"] = 'bigquery';
	}

	function credentials()
	{
		$server = $this->getProjectId();

		return array($server, 'bigquery-service-account', 'service-account-auth');
	}

	private function getProjectId()
	{
		return $_GET["server"] ??
			$_POST["auth"]["server"] ??
			$this->config['project_id'];
	}

	function login($login, $password)
	{
		return true;
	}

	function loginFormField($name, $heading, $value)
	{
		$fieldHandlers = array(
			'driver' => fn() => $this->renderDriverField($heading),
			'server' => fn() => $this->renderProjectIdField(),
			'username' => fn() => $this->renderHiddenField('username'),
			'password' => fn() => $this->renderHiddenField('password'),
			'db' => fn() => $this->renderHiddenField('db')
		);

		return isset($fieldHandlers[$name]) ? $fieldHandlers[$name]() : '';
	}

	private function renderDriverField($heading)
	{
		return $heading . '<select name="auth[driver]" readonly><option value="bigquery" selected>Google BigQuery</option></select>' . "\n";
	}

	private function renderProjectIdField()
	{
		$default_value = htmlspecialchars($this->getProjectId());
		return '<tr><th>Project ID</th><td><input name="auth[server]" value="' . $default_value . '" title="GCP Project ID" placeholder="your-project-id" autocapitalize="off" required></td></tr>' . "\n";
	}

	private function renderHiddenField($fieldName)
	{
		$defaultValues = array(
			'username' => 'bigquery-service-account',
			'password' => 'service-account-auth',
			'db' => ''
		);
		$value = $defaultValues[$fieldName] ?? '';
		return '<input type="hidden" name="auth[' . $fieldName . ']" value="' . htmlspecialchars($value) . '">' . "\n";
	}

	function loginForm()
	{
		echo "<style>";
		echo ".layout tr:has(input[type='hidden']) { display: none; }";
		echo "</style>";
	}

	function operators()
	{
		return array(
			"=", "!=", "<>", "<", "<=", ">", ">=",
			"IN", "NOT IN", "IS NULL", "IS NOT NULL",
			"LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
		);
	}

	protected $translations = array(
		'en' => array('' => 'BigQuery authentication with service account credentials'),
		'ja' => array('' => 'サービスアカウント認証情報によるBigQuery認証'),
	);
}

/**
 * BigQuery CSS Plugin
 * Injects BigQuery-specific CSS when the BigQuery driver is detected
 */
class AdminerBigQueryCSS extends \Adminer\Plugin
{
    function head($dark = null)
    {
        // デバッグ用：常にコメントを出力してプラグインが動作していることを確認
        echo "<!-- BigQueryCSS Plugin loaded -->\n";

        // BigQueryドライバーが使用されている場合のCSS追加
        $isDefined = defined('DRIVER');
        $isAdminerDefined = defined('Adminer\\DRIVER');
        $driverValue = $isDefined ? constant('DRIVER') : 'not_defined';
        $adminerDriverValue = $isAdminerDefined ? constant('Adminer\\DRIVER') : 'not_defined';

        echo "<!-- DRIVER defined: " . ($isDefined ? 'true' : 'false') . ", value: $driverValue -->\n";
        echo "<!-- Adminer\\DRIVER defined: " . ($isAdminerDefined ? 'true' : 'false') . ", value: $adminerDriverValue -->\n";

        if ((defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery')) {
            echo "<!-- BigQuery driver detected -->\n";

            // BigQueryドライバーのCSSメソッドを呼び出し
            if (class_exists('Adminer\\Driver')) {
                echo "<!-- Adminer\\Driver class exists -->\n";
                $driver = new \Adminer\Driver();
                if (method_exists($driver, 'css')) {
                    echo "<!-- Driver css method exists -->\n";
                    // DriverのCSS関数の戻り値を取得してデバッグ
                    $cssContent = $driver->css();
                    echo "<!-- CSS content length: " . strlen($cssContent) . " -->\n";
                    echo "<!-- CSS content preview: " . substr($cssContent, 0, 100) . "... -->\n";
                    // DriverのCSS関数はHTML文字列を返すので、そのまま出力
                    echo $cssContent;
                } else {
                    echo "<!-- Driver css method does not exist -->\n";
                }
            } else {
                echo "<!-- Adminer\\Driver class does not exist -->\n";
            }
        } else {
            echo "<!-- BigQuery driver not detected -->\n";
        }
    }
}
