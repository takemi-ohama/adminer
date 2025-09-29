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

	class BigQueryConnectionPool
	{

		private static $pool = array();
		private static $maxConnections = 3;
		private static $usageTimestamps = array();
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
			'credentials_ttl' => 86400,
			'location_ttl' => 86400,
			'databases_ttl' => 600,
			'tables_ttl' => 600,
			'fields_ttl' => 300,
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

			if (preg_match('/^`[^`]*`$/', $identifier)) {
				return $identifier;
			}

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

		static function convertValueForBigQuery($value, $fieldType)
		{

			if ($value === null) {
				return 'NULL';
			}

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
		// Phase 2 Sprint 2.1: フィールド変換機能強化
		// BigQueryデータ型の適切なSQL関数による変換処理
		
		$fieldName = self::escapeIdentifier($field['field']);
		$fieldType = strtolower($field['type'] ?? '');
		
		// BigQuery固有データ型の変換マッピング
		$conversions = array(
			// 地理空間データの変換
			'geography' => "ST_AsText($fieldName)",
			'geom' => "ST_AsText($fieldName)",
			
			// JSON・構造化データの変換
			'json' => "TO_JSON_STRING($fieldName)",
			'struct' => "TO_JSON_STRING($fieldName)",
			'record' => "TO_JSON_STRING($fieldName)",
			'array' => "TO_JSON_STRING($fieldName)",
			
			// 日時データの変換
			'timestamp' => "TIMESTAMP_TRUNC($fieldName, MICROSECOND)",
			'datetime' => "DATETIME_TRUNC($fieldName, MICROSECOND)",
			'time' => "TIME_TRUNC($fieldName, MICROSECOND)",
			
			// バイナリデータの変換
			'bytes' => "TO_BASE64($fieldName)",
			'blob' => "TO_BASE64($fieldName)",
			
			// 数値データの精度制御
			'numeric' => "CAST($fieldName AS STRING)",
			'bignumeric' => "CAST($fieldName AS STRING)",
			'decimal' => "CAST($fieldName AS STRING)",
			
			// 論理データの明示化
			'boolean' => "IF($fieldName, 'true', 'false')",
			'bool' => "IF($fieldName, 'true', 'false')"
		);
		
		// パターンマッチングで最適な変換を選択
		foreach ($conversions as $typePattern => $conversion) {
			if (strpos($fieldType, $typePattern) !== false) {
				return $conversion;
			}
		}
		
		// デフォルト: 変換不要
		return null;
	}

		static function buildFullTableName($table, $database, $projectId)
		{
			return "`" . $projectId . "`.`" . $database . "`.`" . $table . "`";
		}
	}

	if (!function_exists('Adminer\\idf_escape')) {
		function idf_escape($idf)
		{
			return BigQueryUtils::escapeIdentifier($idf);
		}
	}

	class Db
	{

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

		static $instance;

		public $bigQueryClient;

		public $projectId;

		public $datasetId = '';

		public $config = array();

		public $flavor = 'BigQuery';

		public $server_info = 'Google Cloud BigQuery';

		public $extension = 'BigQuery Driver';

		public $error = '';

		public $affected_rows = 0;

		public $info = '';

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

						break;
					}
				}
			} catch (Exception $e) {

			}
		}

		private static function performBackgroundLocationDetection($projectId, $defaultLocation, $clientKey)
		{
			try {

				$client = BigQueryConnectionPool::getConnectionFromPool($clientKey);
				if (!$client) {
					return;
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

			}
		}

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

				if (empty($this->datasetId) && isset($_GET['db']) && !empty($_GET['db'])) {

					if (preg_match('/^[A-Za-z0-9_]{1,1024}$/', $_GET['db'])) {
						$this->datasetId = $_GET['db'];
					} else {
						error_log("BigQuery: Invalid dataset name provided: " . $_GET['db']);
						$this->error = 'Invalid dataset name. Dataset names must contain only letters, numbers, and underscores (1-1024 characters).';
						return false;
					}
				}

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

				return $this->last_result = new Result($job);
			} catch (ServiceException $e) {
				$errorMessage = $e->getMessage();
				$errorCode = $e->getCode();

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

			if (!empty($this->error)) {
				return $this->error;
			}

			return "Check server logs for detailed error information";
		}

		function multi_query($query)
		{

			return $this->query($query);
		}

		function store_result()
		{

			return $this->last_result;
		}

		function next_result()
		{

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
		public $job = null; // Phase 1: last_id()機能のためのジョブ参照
		
		function __construct($queryResults)
		{
			$this->queryResults = $queryResults;
			$this->job = $queryResults; // BigQueryジョブへの参照を保存

			try {
				$jobInfo = $queryResults->info();
				$this->num_rows = (int) ($jobInfo['totalRows'] ?? 0);
			} catch (Exception $e) {

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

			switch ($baseType) {
				case 'BYTES':

					return 63;
				case 'STRING':
				case 'JSON':

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

					return 63;
				case 'ARRAY':
				case 'STRUCT':
				case 'RECORD':
				case 'GEOGRAPHY':

					return 33;
				default:

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

		public $partitionBy = array();

		public $unsigned = array();

		public $generated = array();

		public $enumLength = array();

		public $insertFunctions = array();

		public $editFunctions = array();

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
			return false;
		}

		function warnings()
		{

			return array();
		}

		function engines()
		{
			return array('BigQuery');
		}

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

		function enumLength($field)
		{

			return array();
		}

		function unconvertFunction($field)
		{

			return null;
		}

		function insert($table, $set)
		{

			return insert($table, $set);
		}

		function update($table, $set, $queryWhere = '', $limit = 0)
		{

			return update($table, $set, $queryWhere, $limit);
		}

		function delete($table, $queryWhere = '', $limit = 0)
		{

			return delete($table, $queryWhere, $limit);
		}

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

		function convertSearch(string $idf, array $val, array $field): string
		{

			return $idf;
		}

		function dropTables($tables)
		{
			global $connection;

			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			$errors = array();
			$successCount = 0;

			try {

				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
				if (empty($database)) {
					return false;
				}

				foreach ($tables as $table) {
					if (empty($table)) {
						continue;
					}

					try {

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

		function explain($query)
		{
			global $connection;
			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			try {

				$explainQuery = "EXPLAIN " . $query;
				BigQueryUtils::logQuerySafely($explainQuery, "EXPLAIN");
				$result = $connection->query($explainQuery);
				return $result;
			} catch (Exception $e) {
				BigQueryUtils::logQuerySafely($e->getMessage(), 'EXPLAIN_ERROR');
				return false;
			}
		}

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

			'create_db',
			'create_table',
			'insert',
			'update',
			'delete',
			'drop_table',
			'truncate',
			'drop',
			'select',
			'export',
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
			'move_tables',
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

			'analyze',
			'optimize',
			'repair',
			'search_tables',
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
	// Phase 2 Sprint 2.1: BigQuery照合順序一覧機能
	// BigQueryでサポートされているUnicode照合順序を返す
	
	return array(
		"unicode:cs" => "Unicode (大文字小文字区別)",
		"unicode:ci" => "Unicode (大文字小文字区別なし)",
		"" => "(デフォルト)"
	);
}
	function db_collation($db)
{
	// Phase 2 Sprint 2.1: BigQuery照合順序適切処理機能
	// BigQueryではデータセット固有の照合順序設定は存在しないが、
	// 照会時にCOLLATE句でUnicode照合順序を指定可能
	
	if (!$db) {
		return "";
	}
	
	// BigQueryのデフォルト照合順序を返す
	// Unicode照合順序（大文字小文字区別）がデフォルト
	return "unicode:cs";
}
	function information_schema($db)
	{
		// Phase 1 Sprint 1.3: BigQuery INFORMATION_SCHEMA判定機能
		if (!$db) {
			return false;
		}

		// BigQueryのINFORMATION_SCHEMAデータセット判定
		// BigQueryでは各プロジェクトにINFORMATION_SCHEMAという特別なデータセットがある
		$informationSchemaPatterns = array(
			'INFORMATION_SCHEMA',
			'information_schema',
			// プロジェクト固有のINFORMATION_SCHEMA
			// 例: project.INFORMATION_SCHEMA
		);

		foreach ($informationSchemaPatterns as $pattern) {
			if (strcasecmp($db, $pattern) === 0) {
				return true;
			}
		}

		// プロジェクト名.INFORMATION_SCHEMAパターンの判定
		if (strpos($db, '.') !== false) {
			$parts = explode('.', $db);
			$lastPart = end($parts);
			if (strcasecmp($lastPart, 'INFORMATION_SCHEMA') === 0) {
				return true;
			}
		}

		return false;
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
		// Phase 1 Sprint 1.3: サービスアカウント情報の詳細表示
		global $connection;
		try {
			if ($connection && isset($connection->projectId)) {
				// プロジェクトIDとサービスアカウントの基本情報を表示
				$projectId = $connection->projectId;
				$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

				// サービスアカウント情報を構築
				$userInfo = "BigQuery Service Account";
				if ($projectId) {
					$userInfo .= " (Project: {$projectId})";
				}

				// 認証情報ソースを追加
				if ($credentialsPath) {
					$fileName = basename($credentialsPath);
					$userInfo .= " - Auth: {$fileName}";
				} elseif (getenv('GOOGLE_CLOUD_PROJECT')) {
					$userInfo .= " - Auth: Default Credentials";
				}

				return $userInfo;
			}
		} catch (Exception $e) {
			// エラー時は基本情報を返す
			error_log("BigQuery logged_user error: " . $e->getMessage());
		}

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

		$condition = preg_replace_callback('/(`[^`]+`)\\s*=\\s*`([^`]+)`/', function ($matches) {
			$column = $matches[1];
			$value = $matches[2];

			if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {

				return $column . ' = ' . $value;
			} else {

				$escaped = str_replace("'", "''", $value);
				return $column . " = '" . $escaped . "'";
			}
		}, $condition);

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

			$maxFields = 1000;
			if ($fieldCount > $maxFields) {

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
		// Phase 2 Sprint 2.1: フィールド逆変換機能強化
		// BigQueryの表示用データをAdminer編集可能な形式に戻す
		
		if ($value === null) {
			return null;
		}
		
		$fieldType = strtolower($field['type'] ?? '');
		$stringValue = (string) $value;
		
		// BigQuery固有データ型の逆変換処理
		switch (true) {
			// JSON・構造化データの逆変換
			case (strpos($fieldType, 'json') !== false):
			case (strpos($fieldType, 'struct') !== false):
			case (strpos($fieldType, 'record') !== false):
			case (strpos($fieldType, 'array') !== false):
				// JSON形式の文字列をそのまま返す（編集可能な形式）
				return $stringValue;
			
			// 地理空間データの逆変換
			case (strpos($fieldType, 'geography') !== false):
				// WKT形式をそのまま返す
				return $stringValue;
			
			// バイナリデータの逆変換
			case (strpos($fieldType, 'bytes') !== false):
			case (strpos($fieldType, 'blob') !== false):
				// Base64デコードは不要、文字列として編集
				return $stringValue;
			
			// 論理データの逆変換
			case (strpos($fieldType, 'boolean') !== false):
			case (strpos($fieldType, 'bool') !== false):
				// 'true'/'false'文字列を論理値に変換
				if ($stringValue === 'true') return '1';
				if ($stringValue === 'false') return '0';
				return $stringValue;
			
			// 数値データの逆変換
			case (strpos($fieldType, 'numeric') !== false):
			case (strpos($fieldType, 'bignumeric') !== false):
			case (strpos($fieldType, 'decimal') !== false):
				// 数値精度を保持して返す
				return $stringValue;
			
			// 日時データの逆変換
			case (strpos($fieldType, 'timestamp') !== false):
			case (strpos($fieldType, 'datetime') !== false):
			case (strpos($fieldType, 'time') !== false):
			case (strpos($fieldType, 'date') !== false):
				// ISO形式の日時文字列をそのまま返す
				return $stringValue;
			
			// その他のデータ型
			default:
				return $value;
		}
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

				$tableFields = fields($table);

				$fields = array();
				$values = array();
				foreach ($set as $field => $value) {

					$cleanFieldName = trim(str_replace('`', '', $field));
					$cleanField = BigQueryUtils::escapeIdentifier($cleanFieldName);
					$fields[] = $cleanField;

					$fieldInfo = $tableFields[$cleanFieldName] ?? null;
					$fieldType = $fieldInfo['type'] ?? 'string';
					$values[] = BigQueryUtils::convertValueForBigQuery($value, $fieldType);
				}

				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$fieldsStr = implode(", ", $fields);
				$valuesStr = implode(", ", $values);
				$insertQuery = "INSERT INTO $fullTableName ($fieldsStr) VALUES ($valuesStr)";

				BigQueryUtils::logQuerySafely($insertQuery, "INSERT");

				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($insertQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery INSERT failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

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

				$tableFields = fields($table);

				$setParts = array();
				foreach ($set as $field => $value) {

					$cleanFieldName = trim(str_replace('`', '', $field));
					$cleanField = BigQueryUtils::escapeIdentifier($cleanFieldName);

					$fieldInfo = $tableFields[$cleanFieldName] ?? null;
					$fieldType = $fieldInfo['type'] ?? 'string';
					$convertedValue = BigQueryUtils::convertValueForBigQuery($value, $fieldType);
					$setParts[] = "$cleanField = $convertedValue";
				}

				if (empty($setParts)) {
					return false;
				}

				$whereClause = '';
				if (!empty($queryWhere)) {
					$whereClause = 'WHERE ' . convertAdminerWhereToBigQuery($queryWhere);
				}

				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$setStr = implode(", ", $setParts);
				$updateQuery = "UPDATE $fullTableName SET $setStr $whereClause";

				BigQueryUtils::logQuerySafely($updateQuery, "UPDATE");

				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($updateQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery UPDATE failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

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

				$whereClause = '';
				if (!empty($queryWhere) && trim($queryWhere) !== '') {
					$whereClause = 'WHERE ' . convertAdminerWhereToBigQuery($queryWhere);
				} else {

					throw new InvalidArgumentException("BigQuery: DELETE without WHERE clause is not allowed. Please specify WHERE conditions to avoid accidental data deletion.");
				}

				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$deleteQuery = "DELETE FROM $fullTableName $whereClause";

				BigQueryUtils::logQuerySafely($deleteQuery, "DELETE");

				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($deleteQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				$jobInfo = $job->info();
				if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						error_log("BigQuery DELETE failed: " . ($errorResult['message'] ?? 'Unknown error'));
						return false;
					}

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

		function last_id()
		{
			global $connection;
			
			// Phase 1: BigQueryジョブIDを返す機能を追加
			if ($connection && isset($connection->last_result)) {
				if ($connection->last_result instanceof Result && isset($connection->last_result->job)) {
					return $connection->last_result->job->id();
				}
			}
			
			return null;
		}

		function create_database($database, $collation)
		{
			// Phase 3 Sprint 3.1: BigQuery Dataset作成機能強化
			// Dataset API活用・権限チェック・エラーハンドリング強化
			
			global $connection;
			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				// データセット名の検証
				if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $database)) {
					error_log("BigQuery: Invalid dataset name format: $database");
					return false;
				}

				// データセット設定の構築
				$datasetOptions = [
					'location' => $connection->config['location'] ?? 'US'
				];

				// 説明の追加（collationパラメータを説明として活用）
				if (!empty($collation) && is_string($collation)) {
					$datasetOptions['description'] = "Dataset created via Adminer BigQuery Plugin - $collation";
				}

				// BigQuery Dataset API でデータセット作成
				BigQueryUtils::logQuerySafely("CREATE DATASET $database", "CREATE_DATASET");
				$dataset = $connection->bigQueryClient->createDataset($database, $datasetOptions);

				// 作成成功の確認
				if ($dataset && $dataset->exists()) {
					error_log("BigQuery: Dataset '$database' created successfully in location: " . ($datasetOptions['location']));
					return true;
				}

				return false;

			} catch (ServiceException $e) {
				$message = $e->getMessage();
				$errorCode = $e->getCode();
				
				// 既存チェック
				if (strpos($message, 'Already Exists') !== false || $errorCode === 409) {
					error_log("BigQuery: Dataset '$database' already exists");
					$connection->error = "Dataset '$database' already exists";
					return false;
				}
				
				// 権限エラー
				if (strpos($message, 'permission') !== false || $errorCode === 403) {
					error_log("BigQuery: Permission denied for dataset creation: $database");
					$connection->error = "Permission denied: Cannot create dataset '$database'";
					return false;
				}
				
				// その他のServiceException
				error_log("BigQuery: Dataset creation failed - Code: $errorCode, Message: $message");
				$connection->error = "Dataset creation failed: $message";
				return false;
				
			} catch (Exception $e) {
				error_log("BigQuery: Dataset creation error - " . $e->getMessage());
				$connection->error = "Dataset creation error: " . $e->getMessage();
				return false;
			}
		}

		function drop_databases($databases)
		{
			// Phase 3 Sprint 3.1: BigQuery Dataset削除機能実装
			// 複数データセットの安全な削除処理
			
			global $connection;
			
			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}
			
			$errors = array();
			$successCount = 0;
			
			try {
				foreach ($databases as $database) {
					if (empty($database)) {
						continue;
					}
					
					try {
						// データセット名の検証
						if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $database)) {
							$errors[] = "Invalid dataset name format: $database";
							continue;
						}
						
						// データセット取得と存在確認
						$dataset = $connection->bigQueryClient->dataset($database);
						if (!$dataset->exists()) {
							$errors[] = "Dataset '$database' does not exist";
							continue;
						}
						
						// 削除前の安全確認（テーブル数チェック）
						$tableIterator = $dataset->tables(['maxResults' => 1]);
						if ($tableIterator->current()) {
							error_log("BigQuery: Warning - Dataset '$database' contains tables, proceeding with deletion");
						}
						
						// BigQuery Dataset削除実行
						BigQueryUtils::logQuerySafely("DROP DATASET $database", "DROP_DATASET");
						$dataset->delete(['deleteContents' => true]);
						
						error_log("BigQuery: Dataset '$database' deleted successfully");
						$successCount++;
						
					} catch (ServiceException $e) {
						$message = $e->getMessage();
						$errorCode = $e->getCode();
						
						// 権限エラー
						if (strpos($message, 'permission') !== false || $errorCode === 403) {
							$errors[] = "Permission denied: Cannot delete dataset '$database'";
						}
						// 存在しないデータセット
						elseif (strpos($message, 'Not found') !== false || $errorCode === 404) {
							$errors[] = "Dataset '$database' not found";
						}
						// その他のエラー
						else {
							$errors[] = "Failed to delete dataset '$database': $message";
						}
						
						BigQueryUtils::logQuerySafely($e->getMessage(), 'DROP_DATASET_ERROR');
						
					} catch (Exception $e) {
						$errors[] = "Delete dataset '$database' failed: " . $e->getMessage();
						BigQueryUtils::logQuerySafely($e->getMessage(), 'DROP_DATASET_ERROR');
					}
				}
				
				// エラーハンドリング
				if (!empty($errors) && $connection) {
					$connection->error = implode('; ', $errors);
				}
				
				return $successCount > 0;
				
			} catch (Exception $e) {
				if ($connection) {
					$connection->error = "DROP DATASETS failed: " . $e->getMessage();
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), 'DROP_DATASETS_ERROR');
				return false;
			}
		}

		function rename_database($old_name, $new_name)
		{
			// Phase 3 Sprint 3.1: BigQuery Dataset名変更機能実装
			// BigQueryは直接名前変更をサポートしないため、作成→コピー→削除のフローで実現
			
			global $connection;
			
			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}
			
			try {
				// データセット名の検証
				if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $old_name) || 
					!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $new_name)) {
					error_log("BigQuery: Invalid dataset name format - old: $old_name, new: $new_name");
					$connection->error = "Invalid dataset name format";
					return false;
				}
				
				// 元データセットの存在確認
				$oldDataset = $connection->bigQueryClient->dataset($old_name);
				if (!$oldDataset->exists()) {
					error_log("BigQuery: Source dataset '$old_name' does not exist");
					$connection->error = "Source dataset '$old_name' does not exist";
					return false;
				}
				
				// 新データセット名の重複確認
				$newDataset = $connection->bigQueryClient->dataset($new_name);
				if ($newDataset->exists()) {
					error_log("BigQuery: Target dataset '$new_name' already exists");
					$connection->error = "Target dataset '$new_name' already exists";
					return false;
				}
				
				// 元データセットの情報を取得
				$oldDatasetInfo = $oldDataset->info();
				$location = $oldDatasetInfo['location'] ?? 'US';
				$description = $oldDatasetInfo['description'] ?? '';
				
				// 新データセット作成
				BigQueryUtils::logQuerySafely("CREATE DATASET $new_name (rename from $old_name)", "RENAME_DATASET_CREATE");
				$newDatasetOptions = [
					'location' => $location,
					'description' => $description . " (Renamed from $old_name via Adminer)"
				];
				$newDataset = $connection->bigQueryClient->createDataset($new_name, $newDatasetOptions);
				
				// テーブル一覧取得（イテレータを直接使用）
				$tableCount = 0;

				// テーブルコピー処理
				foreach ($oldDataset->tables() as $table) {
					$tableCount++;
					$tableName = $table->id();
					$oldTableId = BigQueryUtils::buildFullTableName($tableName, $old_name, $connection->projectId);
					$newTableId = BigQueryUtils::buildFullTableName($tableName, $new_name, $connection->projectId);

					try {
						// テーブルをコピー（CREATE TABLE AS SELECT）
						$copyQuery = "CREATE TABLE $newTableId AS SELECT * FROM $oldTableId";
						BigQueryUtils::logQuerySafely($copyQuery, "RENAME_DATASET_COPY_TABLE");

						$queryJob = $connection->bigQueryClient->query($copyQuery)
							->useLegacySql(false)
							->location($location);
						$job = $connection->bigQueryClient->runQuery($queryJob);

						if (!$job->isComplete()) {
							$job->waitUntilComplete();
						}

						// ジョブステータス確認
						$jobInfo = $job->info();
						if (isset($jobInfo['status']['errorResult'])) {
							throw new Exception("Table copy failed: " . ($jobInfo['status']['errorResult']['message'] ?? 'Unknown error'));
						}

						error_log("BigQuery: Successfully copied table '$tableName' to new dataset");

					} catch (Exception $e) {
						error_log("BigQuery: Failed to copy table '$tableName': " . $e->getMessage());
						// テーブルコピー失敗時は新データセットをクリーンアップ
						try {
							$newDataset->delete(['deleteContents' => true]);
						} catch (Exception $cleanupError) {
							error_log("BigQuery: Cleanup failed: " . $cleanupError->getMessage());
						}
						$connection->error = "Failed to copy table '$tableName': " . $e->getMessage();
						return false;
					}
				}
				if ($tableCount > 0) {
					error_log("BigQuery: Found $tableCount tables to copy from '$old_name' to '$new_name'");
				}
				
				// 元データセット削除
				try {
					BigQueryUtils::logQuerySafely("DROP DATASET $old_name (rename completion)", "RENAME_DATASET_DROP");
					$oldDataset->delete(['deleteContents' => true]);
					error_log("BigQuery: Successfully deleted old dataset '$old_name'");
				} catch (Exception $e) {
					error_log("BigQuery: Warning - Failed to delete old dataset '$old_name': " . $e->getMessage());
					// 新データセットは作成済みなので、警告として記録のみ
					$connection->error = "Dataset renamed but old dataset deletion failed: " . $e->getMessage();
				}
				
				error_log("BigQuery: Dataset rename completed - '$old_name' -> '$new_name' ($tableCount tables)");
				return true;
				
			} catch (ServiceException $e) {
				$message = $e->getMessage();
				$errorCode = $e->getCode();
				
				// 権限エラー
				if (strpos($message, 'permission') !== false || $errorCode === 403) {
					error_log("BigQuery: Permission denied for dataset rename: $old_name -> $new_name");
					$connection->error = "Permission denied: Cannot rename dataset";
					return false;
				}
				
				// その他のServiceException
				error_log("BigQuery: Dataset rename failed - Code: $errorCode, Message: $message");
				$connection->error = "Dataset rename failed: $message";
				return false;
				
			} catch (Exception $e) {
				error_log("BigQuery: Dataset rename error - " . $e->getMessage());
				$connection->error = "Dataset rename error: " . $e->getMessage();
				return false;
			}
		}

		function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning)
		{
			global $connection;

			try {
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				if ($table == "") {

					$database = $_GET['db'] ?? $connection->datasetId ?? '';
					if (empty($database)) {
						return false;
					}

					$dataset = $connection->bigQueryClient->dataset($database);

					$schemaFields = array();
					foreach ($fields as $field) {
						if (isset($field[1]) && is_array($field[1])) {

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

					$tableOptions = array(
						'schema' => array('fields' => $schemaFields)
					);

					if (!empty($comment)) {
						$tableOptions['description'] = $comment;
					}

					$cleanTableName = trim(str_replace('`', '', $name));

					$table = $dataset->createTable($cleanTableName, $tableOptions);

					return true;

				} else {

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

	// Phase 3 Sprint 3.2: BigQueryテーブルコピー機能実装
	// 同一データセット内・データセット間でのテーブルコピーをサポート
	function copy_tables($tables, $target_db, $overwrite)
	{
		global $connection;
		
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}
		
		if (empty($tables) || !is_array($tables)) {
			return false;
		}
		
		$errors = array();
		$successCount = 0;
		
		try {
			// 現在のデータセット名を取得
			$currentDb = $_GET['db'] ?? $connection->datasetId ?? '';
			if (empty($currentDb)) {
				$connection->error = "Current dataset not specified";
				return false;
			}
			
			// ターゲットデータセット名の設定（空の場合は現在のデータセット）
			$targetDb = !empty($target_db) ? $target_db : $currentDb;
			
			// データセット名の検証
			if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $targetDb)) {
				$connection->error = "Invalid target dataset name format: $targetDb";
				return false;
			}
			
			// ターゲットデータセットの存在確認
			$targetDataset = $connection->bigQueryClient->dataset($targetDb);
			if (!$targetDataset->exists()) {
				$connection->error = "Target dataset '$targetDb' does not exist";
				return false;
			}
			
			// 各テーブルのコピー処理
			foreach ($tables as $table) {
				if (empty($table)) {
					continue;
				}
				
				try {
					// テーブル名の検証
					if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $table)) {
						$errors[] = "Invalid table name format: $table";
						continue;
					}
					
					// ソーステーブルの存在確認
					$sourceTableId = BigQueryUtils::buildFullTableName($table, $currentDb, $connection->projectId);
					$sourceTable = $connection->bigQueryClient->dataset($currentDb)->table($table);
					if (!$sourceTable->exists()) {
						$errors[] = "Source table '$table' does not exist in dataset '$currentDb'";
						continue;
					}

					// ターゲットテーブル名の設定
					$targetTableName = $table;
					$targetTableId = BigQueryUtils::buildFullTableName($targetTableName, $targetDb, $connection->projectId);
					
					// 既存テーブルの確認と上書き処理
					$targetTable = $targetDataset->table($targetTableName);
					if ($targetTable->exists()) {
						if (!$overwrite) {
							$errors[] = "Target table '$targetTableName' already exists in dataset '$targetDb' (overwrite disabled)";
							continue;
						} else {
							// 既存テーブルを削除
							BigQueryUtils::logQuerySafely("DROP TABLE $targetTableId (overwrite)", "COPY_TABLES_OVERWRITE");
							$targetTable->delete();
							error_log("BigQuery: Deleted existing target table '$targetTableName' for overwrite");
						}
					}
					
					// テーブルコピー実行（CREATE TABLE AS SELECT）
					$copyQuery = "CREATE TABLE $targetTableId AS SELECT * FROM $sourceTableId";
					BigQueryUtils::logQuerySafely($copyQuery, "COPY_TABLES");
					
					// ソーステーブルの場所情報を取得
					$sourceTableInfo = $sourceTable->info();
					$location = $sourceTableInfo['location'] ?? 'US';
					
					$queryJob = $connection->bigQueryClient->query($copyQuery)
						->useLegacySql(false)
						->location($location);
					$job = $connection->bigQueryClient->runQuery($queryJob);
					
					if (!$job->isComplete()) {
						$job->waitUntilComplete();
					}
					
					// ジョブステータス確認
					$jobInfo = $job->info();
					if (isset($jobInfo['status']['errorResult'])) {
						throw new Exception("Table copy failed: " . ($jobInfo['status']['errorResult']['message'] ?? 'Unknown error'));
					}
					
					error_log("BigQuery: Successfully copied table '$table' from '$currentDb' to '$targetDb'");
					$successCount++;
					
				} catch (ServiceException $e) {
					$message = $e->getMessage();
					$errorCode = $e->getCode();
					
					// 権限エラー
					if (strpos($message, 'permission') !== false || $errorCode === 403) {
						$errors[] = "Permission denied: Cannot copy table '$table'";
					}
					// その他のServiceException
					else {
						$errors[] = "Failed to copy table '$table': $message";
					}
					
					BigQueryUtils::logQuerySafely($e->getMessage(), 'COPY_TABLES_ERROR');
					
				} catch (Exception $e) {
					$errors[] = "Copy table '$table' failed: " . $e->getMessage();
					BigQueryUtils::logQuerySafely($e->getMessage(), 'COPY_TABLES_ERROR');
				}
			}
			
			// エラーハンドリング
			if (!empty($errors) && $connection) {
				$connection->error = implode('; ', $errors);
			}
			
			// 成功ログ
			if ($successCount > 0) {
				error_log(sprintf("BigQuery: copy_tables completed - %d/%d tables copied to '%s'", $successCount, count($tables), $targetDb));
			}
			
			return $successCount > 0;
			
		} catch (Exception $e) {
			if ($connection) {
				$connection->error = "COPY TABLES failed: " . $e->getMessage();
			}
			BigQueryUtils::logQuerySafely($e->getMessage(), 'COPY_TABLES_ERROR');
			return false;
		}
	}

		function move_tables($tables, $views, $target)
	{
		// Phase 3 Sprint 3.2: BigQueryテーブル移動機能強化実装
		// テーブル移動はコピー→削除のフローで実現（BigQuery制限対応）
		
		global $connection;
		
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}
		
		if (empty($tables) || !is_array($tables)) {
			return false;
		}
		
		$errors = array();
		$successCount = 0;
		$originalTables = array(); // 復元用のテーブル情報保存
		
		try {
			// 現在のデータセット名を取得
			$currentDb = $_GET['db'] ?? $connection->datasetId ?? '';
			if (empty($currentDb)) {
				$connection->error = "Current dataset not specified";
				return false;
			}
			
			// ターゲットデータセット名の設定
			$targetDb = !empty($target) ? $target : $currentDb;
			
			// データセット名の検証
			if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $targetDb)) {
				$connection->error = "Invalid target dataset name format: $targetDb";
				return false;
			}
			
			// 同一データセット内の移動は無効
			if ($currentDb === $targetDb) {
				$connection->error = "Cannot move tables within the same dataset";
				return false;
			}
			
			// ターゲットデータセットの存在確認
			$targetDataset = $connection->bigQueryClient->dataset($targetDb);
			if (!$targetDataset->exists()) {
				$connection->error = "Target dataset '$targetDb' does not exist";
				return false;
			}
			
			// Phase 1: 各テーブルをターゲットにコピー
			foreach ($tables as $table) {
				if (empty($table)) {
					continue;
				}
				
				try {
					// テーブル名の検証
					if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $table)) {
						$errors[] = "Invalid table name format: $table";
						continue;
					}
					
					// ソーステーブルの存在確認
					$sourceTableId = BigQueryUtils::buildFullTableName($table, $currentDb, $connection->projectId);
					$sourceTable = $connection->bigQueryClient->dataset($currentDb)->table($table);
					if (!$sourceTable->exists()) {
						$errors[] = "Source table '$table' does not exist in dataset '$currentDb'";
						continue;
					}

					// 移動前情報の保存
					$originalTables[] = array(
						'name' => $table,
						'sourceDataset' => $currentDb,
						'sourceTable' => $sourceTable
					);

					// ターゲットテーブル名の設定
					$targetTableName = $table;
					$targetTableId = BigQueryUtils::buildFullTableName($targetTableName, $targetDb, $connection->projectId);
					
					// ターゲットでの名前衝突チェック
					$targetTable = $targetDataset->table($targetTableName);
					if ($targetTable->exists()) {
						$errors[] = "Target table '$targetTableName' already exists in dataset '$targetDb'";
						continue;
					}
					
					// テーブルコピー実行（CREATE TABLE AS SELECT）
					$copyQuery = "CREATE TABLE $targetTableId AS SELECT * FROM $sourceTableId";
					BigQueryUtils::logQuerySafely($copyQuery, "MOVE_TABLES_COPY");
					
					// ソーステーブルの場所情報を取得
					$sourceTableInfo = $sourceTable->info();
					$location = $sourceTableInfo['location'] ?? 'US';
					
					$queryJob = $connection->bigQueryClient->query($copyQuery)
						->useLegacySql(false)
						->location($location);
					$job = $connection->bigQueryClient->runQuery($queryJob);
					
					if (!$job->isComplete()) {
						$job->waitUntilComplete();
					}
					
					// ジョブステータス確認
					$jobInfo = $job->info();
					if (isset($jobInfo['status']['errorResult'])) {
						throw new Exception("Table copy failed: " . ($jobInfo['status']['errorResult']['message'] ?? 'Unknown error'));
					}
					
					error_log("BigQuery: Successfully copied table '$table' from '$currentDb' to '$targetDb' for move operation");
					$successCount++;
					
				} catch (ServiceException $e) {
					$message = $e->getMessage();
					$errorCode = $e->getCode();
					
					// 権限エラー
					if (strpos($message, 'permission') !== false || $errorCode === 403) {
						$errors[] = "Permission denied: Cannot move table '$table'";
					}
					// その他のServiceException
					else {
						$errors[] = "Failed to move table '$table': $message";
					}
					
					BigQueryUtils::logQuerySafely($e->getMessage(), 'MOVE_TABLES_ERROR');
					
				} catch (Exception $e) {
					$errors[] = "Move table '$table' failed: " . $e->getMessage();
					BigQueryUtils::logQuerySafely($e->getMessage(), 'MOVE_TABLES_ERROR');
				}
			}
			
			// Phase 2: コピー成功したテーブルの元テーブルを削除
			$deletedCount = 0;
			foreach ($originalTables as $tableInfo) {
				if ($deletedCount < $successCount) {
					try {
						$tableName = $tableInfo['name'];
						$sourceTable = $tableInfo['sourceTable'];
						
						// 元テーブル削除
						BigQueryUtils::logQuerySafely("DROP TABLE " . BigQueryUtils::buildFullTableName($tableName, $currentDb, $connection->projectId), "MOVE_TABLES_DELETE");
						$sourceTable->delete();
						
						error_log("BigQuery: Successfully deleted source table '$tableName' after move to '$targetDb'");
						$deletedCount++;
						
					} catch (Exception $e) {
						error_log("BigQuery: Warning - Failed to delete source table '{$tableInfo['name']}' after move: " . $e->getMessage());
						$errors[] = "Move completed but failed to delete source table '{$tableInfo['name']}': " . $e->getMessage();
					}
				}
			}
			
			// エラーハンドリング
			if (!empty($errors) && $connection) {
				$connection->error = implode('; ', $errors);
			}
			
			// 成功ログ
			if ($successCount > 0) {
				error_log("BigQuery: move_tables completed - $successCount/" . count($tables) . " tables moved from '$currentDb' to '$targetDb'");
			}
			
			return $successCount > 0;
			
		} catch (Exception $e) {
			if ($connection) {
				$connection->error = "MOVE TABLES failed: " . $e->getMessage();
			}
			BigQueryUtils::logQuerySafely($e->getMessage(), 'MOVE_TABLES_ERROR');
			return false;
		}
	}

	}
}

