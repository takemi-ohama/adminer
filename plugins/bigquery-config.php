<?php
/**
 * BigQuery configuration for Adminer
 *
 * This file provides configuration options for the BigQuery driver plugin.
 * Include this file along with the BigQuery driver to enable BigQuery support.
 *
 * Usage:
 * 1. Set GOOGLE_APPLICATION_CREDENTIALS environment variable
 * 2. Include this file in your Adminer setup
 * 3. Use project ID as server name in login form
 *
 * @author Claude Code
 * @license Apache-2.0, GPL-2.0-only
 */

// Ensure BigQuery driver is loaded
require_once __DIR__ . '/drivers/bigquery.php';

/**
 * BigQuery login server configuration
 */
class AdminerBigQueryServers {
    /** @var array Server configurations */
    private $servers;

    /**
     * Constructor with default BigQuery servers
     *
     * @param array $servers Custom server configurations
     */
    public function __construct($servers = []) {
        // Default servers - can be customized via environment variables
        $defaultServers = [
            'BigQuery (Default Project)' => [
                'server' => getenv('BQ_PROJECT') ?: 'your-gcp-project-id',
                'driver' => 'bigquery',
                'username' => '',
                'password' => ''
            ]
        ];

        $this->servers = array_merge($defaultServers, $servers);
    }

    /**
     * Login form customization
     */
    public function login($login, $password) {
        return null; // Use default login handling
    }

    /**
     * Login form server options
     */
    public function loginForm() {
        echo "<table cellspacing='0' class='layout'>\n";
        echo "<tr><th>Server<td>";
        echo "<select name='auth[server]' onchange='selectServer(this.value);'>";

        foreach ($this->servers as $name => $config) {
            $selected = ($_GET['server'] ?? '') === $config['server'] ? ' selected' : '';
            echo "<option value='" . htmlspecialchars($config['server']) . "'$selected>"
                . htmlspecialchars($name) . "</option>";
        }

        echo "</select>\n";
        echo "<tr><th>Project ID<td><input name='auth[server]' value='"
            . htmlspecialchars($_GET['server'] ?? '') . "' title='GCP Project ID'>\n";
        echo "<tr><th>Driver<td>";
        echo "<select name='auth[driver]'>";
        echo "<option value='bigquery' selected>BigQuery</option>";
        echo "</select>\n";
        echo "</table>\n";

        // JavaScript for server selection
        echo "<script>
        function selectServer(server) {
            document.querySelector('input[name=\"auth[server]\"]').value = server;
        }
        </script>";

        return true;
    }

    /**
     * Database selection customization
     */
    public function databases() {
        return null; // Use default database listing
    }
}

/**
 * Simple BigQuery driver registration
 */
class AdminerBigQueryDriver {
    /** @var array Configuration options */
    private $config;

    /**
     * Constructor
     *
     * @param array $config Driver configuration
     */
    public function __construct($config = []) {
        $this->config = array_merge([
            'readOnly' => true,
            'queryTimeout' => 30,
            'defaultProject' => getenv('BQ_PROJECT'),
            'location' => getenv('BQ_LOCATION') ?: 'US'
        ], $config);
    }

    /**
     * Check if this is a BigQuery connection
     */
    private function isBigQueryDriver() {
        return ($_GET['driver'] ?? '') === 'bigquery' ||
               (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') === 'bigquery');
    }

    /**
     * Login form customization for BigQuery
     */
    public function loginForm() {
        if ($this->isBigQueryDriver()) {
            echo "<p><strong>BigQuery Connection:</strong></p>";
            echo "<ul>";
            echo "<li>Server: Enter your GCP Project ID</li>";
            echo "<li>Username/Password: Leave empty (uses service account)</li>";
            echo "<li>Authentication: Set GOOGLE_APPLICATION_CREDENTIALS environment variable</li>";
            echo "</ul>";

            if ($this->config['readOnly']) {
                echo "<p><em>Note: Running in READ-ONLY mode. Only SELECT queries are supported.</em></p>";
            }
        }
        return false; // Don't replace default form
    }

    /**
     * Database name formatting
     */
    public function database() {
        if ($this->isBigQueryDriver()) {
            return DB . " (BigQuery Dataset)";
        }
        return null;
    }

    /**
     * Query execution customization
     */
    public function selectQuery($query, $start) {
        if ($this->isBigQueryDriver() && $this->config['readOnly']) {
            // Ensure only SELECT queries in read-only mode
            if (!preg_match('/^\s*SELECT\s+/i', trim($query))) {
                return "-- READ-ONLY MODE: Only SELECT queries are allowed\n-- Original query blocked for security";
            }
        }
        return $query;
    }
}

// Auto-register plugins if this file is included directly
if (class_exists('Adminer') || interface_exists('AdminerPlugin')) {
    // Create instances for automatic registration
    $bigQueryDriver = new AdminerBigQueryDriver();
    $bigQueryServers = new AdminerBigQueryServers();
}