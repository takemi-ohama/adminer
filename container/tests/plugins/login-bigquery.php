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
    protected $project_id;
    protected $credentials_path;

    /** Set default BigQuery configuration
     * @param string $project_id Default GCP Project ID
     * @param string $credentials_path Default credentials file path
     */
    function __construct($project_id = 'nyle-carmo-analysis', $credentials_path = '/etc/google_credentials.json') {
        $this->project_id = $project_id;
        $this->credentials_path = $credentials_path;

        // Force BigQuery driver selection
        if ($_POST["auth"]) {
            $_POST["auth"]["driver"] = 'bigquery';
            // Store credentials path from password field
            if (isset($_POST["auth"]["password"]) && !empty($_POST["auth"]["password"])) {
                $_POST["auth"]["credentials"] = $_POST["auth"]["password"];
            }
        }
    }

    function credentials() {
        // Return: [server, username, password]
        $server = $_GET["server"] ?? $_POST["auth"]["server"] ?? $this->project_id;
        $credentials = $_GET["credentials"] ?? $_POST["auth"]["credentials"] ?? $this->credentials_path;

        // Set environment variable for BigQuery connection
        if ($credentials) {
            putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $credentials);
            $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentials;
        }

        return array($server, '', ''); // No username/password for BigQuery
    }

    function login($login, $password) {
        // Validate credentials file existence
        $credentials_path = $_POST["auth"]["password"] ?? $this->credentials_path;

        if (empty($credentials_path)) {
            return 'BigQuery requires a credentials file path.';
        }

        if (!file_exists($credentials_path)) {
            return "Credentials file not found: " . $credentials_path;
        }

        if (!is_readable($credentials_path)) {
            return "Credentials file not readable: " . $credentials_path;
        }

        // Validate JSON format
        $json_content = file_get_contents($credentials_path);
        $credentials_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return "Invalid JSON format in credentials file.";
        }

        if (!isset($credentials_data['type']) || $credentials_data['type'] !== 'service_account') {
            return "Credentials file must be a service account key.";
        }

        return true; // Login successful
    }

    function loginFormField($name, $heading, $value) {
        if ($name == 'driver') {
            // Fixed BigQuery selection
            return $heading . '<select name="auth[driver]" readonly><option value="bigquery" selected>Google BigQuery</option></select>' . "\n";
        } elseif ($name == 'server') {
            // Project ID input
            $default_value = htmlspecialchars($_GET["server"] ?? $_POST["auth"]["server"] ?? $this->project_id);
            return '<tr><th>Project ID<td><input name="auth[server]" value="' . $default_value . '" title="GCP Project ID" placeholder="your-project-id" autocapitalize="off" required>' . "\n";
        } elseif ($name == 'username') {
            // Hide username field
            return '<input type="hidden" name="auth[username]" value="">' . "\n";
        } elseif ($name == 'password') {
            // Credentials file path input
            $default_value = htmlspecialchars($_POST["auth"]["password"] ?? $this->credentials_path);
            return '<tr><th>Credentials File<td><input type="text" name="auth[password]" value="' . $default_value . '" title="Path to Google Application Credentials JSON file" placeholder="/path/to/credentials.json" autocapitalize="off" required>' . "\n";
        } elseif ($name == 'db') {
            // Hide database field
            return '<input type="hidden" name="auth[db]" value="">' . "\n";
        }
        return '';
    }

    function loginForm() {
        echo "<style>";
        echo ".layout tr:has(input[type='hidden']) { display: none; }";
        echo "</style>";
    }

    protected $translations = array(
        'en' => array('' => 'BigQuery authentication with service account credentials'),
        'ja' => array('' => 'サービスアカウント認証情報によるBigQuery認証'),
    );
}