if (!function_exists('show_unsupported_feature_message')) {

	function show_unsupported_feature_message($feature, $reason = '')
	{

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

	function schema()
	{
		show_unsupported_feature_message('schema', 'BigQuery uses datasets instead of traditional schemas. Dataset information is available in the main database view.');
		return;
	}
}

if (!function_exists('import_sql')) {

	function view($name)
	{
		// Phase 4 Sprint 4.1: BigQuery ビュー定義取得機能
		// BigQuery ビューの詳細情報とクエリ定義を返す
		
		global $connection;
		
		if (!$connection || !isset($connection->bigQueryClient)) {
			return array();
		}
		
		try {
			$database = $_GET['db'] ?? $connection->datasetId ?? '';
			if (empty($database) || empty($name)) {
				return array();
			}
			
			// BigQuery ビューオブジェクトを取得
			$dataset = $connection->bigQueryClient->dataset($database);
			$table = $dataset->table($name);
			
			if (!$table->exists()) {
				return array();
			}
			
			$tableInfo = $table->info();
			
			// ビューかどうかを確認
			$tableType = strtolower($tableInfo['type'] ?? 'TABLE');
			if (!in_array($tableType, ['view', 'materialized_view'])) {
				return array();
			}
			
			// ビュー定義クエリを取得
			$viewQuery = $tableInfo['view']['query'] ?? '';
			if (empty($viewQuery)) {
				// マテリアライズドビューの場合
				$viewQuery = $tableInfo['materializedView']['query'] ?? '';
			}
			
			// Adminer互換のビュー情報配列を構築
			$viewInfo = array(
				'select' => $viewQuery,
				'materialized' => ($tableType === 'materialized_view')
			);
			
			// 追加情報
			if (!empty($tableInfo['description'])) {
				$viewInfo['comment'] = $tableInfo['description'];
			}
			
			if (isset($tableInfo['creationTime'])) {
				$viewInfo['created'] = date('Y-m-d H:i:s', $tableInfo['creationTime'] / 1000);
			}
			
			if (isset($tableInfo['lastModifiedTime'])) {
				$viewInfo['modified'] = date('Y-m-d H:i:s', $tableInfo['lastModifiedTime'] / 1000);
			}
			
			// BigQuery固有情報
			if (isset($tableInfo['location'])) {
				$viewInfo['location'] = $tableInfo['location'];
			}
			
			if ($tableType === 'materialized_view' && isset($tableInfo['materializedView']['refreshIntervalMs'])) {
				$viewInfo['refresh_interval'] = $tableInfo['materializedView']['refreshIntervalMs'] / 1000 . ' seconds';
			}
			
			// ビュー名をサニタイズしてログ出力（ログインジェクション防止）
			$sanitizedName = preg_replace('/[^\w\-\.]/', '_', $name);
			BigQueryUtils::logQuerySafely("VIEW INFO: $sanitizedName", "VIEW_INFO");
			return $viewInfo;
			
		} catch (ServiceException $e) {
			$message = $e->getMessage();
			if (strpos($message, '404') === false && strpos($message, 'Not found') === false) {
				// エラーメッセージをサニタイズしてログ出力（機密情報漏洩防止）
				$sanitizedError = preg_replace('/([\\w\\-\\.]+@[\\w\\-\\.]+\\.[a-zA-Z]+)/', '[EMAIL_REDACTED]', $message);
				$sanitizedError = preg_replace('/(project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+)/i', '[PROJECT_REDACTED]', $sanitizedError);
				BigQueryUtils::logQuerySafely($sanitizedError, 'VIEW_ERROR');
			}
			return array();
		} catch (Exception $e) {
			// エラーメッセージをサニタイズしてログ出力（機密情報漏洩防止）
			$sanitizedError = preg_replace('/([\\w\\-\\.]+@[\\w\\-\\.]+\\.[a-zA-Z]+)/', '[EMAIL_REDACTED]', $e->getMessage());
			$sanitizedError = preg_replace('/(project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+)/i', '[PROJECT_REDACTED]', $sanitizedError);
			BigQueryUtils::logQuerySafely($sanitizedError, 'VIEW_ERROR');
			return array();
		}
	}

	function import_sql($file)
	{
		// Phase 4 Sprint 4.1: BigQuery SQLインポート機能強化
		// BigQueryに適したSQLファイル処理とバッチ実行機能
		
		global $connection;
		
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}
		
		try {
			// ファイル存在確認
			if (!file_exists($file) || !is_readable($file)) {
				error_log("BigQuery: Import file not found or not readable: $file");
				return false;
			}
			
			// ファイルサイズ制限（10MB）
			$maxFileSize = 10 * 1024 * 1024; // 10MB
			$fileSize = filesize($file);
			if ($fileSize > $maxFileSize) {
				error_log("BigQuery: Import file too large: " . ($fileSize / 1024 / 1024) . "MB > 10MB");
				return false;
			}
			
			// SQLファイル読み込み
			$sqlContent = file_get_contents($file);
			if ($sqlContent === false) {
				error_log("BigQuery: Failed to read import file: $file");
				return false;
			}
			
			// BigQuery対応のSQL文分割処理
			$statements = parseBigQueryStatements($sqlContent);
			if (empty($statements)) {
				error_log("BigQuery: No valid SQL statements found in file");
				return false;
			}
			
			// 統計情報
			$totalStatements = count($statements);
			$successCount = 0;
			$errors = array();
			
			BigQueryUtils::logQuerySafely("Starting SQL import: $totalStatements statements from $file", "SQL_IMPORT");
			
			// SQLステートメントを順次実行
			foreach ($statements as $index => $statement) {
				$trimmedStatement = trim($statement);
				if (empty($trimmedStatement) || isCommentOnly($trimmedStatement)) {
					continue;
				}
				
				try {
					// BigQuery危険パターンチェック（メソッド存在確認付き）
					if (class_exists('BigQueryConfig') && method_exists('BigQueryConfig', 'isDangerousQuery')) {
						if (BigQueryConfig::isDangerousQuery($trimmedStatement)) {
							$errors[] = "Statement " . ($index + 1) . ": Dangerous SQL pattern detected";
							continue;
						}
					}
					
					// BigQueryクエリ実行
					$queryLocation = $connection->config['location'] ?? 'US';
					$queryJob = $connection->bigQueryClient->query($trimmedStatement)
						->useLegacySql(false)
						->location($queryLocation);
					$job = $connection->bigQueryClient->runQuery($queryJob);
					
					if (!$job->isComplete()) {
						$job->waitUntilComplete();
					}
					
					// ジョブステータス確認
					$jobInfo = $job->info();
					if (isset($jobInfo['status']['errorResult'])) {
						$errorMessage = $jobInfo['status']['errorResult']['message'] ?? 'Unknown error';
						$errors[] = "Statement " . ($index + 1) . ": " . $errorMessage;
					} else {
						$successCount++;
					}
					
				} catch (ServiceException $e) {
					$errors[] = "Statement " . ($index + 1) . ": " . $e->getMessage();
					// エラーメッセージをサニタイズしてログ出力（機密情報漏洩防止）
					$sanitizedError = preg_replace('/([\\w\\-\\.]+@[\\w\\-\\.]+\\.[a-zA-Z]+)/', '[EMAIL_REDACTED]', $e->getMessage());
					$sanitizedError = preg_replace('/(project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+)/i', '[PROJECT_REDACTED]', $sanitizedError);
					BigQueryUtils::logQuerySafely($sanitizedError, 'SQL_IMPORT_ERROR');
				} catch (Exception $e) {
					$errors[] = "Statement " . ($index + 1) . ": " . $e->getMessage();
					// エラーメッセージをサニタイズしてログ出力（機密情報漏洩防止）
					$sanitizedError = preg_replace('/([\\w\\-\\.]+@[\\w\\-\\.]+\\.[a-zA-Z]+)/', '[EMAIL_REDACTED]', $e->getMessage());
					$sanitizedError = preg_replace('/(project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+)/i', '[PROJECT_REDACTED]', $sanitizedError);
					BigQueryUtils::logQuerySafely($sanitizedError, 'SQL_IMPORT_ERROR');
				}
			}
			
			// 結果ログ出力
			$errorCount = count($errors);
			$resultMessage = "SQL import completed: $successCount/$totalStatements statements executed successfully";
			if ($errorCount > 0) {
				$resultMessage .= ", $errorCount errors";
			}
			
			BigQueryUtils::logQuerySafely($resultMessage, "SQL_IMPORT_RESULT");
			
			// エラーログ詳細出力
			if (!empty($errors)) {
				foreach (array_slice($errors, 0, 5) as $error) { // 最初の5個のエラーのみログ
					error_log("BigQuery SQL Import Error: $error");
				}
				if (count($errors) > 5) {
					error_log("BigQuery SQL Import: ... and " . (count($errors) - 5) . " more errors");
				}
			}
			
			// 成功判定：少なくとも1つのステートメントが成功
			return $successCount > 0;
			
		} catch (Exception $e) {
			error_log("BigQuery: SQL import failed - " . $e->getMessage());
			// エラーメッセージをサニタイズしてログ出力（機密情報漏洩防止）
			$sanitizedError = preg_replace('/([\\w\\-\\.]+@[\\w\\-\\.]+\\.[a-zA-Z]+)/', '[EMAIL_REDACTED]', $e->getMessage());
			$sanitizedError = preg_replace('/(project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+)/i', '[PROJECT_REDACTED]', $sanitizedError);
			BigQueryUtils::logQuerySafely($sanitizedError, 'SQL_IMPORT_FAILED');
			return false;
		}
	}
	
}

