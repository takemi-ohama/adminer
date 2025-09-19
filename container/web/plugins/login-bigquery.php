<?php

/** BigQuery Login Plugin
 * Simplifies login form for BigQuery:
 * - System: Fixed to BigQuery
 * - Server: Project ID input
 * - Password: Credentials file path input
 * - Removes: Username and Database fields
 *
 * @author Adminer BigQuery Plugin
 * @license Apache License, Version 2.0
 */
class AdminerLoginBigQuery extends Adminer\Plugin {
    /** @var array Default configuration */
    private const DEFAULT_CONFIG = [
        'project_id' => '',
        'credentials_path' => '/etc/google_credentials.json'
    ];

    protected $config;

    /** Set default BigQuery configuration
     * @param array $config Configuration options
     */
    function __construct($config = []) {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);

        $this->initializeDriverSelection();
    }

    /**
     * Initialize BigQuery driver selection and credentials handling
     */
    private function initializeDriverSelection() {
        if (!isset($_POST["auth"])) {
            return;
        }

        $_POST["auth"]["driver"] = 'bigquery';

        // Store credentials path from password field
        if (isset($_POST["auth"]["password"]) && !empty($_POST["auth"]["password"])) {
            $_POST["auth"]["credentials"] = $_POST["auth"]["password"];
        }
    }

    function credentials() {
        // Return: [server, username, password]
        $server = $this->getProjectId();
        $credentials = $this->getCredentialsPath();

        $this->setEnvironmentCredentials($credentials);

        return array($server, '', ''); // No username/password for BigQuery
    }

    /**
     * Get project ID from various sources
     */
    private function getProjectId() {
        return $_GET["server"] ??
               $_POST["auth"]["server"] ??
               $this->config['project_id'];
    }

    /**
     * Get credentials path from various sources
     */
    private function getCredentialsPath() {
        return $_GET["credentials"] ??
               $_POST["auth"]["credentials"] ??
               $this->config['credentials_path'];
    }

    /**
     * Set environment variable for BigQuery connection
     */
    private function setEnvironmentCredentials($credentials) {
        if ($credentials) {
            putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $credentials);
            $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentials;
        }
    }

    function login($login, $password) {
        $credentials_path = $_POST["auth"]["password"] ?? $this->config['credentials_path'];

        $this->validateCredentials($credentials_path);

        return true; // 必ずtrueを返してAdminer標準チェックをバイパス
    }

    /**
     * Validate BigQuery credentials file
     * @param string $credentials_path Path to credentials file
     */
    private function validateCredentials($credentials_path) {
        // 検証ルールのマップ
        $validations = [
            'empty' => fn() => empty($credentials_path),
            'file_exists' => fn() => !file_exists($credentials_path),
            'readable' => fn() => !is_readable($credentials_path),
            'json_valid' => fn() => $this->isValidJsonCredentials($credentials_path)
        ];

        $errorMessages = [
            'empty' => "Credentials file path is empty",
            'file_exists' => "Credentials file not found: {$credentials_path}",
            'readable' => "Credentials file not readable: {$credentials_path}",
            'json_valid' => "Invalid credentials file format"
        ];

        foreach ($validations as $type => $validation) {
            if ($validation()) {
                error_log("BigQuery Login: " . $errorMessages[$type]);
                if ($type === 'json_valid') break; // 他の検証が通った場合のみJSONチェック
            }
        }

        if (!array_reduce($validations, fn($carry, $v) => $carry || $v(), false)) {
            error_log("BigQuery Login: Credentials validation successful");
        }
    }

    /**
     * Validate JSON credentials file format
     * @param string $credentials_path Path to credentials file
     * @return bool True if invalid
     */
    private function isValidJsonCredentials($credentials_path) {
        if (empty($credentials_path) || !file_exists($credentials_path) || !is_readable($credentials_path)) {
            return false; // Skip JSON validation if file issues exist
        }

        $json_content = file_get_contents($credentials_path);
        $credentials_data = json_decode($json_content, true);

        return json_last_error() !== JSON_ERROR_NONE ||
               !isset($credentials_data['type']) ||
               $credentials_data['type'] !== 'service_account';
    }

    function loginFormField($name, $heading, $value) {
        // フィールド処理ハンドラーのマップ
        $fieldHandlers = [
            'driver' => fn() => $this->renderDriverField($heading),
            'server' => fn() => $this->renderProjectIdField(),
            'username' => fn() => $this->renderHiddenField('username'),
            'password' => fn() => $this->renderCredentialsField(),
            'db' => fn() => $this->renderHiddenField('db')
        ];

        return isset($fieldHandlers[$name]) ? $fieldHandlers[$name]() : '';
    }

    /**
     * Render driver selection field
     */
    private function renderDriverField($heading) {
        return $heading . '<select name="auth[driver]" readonly><option value="bigquery" selected>Google BigQuery</option></select>' . "\n";
    }

    /**
     * Render Project ID input field
     */
    private function renderProjectIdField() {
        $default_value = htmlspecialchars($this->getProjectId());
        return '<tr><th>Project ID<td><input name="auth[server]" value="' . $default_value . '" title="GCP Project ID" placeholder="your-project-id" autocapitalize="off" required>' . "\n";
    }

    /**
     * Render credentials file path input field
     */
    private function renderCredentialsField() {
        $default_value = htmlspecialchars($_POST["auth"]["password"] ?? $this->config['credentials_path']);
        return '<tr><th>Credentials File<td><input type="text" name="auth[password]" value="' . $default_value . '" title="Path to Google Application Credentials JSON file" placeholder="/path/to/credentials.json" autocapitalize="off" required>' . "\n";
    }

    /**
     * Render hidden field
     */
    private function renderHiddenField($fieldName) {
        return '<input type="hidden" name="auth[' . $fieldName . ']" value="">' . "\n";
    }

    function loginForm() {
        echo "<style>";
        echo ".layout tr:has(input[type='hidden']) { display: none; }";
        echo "</style>";
    }

    function operators() {
        // BigQueryConfigの標準オペレーターを使用
        return [
            "=", "!=", "<>", "<", "<=", ">", ">=",
            "IN", "NOT IN", "IS NULL", "IS NOT NULL",
            "LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
        ];
    }

    protected $translations = array(
        'en' => array('' => 'BigQuery authentication with service account credentials'),
        'ja' => array('' => 'サービスアカウント認証情報によるBigQuery認証'),
    );
}