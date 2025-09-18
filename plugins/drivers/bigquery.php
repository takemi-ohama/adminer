<?php
/**
 * BigQuery driver for Adminer
 *
 * This driver provides READ-ONLY access to Google Cloud BigQuery datasets and tables.
 * It supports basic operations like dataset browsing, table schema viewing, and SELECT queries.
 *
 * Requirements:
 * - PHP 7.4+
 * - google/cloud-bigquery package via Composer
 * - GOOGLE_APPLICATION_CREDENTIALS environment variable set
 *
 * @author Claude Code
 * @license Apache-2.0, GPL-2.0-only
 */

// Define driver constant
define('Adminer\\DRIVER', "bigquery");

// Import required BigQuery classes
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Table;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\ServiceException;

/**
 * BigQuery database connection handler
 */
class Db {
    /** @var BigQueryClient */
    public $bigQueryClient;

    /** @var string Current project ID */
    public $projectId;

    /** @var string Current dataset ID */
    public $datasetId = '';

    /** @var array Connection configuration */
    public $config = [];

    /**
     * Establish connection to BigQuery
     *
     * @param string $server GCP Project ID (optionally with location)
     * @param string $username Not used for BigQuery
     * @param string $password Not used for BigQuery
     * @return bool Connection success
     */
    public function connect($server, $username, $password) {
        try {
            // Parse project ID and optional location from server parameter
            $parts = explode(':', $server);
            $this->projectId = $parts[0];
            $location = isset($parts[1]) ? $parts[1] : 'US';

            // Initialize BigQuery client with service account authentication
            $this->config = [
                'projectId' => $this->projectId,
                'location' => $location
            ];

            // Check for authentication credentials
            if (!getenv('GOOGLE_APPLICATION_CREDENTIALS') && !getenv('GOOGLE_CLOUD_PROJECT')) {
                throw new Exception('BigQuery authentication not configured. Set GOOGLE_APPLICATION_CREDENTIALS environment variable.');
            }

            $this->bigQueryClient = new BigQueryClient($this->config);

            // Test connection by attempting to list datasets
            $datasets = $this->bigQueryClient->datasets(['maxResults' => 1]);
            iterator_to_array($datasets); // Force API call

            return true;

        } catch (ServiceException $e) {
            error_log("BigQuery connection error: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            error_log("BigQuery setup error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute a SELECT query
     *
     * @param string $query SQL query to execute
     * @return Result|false Query result or false on error
     */
    public function query($query) {
        try {
            // For MVP, only allow SELECT queries (READ-ONLY)
            if (!preg_match('/^\s*SELECT\s+/i', trim($query))) {
                throw new Exception('Only SELECT queries are supported in READ-ONLY mode');
            }

            // Configure query job
            $queryConfig = $this->bigQueryClient->query($query)
                ->jobConfig(['location' => $this->config['location'] ?? 'US']);

            // Run query synchronously
            $queryResults = $this->bigQueryClient->runQuery($queryConfig);

            // Wait for job completion if needed
            if (!$queryResults->isComplete()) {
                $queryResults->waitUntilComplete();
            }

            return new Result($queryResults);

        } catch (ServiceException $e) {
            error_log("BigQuery query error: " . $e->getMessage());
            return false;
        } catch (Exception $e) {
            error_log("BigQuery query error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Select a dataset (database in Adminer terms)
     *
     * @param string $database Dataset ID
     * @return bool Success
     */
    public function select_db($database) {
        try {
            $dataset = $this->bigQueryClient->dataset($database);
            $dataset->reload(); // Test if dataset exists
            $this->datasetId = $database;
            return true;
        } catch (ServiceException $e) {
            error_log("Dataset selection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Quote identifier for BigQuery
     *
     * @param string $idf Identifier to quote
     * @return string Quoted identifier
     */
    public function quote($idf) {
        return "`" . str_replace("`", "\\`", $idf) . "`";
    }

    /**
     * Get last error message
     *
     * @return string Error message
     */
    public function error() {
        // Error logging is handled in individual methods
        return "Check server logs for detailed error information";
    }
}

/**
 * Query result wrapper for BigQuery
 */
class Result {
    /** @var \Google\Cloud\BigQuery\QueryResults */
    private $queryResults;

    /** @var array Current row data */
    private $currentRow = null;

    /** @var int Current row number */
    private $rowNumber = 0;

    /** @var array Field information cache */
    private $fieldsCache = null;

    public function __construct($queryResults) {
        $this->queryResults = $queryResults;
    }

    /**
     * Fetch next row as associative array
     *
     * @return array|false Row data or false if no more rows
     */
    public function fetch_assoc() {
        try {
            foreach ($this->queryResults as $row) {
                $this->currentRow = $row;
                $this->rowNumber++;
                return $row;
            }
            return false;
        } catch (Exception $e) {
            error_log("Result fetch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch next row as indexed array
     *
     * @return array|false Row data or false if no more rows
     */
    public function fetch_row() {
        $assoc = $this->fetch_assoc();
        return $assoc ? array_values($assoc) : false;
    }

    /**
     * Get number of fields in result
     *
     * @return int Number of fields
     */
    public function num_fields() {
        if ($this->fieldsCache === null) {
            $this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? [];
        }
        return count($this->fieldsCache);
    }

    /**
     * Get field information
     *
     * @param int $offset Field offset
     * @return object|false Field information
     */
    public function fetch_field($offset = 0) {
        if ($this->fieldsCache === null) {
            $this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? [];
        }

        if (!isset($this->fieldsCache[$offset])) {
            return false;
        }

        $field = $this->fieldsCache[$offset];

        // Create field object compatible with Adminer
        return (object) [
            'name' => $field['name'],
            'type' => $this->mapBigQueryType($field['type']),
            'length' => null,
            'flags' => ($field['mode'] ?? 'NULLABLE') === 'REQUIRED' ? 'NOT NULL' : ''
        ];
    }

    /**
     * Map BigQuery data types to MySQL-compatible types
     *
     * @param string $bigQueryType BigQuery field type
     * @return string Compatible type name
     */
    private function mapBigQueryType($bigQueryType) {
        $typeMap = [
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
        ];

        return $typeMap[strtoupper($bigQueryType)] ?? 'text';
    }
}

/**
 * Main BigQuery driver class
 */
class Driver {
    /** @var array Supported file extensions */
    public $extensions = ["BigQuery"];

    /** @var string Syntax highlighting identifier */
    public $jush = "sql";

    /**
     * Connect to BigQuery
     *
     * @param string $server Project ID
     * @param string $username Not used
     * @param string $password Not used
     * @return Db|false Database connection
     */
    public function connect($server, $username, $password) {
        $db = new Db();
        if ($db->connect($server, $username, $password)) {
            return $db;
        }
        return false;
    }
}

// Global support function for BigQuery features
function support($feature) {
    // Define supported features for READ-ONLY MVP
    $supportedFeatures = [
        'database', 'table', 'columns', 'sql', 'view', 'materializedview'
    ];

    // Features explicitly not supported
    $unsupportedFeatures = [
        'foreignkeys', 'indexes', 'processlist', 'kill', 'transaction',
        'comment', 'drop_col', 'dump', 'event', 'move_col', 'privileges',
        'procedure', 'routine', 'scheme', 'sequence', 'status', 'trigger',
        'type', 'variables', 'descidx', 'check'
    ];

    if (in_array($feature, $supportedFeatures)) {
        return true;
    }

    if (in_array($feature, $unsupportedFeatures)) {
        return false;
    }

    // Default to false for unknown features
    return false;
}

// Global functions for database operations
function logged_user() {
    return "BigQuery Service Account";
}

function get_databases($flush = false) {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        $datasets = [];
        foreach ($connection->bigQueryClient->datasets() as $dataset) {
            $datasets[] = $dataset->id();
        }
        sort($datasets);
        return $datasets;
    } catch (Exception $e) {
        error_log("Error listing datasets: " . $e->getMessage());
        return [];
    }
}

function tables_list($database = '') {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        $dataset = $connection->bigQueryClient->dataset($database ?: $connection->datasetId);
        $tables = [];

        foreach ($dataset->tables() as $table) {
            $tables[$table->id()] = 'table';
        }

        return $tables;
    } catch (Exception $e) {
        error_log("Error listing tables: " . $e->getMessage());
        return [];
    }
}

function table_status($database = '') {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        $dataset = $connection->bigQueryClient->dataset($database ?: $connection->datasetId);
        $tables = [];

        foreach ($dataset->tables() as $table) {
            $tableInfo = $table->info();
            $tables[] = [
                'Name' => $table->id(),
                'Engine' => 'BigQuery',
                'Rows' => $tableInfo['numRows'] ?? 0,
                'Data_length' => $tableInfo['numBytes'] ?? 0,
                'Comment' => $tableInfo['description'] ?? '',
                'Type' => $tableInfo['type'] ?? 'TABLE'
            ];
        }

        return $tables;
    } catch (Exception $e) {
        error_log("Error getting table status: " . $e->getMessage());
        return [];
    }
}

function fields($table) {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        $parts = explode('.', $table);
        $datasetId = count($parts) > 1 ? $parts[0] : $connection->datasetId;
        $tableId = count($parts) > 1 ? $parts[1] : $parts[0];

        $dataset = $connection->bigQueryClient->dataset($datasetId);
        $tableObj = $dataset->table($tableId);
        $tableInfo = $tableObj->info();

        $fields = [];
        foreach ($tableInfo['schema']['fields'] as $field) {
            $fields[] = [
                'field' => $field['name'],
                'type' => strtolower($field['type']),
                'null' => ($field['mode'] ?? 'NULLABLE') !== 'REQUIRED',
                'default' => null,
                'comment' => $field['description'] ?? ''
            ];
        }

        return $fields;
    } catch (Exception $e) {
        error_log("Error getting table fields: " . $e->getMessage());
        return [];
    }
}

// Initialize driver
if (class_exists('Adminer') || interface_exists('AdminerPlugin')) {
    // Register BigQuery driver
    new AdminerPlugin();
}