if (!function_exists('parseBigQueryStatements')) {
	function parseBigQueryStatements($sqlContent)
	{
		// BigQuery用SQL文分割処理
		// セミコロン区切りだが、文字列内・コメント内のセミコロンは無視

		$statements = array();
		$currentStatement = '';
		$inSingleQuote = false;
		$inDoubleQuote = false;
		$inBacktick = false;
		$inLineComment = false;
		$inBlockComment = false;

		$length = strlen($sqlContent);
		for ($i = 0; $i < $length; $i++) {
			$char = $sqlContent[$i];
			$nextChar = ($i + 1 < $length) ? $sqlContent[$i + 1] : '';

			// コメント処理
			if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
				// 行コメント開始
				if ($char === '-' && $nextChar === '-') {
					$inLineComment = true;
					$currentStatement .= $char;
					continue;
				}
				// ブロックコメント開始
				if ($char === '/' && $nextChar === '*') {
					$inBlockComment = true;
					$currentStatement .= $char;
					continue;
				}
			}

			// 行コメント終了
			if ($inLineComment && ($char === "\n" || $char === "\r")) {
				$inLineComment = false;
				$currentStatement .= $char;
				continue;
			}

			// ブロックコメント終了
			if ($inBlockComment && $char === '*' && $nextChar === '/') {
				$inBlockComment = false;
				$currentStatement .= $char . $nextChar;
				$i++; // Skip next character
				continue;
			}

			// コメント内の場合はそのまま追加
			if ($inLineComment || $inBlockComment) {
				$currentStatement .= $char;
				continue;
			}

			// クォート処理
			if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
				$inSingleQuote = !$inSingleQuote;
			} elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
				$inDoubleQuote = !$inDoubleQuote;
			} elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
				$inBacktick = !$inBacktick;
			}

			// セミコロン分割（クォート外の場合のみ）
			if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
				$trimmedStatement = trim($currentStatement);
				if (!empty($trimmedStatement)) {
					$statements[] = $trimmedStatement;
				}
				$currentStatement = '';
				continue;
			}

			$currentStatement .= $char;
		}

		// 最後のステートメント処理
		$trimmedStatement = trim($currentStatement);
		if (!empty($trimmedStatement)) {
			$statements[] = $trimmedStatement;
		}

		return $statements;
	}
}

