<?php

namespace Adminer;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\ServiceException;
use Exception;
use InvalidArgumentException;

if (function_exists('Adminer\\add_driver')) {
	add_driver("bigquery", "Google BigQuery");
}

// AdminerLoginBigQueryクラスは bigquery/AdminerLoginBigQuery.php に分離済み



// AdminerLoginBigQueryクラスをinclude
require_once __DIR__ . '/bigquery/AdminerLoginBigQuery.php';

class AdminerBigQueryCSS extends Plugin
{
	private function isBigQueryDriver()
	{
		return (defined('DRIVER') && DRIVER === 'bigquery') || (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery');
	}

	function head($dark = null)
	{
		if ($this->isBigQueryDriver()) {

			if (class_exists('Adminer\\Driver')) {
				$driver = new \Adminer\Driver();
				if (method_exists($driver, 'css')) {

					echo $driver->css();
				}
			}
		}
	}
}

if (isset($_GET["bigquery"])) {
	require_once __DIR__ . '/bigquery/BigQueryCacheManager.php';
	require_once __DIR__ . '/bigquery/BigQueryConnectionPool.php';
	define('Adminer\DRIVER', "bigquery");

	require_once __DIR__ . '/bigquery/BigQueryConfig.php';

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

		/**
		 * BigQueryジョブの完了状態を包括的に確認する共通関数
		 *
		 * @param object $job BigQueryジョブオブジェクト
		 * @return bool ジョブが完了している場合はtrue
		 */
		static function isJobCompleted($job)
		{
			if (!$job) {
				return false;
			}

			$jobInfo = $job->info();
			$isJobComplete = false;

			// 方法1: job->isComplete()メソッドによる確認
			if ($job->isComplete()) {
				$isJobComplete = true;
			}

			// 方法2: status.state フィールドによる確認
			if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
				$isJobComplete = true;
			}

			// 方法3: statistics の存在による確認
			if (isset($jobInfo['statistics'])) {
				$isJobComplete = true;
			}

			return $isJobComplete;
		}

		/**
		 * Process WHERE clause for BigQuery DML operations
		 *
		 * @param string $queryWhere The WHERE condition from Adminer
		 * @return string Properly formatted WHERE clause with WHERE prefix
		 * @throws InvalidArgumentException If WHERE condition is invalid
		 */
		static function processWhereClause($queryWhere)
		{
			if (empty($queryWhere) || trim($queryWhere) === '') {
				return '';
			}

			$convertedWhere = convertAdminerWhereToBigQuery($queryWhere);

			// Check if the converted WHERE already starts with WHERE keyword
			if (preg_match('/^\s*WHERE\s/i', $convertedWhere)) {
				return ' ' . $convertedWhere;
			} else {
				return ' WHERE ' . $convertedWhere;
			}
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

				// OAuth2認証が有効な場合
				if ($this->isOAuth2Enabled()) {
					$this->initializeConfiguration($location);
					$this->createBigQueryClientWithOAuth2($location);
				} else {
					// 従来のCREDENTIAL認証
					$credentialsPath = $this->getCredentialsPath();
					if (!$credentialsPath) {
						throw new Exception('BigQuery authentication not configured. Set GOOGLE_APPLICATION_CREDENTIALS environment variable or provide credentials file path.');
					}
					$this->initializeConfiguration($location);
					$this->createBigQueryClient($credentialsPath, $location);
				}

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

		/**
		 * OAuth2認証が有効かどうかをチェック
		 */
		private function isOAuth2Enabled()
		{
			$oauth2Enable = getenv('GOOGLE_OAUTH2_ENABLE');
			// Add input validation and sanitization
			return $oauth2Enable === 'true' && $this->validateOAuth2Configuration();
		}

		/**
		 * Validate OAuth2 configuration for security
		 */
		private function validateOAuth2Configuration()
		{
			$clientId = getenv('GOOGLE_OAUTH2_CLIENT_ID');
			$redirectUrl = getenv('GOOGLE_OAUTH2_REDIRECT_URL');

			// Validate client ID format
			if (!$clientId || !preg_match('/^[a-zA-Z0-9\-_.]+$/', $clientId)) {
				error_log('OAuth2: Invalid client ID format');
				return false;
			}

			// Validate redirect URL
			if (!$redirectUrl || !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
				error_log('OAuth2: Invalid redirect URL');
				return false;
			}

			// Ensure HTTPS for production
			if (parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https' &&
				!in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])) {
				error_log('OAuth2: Redirect URL must use HTTPS in production');
				return false;
			}

