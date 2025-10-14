<?php

namespace Adminer;

use Exception;
use ServiceException;
use Google\Cloud\BigQuery\BigQueryClient;

class Db {
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

	public $bigquery;

	public $location;

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

	function connect($server, $username, $password) {
		// $username, $password パラメータは関数シグネチャ互換性のため保持（BigQueryでは環境変数認証を使用）
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
	private function determineLocation($server, $projectId) {
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
	private function isLocationExplicitlySet($server) {
		return strpos($server, ':') !== false || getenv('BIGQUERY_LOCATION');
	}
	private function initializeConfiguration($location) {
		$this->config = array(
			'projectId' => $this->projectId,
			'location' => $location
		);
	}
	private function createBigQueryClient($credentialsPath, $location) {
		$clientKey = md5($this->projectId . $credentialsPath . $location);
		$this->bigQueryClient = BigQueryConnectionPool::getConnection($clientKey, array(
			'projectId' => $this->projectId,
			'location' => $location,
			'credentialsPath' => $credentialsPath
		));
	}
	private function logConnectionError($e, $type) {
		$errorMessage = $e->getMessage();
		$safeMessage = preg_replace('/project[s]?\s*[:\-]\s*[a-z0-9\-]+/i', 'project: [REDACTED]', $errorMessage);
		error_log("BigQuery $type: " . $safeMessage);
		if (strpos($errorMessage, 'UNAUTHENTICATED') !== false || strpos($errorMessage, '401') !== false) {
			error_log("BigQuery: Authentication failed. Check service account credentials.");
		} elseif (strpos($errorMessage, 'OpenSSL') !== false) {
			error_log("BigQuery: Invalid private key in service account file.");
		}
	}
	private function getCachedLocation($projectId) {
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
	private function setCachedLocation($projectId, $location) {
		$cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
		$cacheData = array(
			'location' => $location,
			'expires' => time() + 86400
		);
		@file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
	}
	private function getCredentialsPath() {
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
	private function isOAuth2Enabled() {
		$oauth2Enable = getenv('GOOGLE_OAUTH2_ENABLE');
		// Add input validation and sanitization
		return $oauth2Enable === 'true' && $this->validateOAuth2Configuration();
	}

	/**
	 * Validate OAuth2 configuration for security
	 */
	private function validateOAuth2Configuration() {
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
		if (
			parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https' &&
			!in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])
		) {
			error_log('OAuth2: Redirect URL must use HTTPS in production');
			return false;
		}

		return true;
	}

	/**
	 * Validate OAuth2 state parameter for CSRF protection
	 */
	private function validateStateParameter($state) {
		try {
			// Handle double URL encoding that may occur in OAuth flow
			$decodedState = urldecode($state);
			$decodedState = base64_decode($decodedState, true);
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
				// Allow root path "/" and relative URLs starting with /
				if (
					$redirectTo === '/' ||
					preg_match('/^\/[^\/]/', $redirectTo) ||
					(parse_url($redirectTo, PHP_URL_HOST) === $_SERVER['HTTP_HOST'])
				) {
					return true;
				}
				return false;
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
	private function getOAuth2Config() {
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
	private function getOAuth2AccessToken() {
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
	private function generateTokenFromProxyAuth() {
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
	private function createBigQueryClientWithOAuth2($location) {
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
	private function createBigQueryClientWithServiceAccount($location) {
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
	private function initiateOAuth2Flow() {
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
	private function handleOAuth2Callback() {
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
			if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $_GET['code'])) {
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
	private function exchangeCodeForToken($code, $clientId, $redirectUrl) {
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
	private function storeOAuth2Token($tokenData) {
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
	private function validateCredentialsFile($credentialsPath) {
		$fileInfo = @stat($credentialsPath);
		if ($fileInfo === false) {
			throw new Exception("Service account file not found: {$credentialsPath}");
		}
		if (!($fileInfo['mode'] & 0444)) {
			throw new Exception("Service account file not readable: {$credentialsPath}");
		}
	}
	private function scheduleLocationDetection($projectId, $defaultLocation) {
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
					}
					break;
				} catch (Exception $e) {
					// $e パラメータは例外処理のため保持（ログ出力は不要）
					break;
				}
			}
		} catch (Exception $e) {
		}
	}

	private static function performBackgroundLocationDetection($projectId, $defaultLocation, $clientKey) {
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

	private static function setCachedLocationStatic($projectId, $location) {
		$cacheFile = sys_get_temp_dir() . "/bq_location_" . md5($projectId) . ".cache";
		$cacheData = array(
			'location' => $location,
			'expires' => time() + 86400
		);
		@file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
	}
	function query($query) {
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
	private function checkJobStatus($job) {
		$jobInfo = $job->info();
		if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
			$errorResult = $jobInfo['status']['errorResult'] ?? null;
			if ($errorResult) {
				throw new Exception("BigQuery job failed: " . ($errorResult['message'] ?? 'Unknown error'));
			}
		}
	}
	private function validateReadOnlyQuery($query) {
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
	private function determineQueryLocation() {
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
	function select_db($database) {
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
	function quote($idf) {
		return BigQueryUtils::escapeIdentifier($idf);
	}
	function error() {

		if (!empty($this->error)) {
			return $this->error;
		}

		return "Check server logs for detailed error information";
	}

	function multi_query($query) {

		return $this->query($query);
	}

	function store_result() {

		return $this->last_result;
	}

	function next_result() {

		return false;
	}
}