if (!function_exists('isCommentOnly')) {
	function isCommentOnly($statement)
	{
		// コメントのみの行判定
		$trimmed = trim($statement);
		return empty($trimmed) ||
			   strpos($trimmed, '--') === 0 ||
			   (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/') !== false);
	}
}

	function truncate_table($table)
	{
		global $connection;

		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		try {

			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database) || empty($table)) {
				return false;
			}

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

if (!function_exists('check_table')) {

	function check_table($table)
	{
		show_unsupported_feature_message('check');
		return false;
	}
}

if (!function_exists('optimize_table')) {

	function optimize_table($table)
	{
		show_unsupported_feature_message('optimize');
		return false;
	}
}

if (!function_exists('repair_table')) {

	function repair_table($table)
	{
		show_unsupported_feature_message('repair');
		return false;
	}
}

if (!function_exists('analyze_table')) {

	function analyze_table($table)
	{
		show_unsupported_feature_message('analyze');
		return false;
	}
}

class AdminerLoginBigQuery extends \Adminer\Plugin
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

// =============================================================================
// Phase 1 Sprint 1.1: 基本クエリ機能実装
// Note: これらの関数はAdminerのDriverクラス内で既に実装されている場合があるため、
// BigQueryドライバー固有の拡張機能としてDriverクラス内のメソッドとして実装済み
// =============================================================================


class AdminerBigQueryCSS extends \Adminer\Plugin
{
	function head($dark = null)
	{
		if ((defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery')) {

			if (class_exists('Adminer\\Driver')) {
				$driver = new \Adminer\Driver();
				if (method_exists($driver, 'css')) {

					echo $driver->css();
				}
			}
		}
	}
}