			return true;
		}

		/**
		 * Validate OAuth2 state parameter for CSRF protection
		 */
		private function validateStateParameter($state)
		{
			try {
				$decodedState = base64_decode($state, true);
				if ($decodedState === false) {
					return false;
				}

				$stateData = json_decode($decodedState, true);
				if (!is_array($stateData)) {
					return false;
				}

				// Validate redirect_to parameter
				if (isset($stateData['redirect_to'])) {
					$redirectTo = $stateData['redirect_to'];
					// Only allow relative URLs or same-origin URLs
					if (!preg_match('/^\/[^\/]/', $redirectTo) &&
						parse_url($redirectTo, PHP_URL_HOST) !== $_SERVER['HTTP_HOST']) {
						return false;
					}
				}

				return true;
			} catch (Exception $e) {
				error_log('OAuth2: State validation error: ' . $e->getMessage());
				return false;
			}
		}

		/**
		 * OAuth2認証用の環境変数を取得
		 */
		private function getOAuth2Config()
		{
			return [
				'client_id' => getenv('GOOGLE_OAUTH2_CLIENT_ID'),
				'redirect_url' => getenv('GOOGLE_OAUTH2_REDIRECT_URL'),
				'cookie_domain' => getenv('GOOGLE_OAUTH2_COOKIE_DOMAIN'),
				'cookie_name' => getenv('GOOGLE_OAUTH2_COOKIE_NAME'),
				'cookie_expire' => getenv('GOOGLE_OAUTH2_COOKIE_EXPIRE'),
				'cookie_secret' => getenv('GOOGLE_OAUTH2_COOKIE_SECRET')
			];
		}

		/**
		 * OAuth2アクセストークンを取得
		 */
		private function getOAuth2AccessToken()
		{
			// OAuth2コールバック処理をチェック
			if ($this->handleOAuth2Callback()) {
				// コールバック処理完了後、適切にリダイレクト
				$state = $_GET['state'] ?? '';
				$stateData = json_decode(base64_decode($state), true);
				$redirectTo = $stateData['redirect_to'] ?? $_SERVER['PHP_SELF'];

				header('Location: ' . $redirectTo);
				exit();
			}

			$config = $this->getOAuth2Config();
			$cookieName = $config['cookie_name'] ?: 'oauth2_proxy';

			// 1. プロキシレベルのOAuth2認証をチェック（最優先）
			if (isset($_COOKIE['__Host-oauth2_proxy'])) {
				// プロキシが既にOAuth2認証を完了している場合
				error_log('OAuth2: Using proxy-level authentication');
				return $_COOKIE['__Host-oauth2_proxy'];
			}

			// 2. セッションから取得を試行
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}
			if (isset($_SESSION['oauth2_token']['access_token'])) {
				return $_SESSION['oauth2_token']['access_token'];
			}

			// 3. アプリケーションレベルのクッキーから取得を試行
			if (isset($_COOKIE[$cookieName])) {
				return $_COOKIE[$cookieName];
			}

			// 4. プロキシ認証情報をHTTPヘッダーから取得（oauth2_proxy標準）
			if (isset($_SERVER['HTTP_X_USER'])) {
				error_log('OAuth2: Using proxy HTTP_X_USER header authentication');
				// プロキシが設定したユーザー情報を使用してアクセストークンを生成
				return $this->generateTokenFromProxyAuth();
			}

			return null;
		}

		/**
		 * プロキシ認証情報からアクセストークンを生成
		 */
		private function generateTokenFromProxyAuth()
		{
			// プロキシが設定したヘッダー情報を確認
			$userEmail = $_SERVER['HTTP_X_USER'] ?? '';
			$accessToken = $_SERVER['HTTP_X_ACCESS_TOKEN'] ?? '';

			if ($accessToken) {
				// プロキシが直接アクセストークンを提供している場合
				error_log("OAuth2: Using proxy-provided access token for user: $userEmail");
				return $accessToken;
			}

			// プロキシ認証成功の場合、環境に応じたトークン取得
			if ($userEmail) {
				error_log("OAuth2: Proxy authentication detected for user: $userEmail");

				// プロキシ認証が成功している場合、デフォルト認証情報を使用
				// この場合、Google Cloud のデフォルト認証情報が使用される
				try {
					$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
					if ($credentialsPath && file_exists($credentialsPath)) {
						// サービスアカウント認証を使用
						error_log("OAuth2: Falling back to service account authentication");
						return 'service_account_fallback';
					}
				} catch (Exception $e) {
					error_log("OAuth2: Service account fallback failed: " . $e->getMessage());
				}
			}

			return null;
		}

		/**
		 * OAuth2認証を使用してBigQueryクライアントを作成
		 */
		private function createBigQueryClientWithOAuth2($location)
		{
			try {
				$accessToken = $this->getOAuth2AccessToken();
				if (!$accessToken) {
					// OAuth2認証フローを開始
					$this->initiateOAuth2Flow();
					// この後はリダイレクトされるので、到達しない
					return false;
				}

				// 特別な値の場合はサービスアカウント認証にフォールバック
				if ($accessToken === 'service_account_fallback') {
					error_log('OAuth2: Using service account fallback after proxy authentication');
					return $this->createBigQueryClientWithServiceAccount($location);
				}

				// Google Client を使用してOAuth2認証を設定
				require_once __DIR__ . '/../../vendor/autoload.php';

				$client = new \Google\Client();
				$client->setAccessToken($accessToken);

				// BigQueryクライアントを作成
				$config = [
					'projectId' => $this->projectId,
					'location' => $location
				];

				// OAuth2クライアントを使用してBigQueryクライアントを初期化
				$this->bigquery = new BigQueryClient($config + [
					'authHttpHandler' => function ($request, $options) use ($client) {
						// OAuth2アクセストークンをHTTPヘッダーに追加
						return $client->authorize()->send($request, $options);
					}
				]);

				$this->location = $location;

				error_log('OAuth2: BigQuery client created successfully with OAuth2 authentication');
				return true;
			} catch (Exception $e) {
				error_log('OAuth2: BigQuery client initialization failed: ' . $e->getMessage());

				// プロキシ認証が成功している場合、サービスアカウントにフォールバック
				if (isset($_SERVER['HTTP_X_USER']) || isset($_COOKIE['__Host-oauth2_proxy'])) {
					error_log('OAuth2: Attempting service account fallback after proxy authentication');
					return $this->createBigQueryClientWithServiceAccount($location);
				}

				throw new Exception('OAuth2 BigQuery client initialization failed: ' . $e->getMessage());
			}
		}

		/**
		 * サービスアカウント認証でBigQueryクライアントを作成（プロキシ認証後のフォールバック）
		 */
		private function createBigQueryClientWithServiceAccount($location)
		{
			try {
				require_once __DIR__ . '/../../vendor/autoload.php';

				$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
				if (!$credentialsPath || !file_exists($credentialsPath)) {
					throw new Exception('Service account credentials not found');
				}

				$config = [
					'projectId' => $this->projectId,
					'keyFilePath' => $credentialsPath,
					'location' => $location
				];

				$this->bigquery = new BigQueryClient($config);
				$this->location = $location;

				error_log('OAuth2: Successfully created BigQuery client with service account fallback');
				return true;
			} catch (Exception $e) {
				error_log('OAuth2: Service account fallback failed: ' . $e->getMessage());
				throw $e;
			}
		}

		/**
		 * OAuth2認証フローを開始（Googleの認証エンドポイントにリダイレクト）
		 */
		private function initiateOAuth2Flow()
		{
			$config = $this->getOAuth2Config();
			$clientId = $config['client_id'];
			$redirectUrl = $config['redirect_url'];

			if (!$clientId || !$redirectUrl) {
				throw new Exception('OAuth2 configuration is incomplete. Please set GOOGLE_OAUTH2_CLIENT_ID and GOOGLE_OAUTH2_REDIRECT_URL environment variables.');
			}

			// 現在のURLを保存してコールバック後にリダイレクト
			$currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
			$state = base64_encode(json_encode(['redirect_to' => $currentUrl]));

			// Google OAuth2認証URL
			$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
			$params = [
				'client_id' => $clientId,
				'redirect_uri' => $redirectUrl,
				'scope' => 'https://www.googleapis.com/auth/bigquery https://www.googleapis.com/auth/cloud-platform',
				'response_type' => 'code',
				'access_type' => 'offline',
				'state' => $state
			];

			$authUrlWithParams = $authUrl . '?' . http_build_query($params);

			// OAuth2認証ページにリダイレクト
			header('Location: ' . $authUrlWithParams);
			exit();
		}

		/**
		 * OAuth2認証コールバックを処理
		 */
		private function handleOAuth2Callback()
		{
			if (!isset($_GET['code']) || !isset($_GET['state'])) {
				return false;
			}

			// Validate state parameter to prevent CSRF attacks
			if (!$this->validateStateParameter($_GET['state'])) {
				error_log('OAuth2: Invalid state parameter detected');
				return false;
			}

			$config = $this->getOAuth2Config();
			$clientId = $config['client_id'];
			$redirectUrl = $config['redirect_url'];

			try {
				// Validate authorization code format
				if (!preg_match('/^[a-zA-Z0-9\-_.\\/]+$/', $_GET['code'])) {
					throw new Exception('Invalid authorization code format');
				}

				// Validate client configuration
				if (!$clientId || !$redirectUrl) {
					throw new Exception('OAuth2 configuration incomplete');
				}

				// 認証コードをアクセストークンに交換
				$tokenData = $this->exchangeCodeForToken($_GET['code'], $clientId, $redirectUrl);

				if ($tokenData) {
					// アクセストークンをセッションまたはクッキーに保存
					$this->storeOAuth2Token($tokenData);
					return true;
				}
			} catch (Exception $e) {
				error_log('OAuth2 callback error: ' . $e->getMessage());
			}

			return false;
		}

		/**
		 * 認証コードをアクセストークンに交換
		 */
		private function exchangeCodeForToken($code, $clientId, $redirectUrl)
		{
			$tokenUrl = 'https://oauth2.googleapis.com/token';

			$postData = [
				'code' => $code,
				'client_id' => $clientId,
				'client_secret' => getenv('GOOGLE_OAUTH2_CLIENT_SECRET'), // 必要に応じて追加
				'redirect_uri' => $redirectUrl,
				'grant_type' => 'authorization_code'
			];

			$context = stream_context_create([
				'http' => [
					'method' => 'POST',
					'header' => 'Content-Type: application/x-www-form-urlencoded',
					'content' => http_build_query($postData)
				]
			]);

			$response = file_get_contents($tokenUrl, false, $context);

			if ($response === false) {
				throw new Exception('Failed to exchange code for token');
			}

			return json_decode($response, true);
		}

		/**
		 * OAuth2トークンを保存
		 */
		private function storeOAuth2Token($tokenData)
		{
			$config = $this->getOAuth2Config();
			$cookieName = $config['cookie_name'] ?: 'oauth2_proxy';
			$cookieDomain = $config['cookie_domain'] ?: '';
			$cookieExpire = $config['cookie_expire'] ?: 3600;

			// プロキシレベル認証が存在する場合は、アプリレベルのCookie設定をスキップ
			if (isset($_COOKIE['__Host-oauth2_proxy'])) {
				error_log('OAuth2: Proxy-level authentication detected, skipping app-level cookie');
			} else {
				// HTTPS検出を動的に行う
				$isHttps = (
					(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
					(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
					(!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
					(!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
				);

				// アクセストークンをクッキーに保存（HTTPSの場合のみsecure設定）
				setcookie(
					$cookieName,
					$tokenData['access_token'],
					time() + $cookieExpire,
					'/',
					$cookieDomain,
					$isHttps, // 動的にsecure設定
					true  // httponly
				);

				error_log("OAuth2: Stored app-level cookie with secure=" . ($isHttps ? 'true' : 'false'));
			}

			// セッションにも保存
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}
			$_SESSION['oauth2_token'] = $tokenData;

			error_log('OAuth2: Token stored in session successfully');
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

		/** @var array BigQuery table partitioning configuration */
		public $partitionBy = array();

		/** @var array Unsigned numeric type definitions */
		public $unsigned = array();

		/** @var array Generated column definitions */
		public $generated = array();

		/** @var array Enum field length restrictions */
		public $enumLength = array();

		/** @var array Functions available for INSERT operations */
		public $insertFunctions = array();

		/** @var array Functions available for field editing operations */
		public $editFunctions = array();

		/** @var array Database functions available for use in queries */
		public $functions = array();

		/** @var array Field grouping configuration for query operations */
		public $grouping = array();

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
			// BigQuery SELECT * との併用問題を回避するため、フィールド変換を無効化
			// Adminerが SELECT * を使用する際に不正なSQL生成を防ぐ
			return null;
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
			// 共通メソッドを使用してテーブル削除を実行
			return $this->executeForTables("DROP TABLE {table}", $tables, "DROP_TABLE");
		}

		/**
		 * 共通SQL実行メソッド - connectionとtableのチェック、エラーハンドリングを統一
		 * @param string $sql SQL文
		 * @param string $logOperation ログ用操作名
		 * @param string|null $table テーブル名（フルネーム構築用、オプション）
		 * @param string|null $database データベース名（オプション、$_GET['db']より優先）
		 * @return mixed クエリ実行結果
		 */
		public function executeSql($sql, $logOperation, $table = null, $database = null)
		{
			global $connection;

			// 基本接続チェック
			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			try {
				// データベース名取得（引数→接続設定→$_GET['db']の順で優先）
				if ($database === null) {
					$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '');
				}

				// データベース名が空の場合はエラーを返す
				if (empty($database)) {
					if ($connection) {
						$connection->error = "$logOperation failed: No database specified";
					}
					return false;
				}

				// テーブル名が指定されている場合、フルテーブル名を構築
				if ($table !== null && !empty($database)) {
					$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
					$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
					$sql = str_replace('{table}', $fullTableName, $sql);
				}

				// SQL実行ログ
				BigQueryUtils::logQuerySafely($sql, $logOperation);

				// クエリ実行
				return $connection->query($sql);

			} catch (Exception $e) {
				if ($connection) {
					$connection->error = "$logOperation failed: " . $e->getMessage();
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
				return false;
			}
		}

		/**
		 * 複数テーブルに対する同一SQL実行（MySQLのapply_queriesパターン）
		 * @param string $sqlTemplate SQL文テンプレート（{table}をプレースホルダーとして使用）
		 * @param array $tables テーブル名の配列
		 * @param string $logOperation ログ用操作名
		 * @param string|null $database データベース名（オプション）
		 * @return bool 全て成功した場合true、1つでも失敗した場合false
		 */
		public function executeForTables($sqlTemplate, $tables, $logOperation, $database = null)
		{
			global $connection;

			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			$errors = array();
			$successCount = 0;

			foreach ($tables as $table) {
				if (empty($table)) {
					continue;
				}

				$result = $this->executeSql($sqlTemplate, $logOperation, $table, $database);
				if ($result !== false) {
					$successCount++;
				} else {
					$errors[] = "$logOperation failed for table: $table";
				}
			}

			// エラーハンドリング
			if (!empty($errors) && $connection) {
				$connection->error = implode('; ', $errors);
			}

			return $successCount > 0;
		}

		/**
		 * 複数データベース（データセット）に対する操作実行
		 * @param array $databases データベース名の配列
		 * @param string $logOperation ログ用操作名
		 * @param callable $callback 各データベースに対する処理関数
		 * @return bool 1つでも成功した場合true
		 */
		public function executeForDatabases($databases, $logOperation, $callback)
		{
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
						// コールバック関数を実行
						$result = $callback($database, $connection);
						if ($result) {
							$successCount++;
							BigQueryUtils::logQuerySafely("$logOperation $database", $logOperation);
						} else {
							$errors[] = "$logOperation failed for database: $database";
						}
					} catch (Exception $e) {
						$errors[] = "$logOperation '$database' failed: " . $e->getMessage();
						BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
					}
				}

				// エラーハンドリング
				if (!empty($errors) && $connection) {
					$connection->error = implode('; ', $errors);
				}

				return $successCount > 0;

			} catch (Exception $e) {
				if ($connection) {
					$connection->error = "$logOperation failed: " . $e->getMessage();
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
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
			'dump',
		);
		$unsupportedFeatures = array(
			'foreignkeys',
			'indexes',
			'processlist',
			'kill',
			'transaction',
			'comment',
			'drop_col',
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

	function dumpOutput()
	{
		// BigQuery用のExport出力オプション
		return array(
			'text' => 'Open', // ブラウザで表示
			'file' => 'Save', // ファイル保存
		);
	}

	function dumpFormat()
	{
		// BigQuery用のExport形式オプション
		return array(
			'csv' => 'CSV',
			'json' => 'JSON',
			'sql' => 'SQL',
		);
	}

	function dumpHeaders($identifier, $multi_table = false)
	{
		$format = $_POST["format"] ?? 'csv';

		// BigQuery用の適切なContent-Typeを設定
		switch ($format) {
			case 'csv':
				header("Content-Type: text/csv; charset=utf-8");
				return 'csv';
			case 'json':
				header("Content-Type: application/json; charset=utf-8");
				return 'json';
			case 'sql':
				header("Content-Type: text/plain; charset=utf-8");
				return 'sql';
			default:
				header("Content-Type: text/plain; charset=utf-8");
				return 'txt';
		}
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

		return array(
			"unicode:cs" => "Unicode (大文字小文字区別)",
			"unicode:ci" => "Unicode (大文字小文字区別なし)",
			"" => "(デフォルト)"
		);
	}
	function db_collation($db)
	{

		if (!$db) {
			return "";
		}

		// BigQueryのデフォルト照合順序を返す
		// Unicode照合順序（大文字小文字区別）がデフォルト
		return "unicode:cs";
	}
	function information_schema($db)
	{
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
		// WHERE条件の検証

		if (!is_string($condition)) {
			throw new InvalidArgumentException('WHERE condition must be a string');
		}
		if (strlen($condition) > 1000) {
			throw new InvalidArgumentException('WHERE condition exceeds maximum length');
		}
		$suspiciousPatterns = array(
			'/;\\s*(DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\\s+/i',
			'/UNION\\s+(ALL\\s+)?SELECT/i',
			'/\\/\\*.*?\\*\\//s',
			'/--[^\\r\\n]*/i',
			'/\\bEXEC\\b/i',
			'/\\bEXECUTE\\b/i',
			'/\\bSP_/i'
		);
		foreach ($suspiciousPatterns as $pattern) {
			if (preg_match($pattern, $condition)) {
				error_log("BigQuery: Blocked suspicious WHERE condition pattern: " . substr($condition, 0, 100) . "...");
				throw new InvalidArgumentException('WHERE condition contains prohibited SQL patterns');
			}
		}

		// 正規表現を修正：\\\\s を \\s に変更
		$condition = preg_replace_callback('/(`[^`]+`)\\s*=\\s*`([^`]+)`/', function ($matches) {
			$column = $matches[1];
			$value = $matches[2];

			if (preg_match('/^-?(?:0|[1-9]\\d*)(?:\\.\\d+)?$/', $value)) {
				// 数値の場合
				return $column . ' = ' . $value;
			} else {
				// 文字列の場合
				$escaped = str_replace("'", "''", $value);
				return $column . " = '" . $escaped . "'";
			}
		}, $condition);

		// COLLATE句を削除
		$condition = preg_replace('/\\s+COLLATE\\s+\\w+/i', '', $condition);

		// WHERE条件の変換完了

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
			// BigQuery SELECT * との併用問題を回避するため、フィールド変換を無効化
			// Adminerが SELECT * を使用する際に不正なSQL生成を防ぐ
			return null;
		}
	}
	if (!function_exists('unconvert_field')) {
		function unconvert_field(array $field, $value)
		{

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
					if ($stringValue === 'true')
						return '1';
					if ($stringValue === 'false')
						return '0';
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

				// BigQuery INSERT ジョブ完了判定（共通関数を使用）
				if (BigQueryUtils::isJobCompleted($job)) {
					$jobInfo = $job->info();
					// ジョブが完了している場合、エラー結果をチェック
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						$errorMessage = $errorResult['message'] ?? 'Unknown error';
						error_log("BigQuery INSERT failed: " . $errorMessage);
						$connection->error = "INSERT failed: " . $errorMessage;
						return false;
					}

					// 成功時の処理
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 1;
					return true;
				}
				// ジョブが完了していない場合
				$connection->error = "INSERT job did not complete successfully";
				return false;

			} catch (ServiceException $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'INSERT_SERVICE_ERROR');
				$connection->error = "INSERT ServiceException: " . $errorMessage;
				return false;
			} catch (Exception $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'INSERT_ERROR');
				$connection->error = "INSERT Exception: " . $errorMessage;
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

				// Use the consolidated WHERE clause processing helper
				$whereClause = BigQueryUtils::processWhereClause($queryWhere);

				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$setStr = implode(", ", $setParts);
				$updateQuery = "UPDATE $fullTableName SET $setStr$whereClause";


				BigQueryUtils::logQuerySafely($updateQuery, "UPDATE");

				$queryLocation = $connection->config['location'] ?? 'US';
				$queryJob = $connection->bigQueryClient->query($updateQuery)
					->useLegacySql(false)
					->location($queryLocation);

				$job = $connection->bigQueryClient->runQuery($queryJob);
				if (!$job->isComplete()) {
					$job->waitUntilComplete();
				}

				// BigQuery UPDATE ジョブ完了判定（共通関数を使用）
				if (BigQueryUtils::isJobCompleted($job)) {
					$jobInfo = $job->info();
					// エラーがないかチェック
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						$errorMessage = $errorResult['message'] ?? 'Unknown error';
						error_log("BigQuery UPDATE failed: " . $errorMessage);
						$connection->error = "UPDATE failed: " . $errorMessage;
						return false;
					}

					// 成功時のaffected_rows設定
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 0;
					return true;
				}

				// ここに到達するのは異常な状態
				$connection->error = "UPDATE job completion status could not be verified";
				return false;
			} catch (ServiceException $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'UPDATE_SERVICE_ERROR');
				$connection->error = "UPDATE ServiceException: " . $errorMessage;
				return false;
			} catch (Exception $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'UPDATE_ERROR');
				$connection->error = "UPDATE Exception: " . $errorMessage;
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

				// Use the consolidated WHERE clause processing helper
				$whereClause = BigQueryUtils::processWhereClause($queryWhere);
				if (empty($whereClause)) {
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

				// BigQuery DELETE ジョブ完了判定（共通関数を使用）
				if (BigQueryUtils::isJobCompleted($job)) {
					$jobInfo = $job->info();
					// エラーがないかチェック
					$errorResult = $jobInfo['status']['errorResult'] ?? null;
					if ($errorResult) {
						$errorMessage = $errorResult['message'] ?? 'Unknown error';
						$connection->error = "DELETE failed: " . $errorMessage;
						return false;
					}

					// 成功時のaffected_rows設定
					$connection->affected_rows = $jobInfo['statistics']['query']['numDmlAffectedRows'] ?? 0;
					return true;
				}

				$connection->error = "DELETE job did not complete successfully";
				return false;
			} catch (ServiceException $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'DELETE_SERVICE_ERROR');
				$connection->error = "DELETE ServiceException: " . $errorMessage;
				return false;
			} catch (Exception $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'DELETE_ERROR');
				$connection->error = "DELETE Exception: " . $errorMessage;
				return false;
			}
		}

		function last_id()
		{
			global $connection;

			if ($connection && isset($connection->last_result)) {
				if ($connection->last_result instanceof Result && isset($connection->last_result->job)) {
					return $connection->last_result->job->id();
				}
			}

			return null;
		}

		function create_database($database, $collation)
		{

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

			global $driver;

			if (!$driver) {
				return false;
			}

			// 共通メソッドでデータベース削除処理を実行
			return $driver->executeForDatabases($databases, "DROP_DATASET", function ($database, $connection) {
				// データセット名の検証
				if (!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $database)) {
					throw new Exception("Invalid dataset name format: $database");
				}

				// データセット取得と存在確認
				$dataset = $connection->bigQueryClient->dataset($database);
				if (!$dataset->exists()) {
					throw new Exception("Dataset '$database' does not exist");
				}

				// 削除前の安全確認（テーブル数チェック）
				$tableIterator = $dataset->tables(['maxResults' => 1]);
				if ($tableIterator->current()) {
					error_log("BigQuery: Warning - Dataset '$database' contains tables, proceeding with deletion");
				}

				// BigQuery Dataset削除実行
				$dataset->delete(['deleteContents' => true]);
				error_log("BigQuery: Dataset '$database' deleted successfully");

				return true;
			});
		}

		function rename_database($old_name, $new_name)
		{

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
			global $connection, $driver;

			try {
				// 基本接続チェックを共通化
				if (!$connection || !isset($connection->bigQueryClient)) {
					return false;
				}

				if ($table == "") {
					// 新規テーブル作成の場合

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

					// 共通エラーハンドリングを使用してテーブル作成をログ
					BigQueryUtils::logQuerySafely("CREATE TABLE $cleanTableName", "CREATE_TABLE");

					$table = $dataset->createTable($cleanTableName, $tableOptions);

					return true;

				} else {
					// 既存テーブルの変更は未対応
					return false;
				}

			} catch (ServiceException $e) {
				$message = $e->getMessage();
				if (strpos($message, 'Already Exists') !== false) {
					error_log("BigQuery: Table '$name' already exists");
					return false;
				}
				// 共通エラーハンドリングを使用
				if ($connection) {
					$connection->error = "CREATE TABLE failed: " . $message;
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), 'CREATE_TABLE_ERROR');
				error_log("BigQuery: Table creation failed - " . $message);
				return false;
			} catch (Exception $e) {
				// 共通エラーハンドリングを使用
				if ($connection) {
					$connection->error = "CREATE TABLE failed: " . $e->getMessage();
				}
				BigQueryUtils::logQuerySafely($e->getMessage(), 'CREATE_TABLE_ERROR');
				error_log("BigQuery: Table creation error - " . $e->getMessage());
				return false;
			}
		}

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

		function auto_increment($table = null)
		{
			global $connection;

			if (!$connection || !isset($connection->bigQueryClient)) {
				return null;
			}

			try {
				// BigQueryではAUTO_INCREMENTが存在しないため、最大値+1を返すアプローチを実装

				if ($table) {
					$database = $_GET['db'] ?? $connection->datasetId ?? '';
					if (empty($database)) {
						return null;
					}

					// テーブルが存在するか確認
					$tableObj = $connection->bigQueryClient->dataset($database)->table($table);
					if (!$tableObj->exists()) {
						return null;
					}

					// BigQuery代替案としての最大値+1を返す（参考値として）
					// 実際のAUTO_INCREMENT相当機能はアプリケーション側で実装する必要がある
					$projectId = $connection->projectId ?? 'default';
					$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);

					// BigQuery数値型の包括的検出（BigQueryConfig::TYPE_MAPPINGを活用）
					$fields = fields($table);
					$numericFields = array_filter($fields, function ($field) {
						$type = strtolower($field['type'] ?? '');
						// BigQuery数値型の包括的チェック
						$numericTypes = ['int64', 'integer', 'float64', 'float', 'numeric', 'bignumeric', 'decimal'];
						foreach ($numericTypes as $numType) {
							if (strpos($type, $numType) !== false) {
								return true;
							}
						}
						return false;
					});

					if (!empty($numericFields)) {
						$firstNumericField = array_keys($numericFields)[0];

						// BigQueryUtils::escapeIdentifier()の存在確認（防御的プログラミング）
						if (method_exists('BigQueryUtils', 'escapeIdentifier')) {
							$escapedField = BigQueryUtils::escapeIdentifier($firstNumericField);
						} else {
							// フォールバック: 手動でエスケープ
							$escapedField = "`" . str_replace("`", "``", $firstNumericField) . "`";
						}

						$query = "SELECT MAX($escapedField) as max_id FROM $fullTableName";

						BigQueryUtils::logQuerySafely($query, "AUTO_INCREMENT_CHECK");
						$result = $connection->query($query);

						if ($result && $result instanceof Result) {
							$row = $result->fetch_assoc();
							if ($row && isset($row['max_id'])) {
								return (int) $row['max_id'] + 1;
							}
						}
					}

					// フォールバック: 1を返す
					return 1;
				}

				// テーブル指定なしの場合はnullを返す
				return null;

			} catch (ServiceException $e) {
				$message = $e->getMessage();
				error_log("BigQuery: auto_increment ServiceException - " . $message);
				return null;
			} catch (Exception $e) {
				error_log("BigQuery: auto_increment error - " . $e->getMessage());
				return null;
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

if (!function_exists('Adminer\\bigquery_view')) {

	function bigquery_view($name)
	{

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

	$trimmedStatement = trim($currentStatement);
	if (!empty($trimmedStatement)) {
		$statements[] = $trimmedStatement;
	}

	return $statements;
}

function isCommentOnly($statement)
{
	$trimmed = trim($statement);
	return empty($trimmed) ||
		strpos($trimmed, '--') === 0 ||
		(strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/') !== false);
}


function truncate_table($table)
{
	global $driver;

	if (!$driver) {
		return false;
	}

	// 共通メソッドを使用してTRUNCATE文を実行
	$sql = "TRUNCATE TABLE {table}";
	return $driver->executeSql($sql, "TRUNCATE", $table) !== false;
}

function check_table($table)
{
	show_unsupported_feature_message('check');
	return false;
}


function optimize_table($table)
{
	show_unsupported_feature_message('optimize');
	return false;
}



function repair_table($table)
{
	show_unsupported_feature_message('repair');
	return false;
}



function analyze_table($table)
{
	show_unsupported_feature_message('analyze');
	return false;
}
