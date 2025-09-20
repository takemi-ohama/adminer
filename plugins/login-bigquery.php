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
