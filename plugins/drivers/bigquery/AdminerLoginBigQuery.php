<?php

namespace Adminer;

/**
 * AdminerLoginBigQuery - BigQuery認証用のログインプラグイン
 */
class AdminerLoginBigQuery extends \Adminer\Plugin
{
	protected $config;

	function __construct($config = array())
	{
		$this->config = $config;
		$this->initializeDriverSelection();
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
	 * OAuth2設定を取得
	 */
	private function getOAuth2Config()
	{
		return [
			'client_id' => getenv('GOOGLE_OAUTH2_CLIENT_ID'),
			'redirect_url' => getenv('GOOGLE_OAUTH2_REDIRECT_URL')
		];
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
		// OAuth2認証が有効な場合は異なる表示
		if ($this->isOAuth2Enabled()) {
			return $this->renderOAuth2Field($name, $heading, $value);
		}

		// 従来のCREDENTIAL認証
		$fieldHandlers = array(
			'driver' => function () use ($heading) {
				return $this->renderDriverField($heading);
			},
			'server' => function () {
				return $this->renderProjectIdField();
			},
			'username' => function () {
				return $this->renderHiddenField('username');
			},
			'password' => function () {
				return $this->renderHiddenField('password');
			},
			'db' => function () {
				return $this->renderHiddenField('db');
			}
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

	/**
	 * OAuth2認証用のフィールドを描画
	 */
	private function renderOAuth2Field($name, $heading, $value)
	{
		switch ($name) {
			case 'driver':
				return $this->renderDriverField($heading);
			case 'server':
				return $this->renderOAuth2ProjectIdField();
			case 'username':
			case 'password':
			case 'db':
				return $this->renderHiddenField($name);
			default:
				return '';
		}
	}

	/**
	 * OAuth2認証用のProject IDフィールドを描画
	 */
	private function renderOAuth2ProjectIdField()
	{
		$default_value = htmlspecialchars($this->getProjectId());
		return '<tr><th>Project ID</th><td><input name="auth[server]" value="' . $default_value . '" title="GCP Project ID" placeholder="your-project-id" autocapitalize="off" required></td></tr>' . "\n";
	}

	/**
	 * Google OAuth2ログインボタンを描画
	 */
	private function renderOAuth2LoginButton()
	{
		$config = $this->getOAuth2Config();
		$clientId = $config['client_id'];
		$redirectUrl = $config['redirect_url'];

		if (!$clientId || !$redirectUrl) {
			return '<div class="oauth2-error">OAuth2 configuration incomplete. Please set GOOGLE_OAUTH2_CLIENT_ID and GOOGLE_OAUTH2_REDIRECT_URL.</div>';
		}

		// Google OAuth2 認証URL を構築
		$scope = urlencode('https://www.googleapis.com/auth/bigquery https://www.googleapis.com/auth/cloud-platform');
		$state = urlencode(base64_encode(json_encode(['redirect_to' => $_SERVER['REQUEST_URI']])));

		$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
			'client_id' => $clientId,
			'redirect_uri' => $redirectUrl,
			'scope' => 'https://www.googleapis.com/auth/bigquery https://www.googleapis.com/auth/cloud-platform',
			'response_type' => 'code',
			'state' => $state,
			'access_type' => 'offline',
			'prompt' => 'consent'
		]);

		return '
		<div class="oauth2-login-container">
			<div class="oauth2-logo">
				<svg width="40" height="40" viewBox="0 0 24 24" fill="none">
					<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
					<path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
					<path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
					<path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
				</svg>
			</div>
			<h2>Adminer</h2>
			<a href="' . htmlspecialchars($authUrl) . '" class="oauth2-signin-button">Sign in with Google</a>
		</div>';
	}

	function loginForm()
	{
		// OAuth2認証が有効な場合は専用のログイン画面を表示
		if ($this->isOAuth2Enabled()) {
			echo "<style>";
			echo ".layout { display: none; }"; // 通常のログインフォームを非表示
			echo ".oauth2-login-container {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				min-height: 60vh;
				text-align: center;
				background: #f5f5f5;
				margin: 20px;
				border-radius: 8px;
				padding: 40px;
				box-shadow: 0 2px 10px rgba(0,0,0,0.1);
			}";
			echo ".oauth2-logo {
				margin-bottom: 20px;
			}";
			echo ".oauth2-login-container h2 {
				color: #5f6368;
				font-size: 24px;
				font-weight: 400;
				margin: 10px 0 30px 0;
			}";
			echo ".oauth2-signin-button {
				background: #1a73e8;
				color: white;
				border: none;
				border-radius: 4px;
				padding: 12px 24px;
				font-size: 14px;
				font-weight: 500;
				text-decoration: none;
				display: inline-block;
				transition: background-color 0.2s;
			}";
			echo ".oauth2-signin-button:hover {
				background: #1557b0;
				color: white;
				text-decoration: none;
			}";
			echo ".oauth2-error {
				color: #d93025;
				background: #fce8e6;
				border: 1px solid #fce8e6;
				border-radius: 4px;
				padding: 12px 16px;
				margin: 20px 0;
			}";
			echo "</style>";

			// OAuth2ログインボタンを表示
			echo $this->renderOAuth2LoginButton();
		} else {
			// 従来のCREDENTIAL認証用のスタイル
			echo "<style>";
			echo ".layout tr:has(input[type='hidden']) { display: none; }";
			echo "</style>";
		}
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
