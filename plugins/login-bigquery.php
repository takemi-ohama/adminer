<?php

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

		// BigQuery認証用の固定クレデンシャル設定
		$_GET['username'] = 'bigquery-service-account';
		$_POST['auth']['username'] = 'bigquery-service-account';

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
		// BigQueryサービスアカウント認証は常に許可
		if ($login === 'bigquery-service-account' || $password === 'service-account-auth') {
			return true;
		}

		// 空のusernameでもBigQueryドライバー使用時は許可
		if (($_GET['bigquery'] ?? $_POST['auth']['server'] ?? '') !== '') {
			return true;
		}

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


	function operators()
	{
		return array(
			"=", "!=", "<>", "<", "<=", ">", ">=",
			"IN", "NOT IN", "IS NULL", "IS NOT NULL",
			"LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
		);
	}

	function database()
	{
		// BigQueryプロジェクト内の全てのデータセットへのアクセスを許可
		return true;
	}

	function databasesPrint($missing)
	{
		// データベース（データセット）一覧の表示を許可
		return true;
	}

	function authUrl($vendor, $server, $username, $database)
	{
		// BigQuery認証URL生成時に空のusernameパラメータを除去
		if ($vendor === 'bigquery') {
			// プロジェクトIDのみでアクセスするURLを生成
			$url = remove_from_uri('username');
			// usernameパラメータを完全に除去
			$url = preg_replace('/[&?]username=[^&]*/', '', $url);
			// 重複する &, ? を整理
			$url = preg_replace('/[&?]{2,}/', '&', $url);
			$url = rtrim($url, '&?');
			return $url;
		}
		return null;
	}

	function tableName($tableStatus)
	{
		// テーブル名表示権限の許可
		return h($tableStatus["Name"]);
	}

	function fieldName($field, $order = 0)
	{
		// フィールド名表示権限の許可
		return '<span title="' . h($field["full_type"]) . '">' . h($field["field"]) . '</span>';
	}

	function loginForm()
	{
		// 隠しフィールドのスタイル設定
		echo "<style>";
		echo ".layout tr:has(input[type='hidden']) { display: none; }";
		echo "</style>";

		// URLからusernameパラメータを除去するJavaScript
		echo "<script>";
		echo "if (window.location.search.includes('username=') && window.location.search.includes('bigquery=')) {";
		echo "  var url = window.location.href.replace(/[&?]username=[^&]*/, '');";
		echo "  url = url.replace(/[&?]{2,}/, '&').replace(/[&?]$/, '');";
		echo "  if (url !== window.location.href) {";
		echo "    window.location.replace(url);";
		echo "  }";
		echo "}";
		echo "</script>";

		// BigQuery認証状態をグローバルで設定
		global $adminer;
		if (isset($_POST['auth']['driver']) && $_POST['auth']['driver'] === 'bigquery') {
			$_SESSION['bigquery_authenticated'] = true;
		}
	}

	protected $translations = array(
		'en' => array('' => 'BigQuery authentication with service account credentials'),
		'ja' => array('' => 'サービスアカウント認証情報によるBigQuery認証'),
	);
}
