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
		function getConnection($key, $config)
		{
			if (isset(self::$pool[$key])) {
				self::$usageTimestamps[$key] = time();
				$age = time() - self::$creationTimes[$key];
				error_log("BigQuery ConnectionPool: Reusing connection (age: {$age}s, pool size: " . count(self::$pool) . ")");
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
			error_log("BigQuery ConnectionPool: Created new connection in {$creationTime}s (pool size: " . count(self::$pool) . ")");
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
			error_log("BigQuery ConnectionPool: Evicted LRU connection '$oldestKey' (pool size: " . count(self::$pool) . ")");
		}
		function clearPool()
		{
			$count = count(self::$pool);
			self::$pool = array();
			self::$usageTimestamps = array();
			self::$creationTimes = array();
			error_log("BigQuery ConnectionPool: Cleared all connections ($count removed)");
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
			'ddl_dml' => '/;\\s*(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\\s+/i',
			'union_injection' => '/UNION\\s+(ALL\\s+)?SELECT/i',
			'block_comments' => '/\\/\\*.*?\\*\\//i',
			'line_comments' => '/--[^\\r\\n]*/i',
			'execute_commands' => '/\\b(EXEC|EXECUTE|SP_)\\b/i',
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
		function mapType($bigQueryType)
		{
			$baseType = strtoupper(preg_replace('/\\(.*\\)/', '', $bigQueryType));
			return self::TYPE_MAPPING[$baseType] ?? array('type' => 'text', 'length' => null);
		}
		function isDangerousQuery($query)
		{
			foreach (self::DANGEROUS_SQL_PATTERNS as $pattern) {
				if (preg_match($pattern, $query)) {
					return true;
				}
			}
			return false;
		}
		function isFeatureSupported($feature)
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
				self::$apcuAvailable = function_exists('\apcu_exists') && function_exists('\apcu_fetch') && function_exists('\apcu_store');
			}
			return self::$apcuAvailable;
		}
		function get($key, $ttl = 300)
		{
			if (self::isApcuAvailable() && \apcu_exists($key)) {
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
		function set($key, $value, $ttl = 300)
		{
			$success = false;
			if (self::isApcuAvailable()) {
				$success = \apcu_store($key, $value, $ttl);
			}
			self::$staticCache[$key] = $value;
			self::$cacheTimestamps[$key] = time();
			return $success;
		}
		function clear($pattern = null)
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
		function getStats()
		{
			$apcuInfo = self::isApcuAvailable() && function_exists('\apcu_cache_info') ? \apcu_cache_info() : array();
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

		function validateProjectId($projectId)
		{
			return preg_match('/^[a-z0-9][a-z0-9\\-]{4,28}[a-z0-9]$/i', $projectId) &&
				strlen($projectId) <= 30;
		}
		function escapeIdentifier($identifier)
		{
			return "`" . str_replace("`", "``", $identifier) . "`";
		}
		function logQuerySafely($query, $context = "QUERY")
		{
			$sanitizers = array(
				'/([\\\'"])[^\\\'\"]*\\1/' => '$1***REDACTED***$1',
				'/\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Z|a-z]{2,}\\b/' => '***EMAIL_REDACTED***'
			);
			$safeQuery = preg_replace(array_keys($sanitizers), array_values($sanitizers), $query);
			if (strlen($safeQuery) > 200) {
				$safeQuery = substr($safeQuery, 0, 200) . '... [TRUNCATED]';
			}
			error_log("BigQuery $context: $safeQuery");
		}
		function convertWhereCondition($condition)
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
		function formatComplexValue($value, $field)
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
		function generateFieldConversion($field)
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
	}
	class Db
	{

		static $instance;
		public $bigQueryClient;
		public $projectId;
		public $datasetId = '';
		public $config = array();
		public $flavor = 'BigQuery';
		public $server_info = 'Google Cloud BigQuery';
		public $extension = 'BigQuery Driver';
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
				error_log("BigQuery: Using cached location '$cachedLocation' for project '$projectId'");
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
			error_log("BigQuery: Using connection pool for project '{$this->projectId}' (key: " . substr($clientKey, 0, 8) . "...)");
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
							error_log("BigQuery: Background location detection: '$detectedLocation' for project '$projectId'");
						}
						break;
					} catch (Exception $e) {
						error_log("BigQuery: Background location detection failed: " . $e->getMessage());
						break;
					}
				}
			} catch (Exception $e) {
				error_log("BigQuery: Background location detection error: " . $e->getMessage());
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
				$this->validateReadOnlyQuery($query);
				$queryLocation = $this->determineQueryLocation();
				$queryJob = $this->bigQueryClient->query($query)
					->useLegacySql(false)
					->location($queryLocation);
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
						error_log("BigQuery: Using dataset location '$datasetLocation' for query execution");
						$this->config['location'] = $datasetLocation;
						return $datasetLocation;
					}
				} catch (Exception) {
					error_log("BigQuery: Failed to get dataset location, falling back to config location");
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
			return "Check server logs for detailed error information";
		}
	}
	class Result
	{

		private $queryResults;
		private $rowNumber = 0;
		private $fieldsCache = null;
		private $iterator = null;
		private $isIteratorInitialized = false;
		function __construct($queryResults)
		{
			$this->queryResults = $queryResults;
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
				'flags' => ($field['mode'] ?? 'NULLABLE') === 'REQUIRED' ? 'NOT NULL' : ''
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
			return array();
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
	}
	function idf_escape($idf)
	{
		return "`" . str_replace("`", "``", $idf) . "`";
	}
	function support($feature)
	{
		$supportedFeatures = array(
			'database',
			'table',
			'columns',
			'sql',
			'view',
			'materializedview'
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
			'schema'
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
				error_log("get_databases: Using cached result (" . count($cached) . " datasets)");
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
			error_log("get_databases: Retrieved and cached " . count($datasets) . " datasets");
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
				error_log("tables_list: Using cached result for dataset '$actualDatabase' (" . count($cached) . " tables)");
				return $cached;
			}
			error_log("tables_list called with database: '$database', using actual: '$actualDatabase'");
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
			error_log("tables_list: Retrieved and cached " . count($tables) . " tables for dataset '$actualDatabase'");
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
			error_log("table_status called with name param: '$name', fast: " . ($fast ? 'true' : 'false') . ", using database: '$database'");
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
					error_log("table_status: returning specific table '$name' info as indexed array");
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
				error_log("table_status: returning " . count($tables) . " tables as indexed array (fast: " . ($fast ? 'true' : 'false') . ")");
			}
			$result = is_array($tables) ? $tables : array();
			error_log("table_status: final result type: " . gettype($result) . ", count: " . count($result) . ", keys: " . implode(',', array_keys($result)));
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
			'/\/\*.*?\*\//i',
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
		$condition = preg_replace('/`([^`]+)`/', '`$1`', $condition);
		$condition = preg_replace_callback("/'([^']*)'/", function ($matches) {
			$escaped = str_replace("'", "\\'", $matches[1]);
			$escaped = str_replace("\\", "\\\\", $escaped);
			return "'" . $escaped . "'";
		}, $condition);
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
				error_log("fields: Using cached result for table '$table' (" . count($cached) . " fields)");
				return $cached;
			}
			error_log("fields called for table: '$table' in database: '$database'");
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
			$fields = array();
			static $typeCache = array();
			foreach ($tableInfo['schema']['fields'] as $field) {
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
			error_log("fields: Successfully retrieved and cached " . count($fields) . " fields for table '$table'");
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
				return "`" . str_replace("`", "``", $col) . "`";
			}, $select));
			$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '') ?? '';
			if (empty($database)) {
				return false;
			}
			$fullTableName = "`" . ($connection && isset($connection->projectId) ? $connection->projectId : 'default') . "`.`" . $database . "`.`" . $table . "`";
			$query = "SELECT $selectClause FROM $fullTableName";
			if (!empty($where)) {
				$whereClause = array();
				foreach ($where as $condition) {
					$whereClause[] = convertAdminerWhereToBigQuery($condition);
				}
				$query .= " WHERE " . implode(" AND ", $whereClause);
			}
			if (!empty($group)) {
				$query .= " GROUP BY " . implode(", ", array_map(function ($col) {
					return "`" . str_replace("`", "``", $col) . "`";
				}, $group));
			}
			if (!empty($order)) {
				$orderClause = array();
				foreach ($order as $orderSpec) {
					if (preg_match('/^(.+?)\s+(DESC|ASC)$/i', $orderSpec, $matches)) {
						$orderClause[] = "`" . str_replace("`", "``", $matches[1]) . "` " . $matches[2];
					} else {
						$orderClause[] = "`" . str_replace("`", "``", $orderSpec) . "`";
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
	}
}
