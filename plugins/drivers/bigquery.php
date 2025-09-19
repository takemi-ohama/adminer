<?php
namespace Adminer;

// Import required BigQuery classes
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Table;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\ServiceException;

if (function_exists('Adminer\\add_driver')) {
    add_driver("bigquery", "Google BigQuery");
}

if (isset($_GET["bigquery"])) {
	define('Adminer\DRIVER', "bigquery");

/**
 * BigQuery database connection handler
 */
class Db {
    /** @var Db */ 
    static $instance;

    /** @var BigQueryClient */
    public $bigQueryClient;

    /** @var string Current project ID */
    public $projectId;

    /** @var string Current dataset ID */
    public $datasetId = '';

    /** @var array Connection configuration */
    public $config = [];

    /** @var string Database flavor/version info */
    public $flavor = 'BigQuery';

    /** @var string Server version info */
    public $server_info = 'Google Cloud BigQuery';

    /** @var string Extension name */
    public $extension = 'BigQuery Driver';

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
            // Validate and parse project ID and optional location from server parameter
            if (empty($server)) {
                throw new Exception('Project ID is required');
            }

            $parts = explode(':', $server);
            $projectId = trim($parts[0]);

            // Validate project ID format
            if (!preg_match('/^[a-z0-9][a-z0-9\\-]{4,28}[a-z0-9]$/i', $projectId)) {
                throw new Exception('Invalid GCP Project ID format');
            }

            if (strlen($projectId) > 30) {
                throw new Exception('Project ID too long (max 30 characters)');
            }

            $this->projectId = $projectId;
            
            // Determine location priority order:
            // 1. Explicit location in server parameter (project:location)
            // 2. Environment variable BIGQUERY_LOCATION
            // 3. Detect from first dataset location
            // 4. Use 'US' as fallback
            $location = null;
            
            if (isset($parts[1]) && !empty($parts[1])) {
                $location = $parts[1];
            } elseif (getenv('BIGQUERY_LOCATION')) {
                $location = getenv('BIGQUERY_LOCATION');
            }
            
            // If no explicit location, we'll auto-detect from datasets later
            // For now, use 'US' as temporary default for client initialization
            $defaultLocation = $location ?? 'US';

            // Initialize BigQuery client with service account authentication
            $this->config = [
                'projectId' => $this->projectId,
                'location' => $defaultLocation
            ];

            // Check for custom credentials path from form input
            $customCredentialsPath = $_POST['auth']['credentials'] ?? null;
            $credentialsPath = null;
            
            if ($customCredentialsPath && !empty($customCredentialsPath)) {
                // Use custom credentials path if provided via form
                $credentialsPath = $customCredentialsPath;
                putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $credentialsPath);
                $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $credentialsPath;
            } else {
                // Fall back to existing environment variable
                $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
            }

            // Enhanced authentication check with detailed diagnostics
            if (!$credentialsPath && !getenv('GOOGLE_CLOUD_PROJECT')) {
                throw new Exception('BigQuery authentication not configured. Set GOOGLE_APPLICATION_CREDENTIALS environment variable or provide credentials file path.');
            }
            
            if ($credentialsPath && !file_exists($credentialsPath)) {
                throw new Exception("Service account file not found: {$credentialsPath}");
            }
            
            if ($credentialsPath && !is_readable($credentialsPath)) {
                throw new Exception("Service account file not readable: {$credentialsPath}");
            }

            // Log successful authentication setup (debug info)
            if ($credentialsPath) {
                error_log("BigQuery: Using service account file: {$credentialsPath}");
            }

            $this->bigQueryClient = new BigQueryClient($this->config);

            // If location was not explicitly set, try to auto-detect from datasets
            if (!$location) {
                $autoDetectedLocation = $this->detectLocationFromDatasets();
                if ($autoDetectedLocation && $autoDetectedLocation !== $defaultLocation) {
                    $this->config['location'] = $autoDetectedLocation;
                    error_log("BigQuery: Auto-detected location '$autoDetectedLocation' from project datasets");
                }
            }

            // Test connection by attempting to list datasets
            $datasets = $this->bigQueryClient->datasets(['maxResults' => 1]);
            iterator_to_array($datasets); // Force API call

            error_log("BigQuery: Connected to project '{$this->projectId}' with location '{$this->config['location']}'");
            return true;

        } catch (ServiceException $e) {
            // Log detailed error for debugging while redacting sensitive info
            $errorMessage = $e->getMessage();
            $safeMessage = preg_replace('/project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+/i', 'project: [REDACTED]', $errorMessage);
            error_log("BigQuery ServiceException: " . $safeMessage);
            
            // Check for common authentication issues
            if (strpos($errorMessage, 'UNAUTHENTICATED') !== false || strpos($errorMessage, '401') !== false) {
                error_log("BigQuery: Authentication failed. Check service account credentials.");
            }
            
            return false;
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $safeMessage = preg_replace('/project[s]?\\s*[:\\-]\\s*[a-z0-9\\-]+/i', 'project: [REDACTED]', $errorMessage);
            error_log("BigQuery Exception: " . $safeMessage);
            
            // Provide helpful diagnostic information
            if (strpos($errorMessage, 'OpenSSL') !== false) {
                error_log("BigQuery: Invalid private key in service account file.");
            }
            
            return false;
        }
    }

    /**
     * Auto-detect BigQuery location from existing datasets
     *
     * @return string|null Detected location or null if detection failed
     */
    private function detectLocationFromDatasets() {
        try {
            $datasets = $this->bigQueryClient->datasets(['maxResults' => 10]);
            $locationCounts = [];
            
            foreach ($datasets as $dataset) {
                $datasetInfo = $dataset->info();
                $location = $datasetInfo['location'] ?? 'US';
                $locationCounts[$location] = ($locationCounts[$location] ?? 0) + 1;
            }
            
            if (empty($locationCounts)) {
                return null;
            }
            
            // Return the most common location
            arsort($locationCounts);
            $mostCommonLocation = array_key_first($locationCounts);
            
            error_log("BigQuery: Location distribution in project: " . json_encode($locationCounts) . 
                     ", selected: $mostCommonLocation");
            
            return $mostCommonLocation;
            
        } catch (Exception $e) {
            error_log("BigQuery: Failed to auto-detect location: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate if query is safe for read-only access
     *
     * @param string $query SQL query to validate
     * @return bool True if query is safe
     * @throws Exception If query contains unsafe operations
     */
    private function validateReadOnlyQuery($query) {
        // Remove SQL comments to prevent bypass
        $cleanQuery = preg_replace('/--.*$/m', '', $query);
        $cleanQuery = preg_replace('/\\/\\*.*?\\*\\//s', '', $cleanQuery);
        $cleanQuery = trim($cleanQuery);

        // Must start with SELECT
        if (!preg_match('/^\\s*SELECT\\s+/i', $cleanQuery)) {
            throw new Exception('Only SELECT queries are supported in read-only mode');
        }

        // Block dangerous operations that might be hidden in subqueries or CTEs
        $dangerousPatterns = [
            '/\\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE)\\b/i',
            '/\\b(GRANT|REVOKE)\\b/i',
            '/\\bCALL\\s+/i',
            '/\\bEXEC(UTE)?\\s+/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $cleanQuery)) {
                throw new Exception('DDL/DML operations are not allowed in read-only mode');
            }
        }

        return true;
    }

    /**
     * Execute a SELECT query
     *
     * @param string $query SQL query to execute
     * @return Result|false Query result or false on error
     */
    public function query($query) {
        try {
            // Validate query for read-only safety
            $this->validateReadOnlyQuery($query);

            // Determine the appropriate location for query execution
            $queryLocation = $this->determineQueryLocation();

            // Configure query job with correct BigQuery API
            $queryJob = $this->bigQueryClient->query($query)
                ->useLegacySql(false)
                ->location($queryLocation)
                ->useQueryCache(true); // Enable query cache for better performance

            $job = $this->bigQueryClient->runQuery($queryJob);

            // Wait for job completion if needed
            if (!$job->isComplete()) {
                $job->waitUntilComplete();
            }

            // Check for job errors
            $jobInfo = $job->info();
            if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
                $errorResult = $jobInfo['status']['errorResult'] ?? null;
                if ($errorResult) {
                    throw new Exception("BigQuery job failed: " . ($errorResult['message'] ?? 'Unknown error'));
                }
            }

            error_log("BigQuery: Query executed successfully in location '$queryLocation'");
            return new Result($job);

        } catch (ServiceException $e) {
            // Log sanitized error message
            $safeMessage = preg_replace('/project[s]?[\\s:]+[a-z0-9\\-]+/i', 'project: [REDACTED]', $e->getMessage());
            error_log("BigQuery query error: " . $safeMessage);
            
            // Handle location-specific errors
            if (strpos($e->getMessage(), 'location') !== false) {
                error_log("BigQuery: Location-related error. Current location: " . ($this->config['location'] ?? 'NOT_SET'));
            }
            
            return false;
        } catch (Exception $e) {
            $safeMessage = preg_replace('/project[s]?[\\s:]+[a-z0-9\\-]+/i', 'project: [REDACTED]', $e->getMessage());
            error_log("BigQuery query error: " . $safeMessage);
            return false;
        }
    }

    /**
     * Determine the best location for query execution
     *
     * @return string Location to use for query execution
     */
    private function determineQueryLocation() {
        // Priority order for location determination:
        // 1. Current dataset location (if dataset is selected)
        // 2. Connection config location
        // 3. Environment variable
        // 4. US as fallback
        
        // If we have a selected dataset, use its location
        if (!empty($this->datasetId)) {
            try {
                $dataset = $this->bigQueryClient->dataset($this->datasetId);
                $datasetInfo = $dataset->info();
                $datasetLocation = $datasetInfo['location'] ?? null;
                
                if ($datasetLocation) {
                    // Update our config if dataset location differs
                    if ($datasetLocation !== ($this->config['location'] ?? '')) {
                        error_log("BigQuery: Using dataset location '$datasetLocation' for query execution");
                        $this->config['location'] = $datasetLocation;
                    }
                    return $datasetLocation;
                }
            } catch (Exception $e) {
                error_log("BigQuery: Failed to get dataset location, falling back to config location");
            }
        }

        // Fall back to config location
        $configLocation = $this->config['location'] ?? null;
        if ($configLocation) {
            return $configLocation;
        }

        // Fall back to environment variable
        $envLocation = getenv('BIGQUERY_LOCATION');
        if ($envLocation) {
            error_log("BigQuery: Using environment location '$envLocation' for query execution");
            $this->config['location'] = $envLocation;
            return $envLocation;
        }

        // Final fallback to US
        error_log("BigQuery: No specific location configured, using 'US' as fallback");
        $this->config['location'] = 'US';
        return 'US';
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
            
            // Get dataset info to retrieve its location
            $datasetInfo = $dataset->info();
            $datasetLocation = $datasetInfo['location'] ?? 'US';
            
            // Update our config location to match the dataset location
            $previousLocation = $this->config['location'] ?? 'US';
            if ($datasetLocation !== $previousLocation) {
                error_log("BigQuery: Dataset '$database' is in location '$datasetLocation', updating connection from '$previousLocation'");
                $this->config['location'] = $datasetLocation;
            }
            
            $this->datasetId = $database;
            error_log("BigQuery: Successfully selected dataset '$database' in location '$datasetLocation'");
            return true;
            
        } catch (ServiceException $e) {
            $safeMessage = preg_replace('/dataset[s]?[\\s:]+[a-z0-9\\-_]+/i', 'dataset: [REDACTED]', $e->getMessage());
            error_log("BigQuery dataset selection error: " . $safeMessage);
            
            // Provide more specific error information
            if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'Not found') !== false) {
                error_log("BigQuery: Dataset '$database' not found in project '{$this->projectId}'");
            } elseif (strpos($e->getMessage(), 'permission') !== false || strpos($e->getMessage(), '403') !== false) {
                error_log("BigQuery: Access denied to dataset '$database'");
            }
            
            return false;
        } catch (Exception $e) {
            $safeMessage = preg_replace('/dataset[s]?[\\s:]+[a-z0-9\\-_]+/i', 'dataset: [REDACTED]', $e->getMessage());
            error_log("BigQuery dataset selection error: " . $safeMessage);
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
        return "`" . str_replace("`", "\\\\`", $idf) . "`";
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

    /** @var Iterator Result iterator */
    private $iterator = null;

    /** @var bool Is iterator initialized */
    private $isIteratorInitialized = false;

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
            if (!$this->isIteratorInitialized) {
                $this->iterator = $this->queryResults->getIterator();
                $this->isIteratorInitialized = true;
            }

            if ($this->iterator && $this->iterator->valid()) {
                $row = $this->iterator->current();
                $this->iterator->next();
                
                // Convert array values to strings for Adminer compatibility
                $processedRow = [];
                foreach ($row as $key => $value) {
                    if (is_array($value)) {
                        // Convert complex types (STRUCT, ARRAY, etc.) to JSON string
                        $processedRow[$key] = json_encode($value);
                    } elseif (is_object($value)) {
                        // Handle DateTime objects specifically
                        if ($value instanceof \DateTime) {
                            $processedRow[$key] = $value->format('Y-m-d H:i:s');
                        } elseif ($value instanceof \DateTimeInterface) {
                            $processedRow[$key] = $value->format('Y-m-d H:i:s');
                        } elseif (method_exists($value, 'format')) {
                            // For Google Cloud DateTime objects that have format method
                            try {
                                $processedRow[$key] = $value->format('Y-m-d H:i:s');
                            } catch (Exception $e) {
                                // Fallback to string conversion
                                $processedRow[$key] = (string) $value;
                            }
                        } elseif (method_exists($value, '__toString')) {
                            // Use __toString if available
                            $processedRow[$key] = (string) $value;
                        } else {
                            // Last resort: serialize or convert to string representation
                            $processedRow[$key] = json_encode($value);
                        }
                    } elseif (is_null($value)) {
                        // Keep null as is
                        $processedRow[$key] = null;
                    } else {
                        // Keep scalar values as is
                        $processedRow[$key] = $value;
                    }
                }
                
                $this->currentRow = $processedRow;
                $this->rowNumber++;
                return $processedRow;
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
    /** @var Driver */
    static $instance;

    /** @var array Supported file extensions */
    static $extensions = ["BigQuery"];

    /** @var string Syntax highlighting identifier */
    static $jush = "sql";

    /** @var array Supported operators */
    static $operators = array(
        "=", "!=", "<>", "<", "<=", ">", ">=",
        "IN", "NOT IN", "IS NULL", "IS NOT NULL",
        "LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
    );

    /**
     * Connect to BigQuery
     *
     * @param string $server Project ID
     * @param string $username Not used
     * @param string $password Not used
     * @return Db|false Database connection
     */
    static function connect($server, $username, $password) {
        $db = new Db();
        if ($db->connect($server, $username, $password)) {
            return $db;
        }
        return false;
    }

    /**
     * Get table help URL (not applicable for BigQuery)
     *
     * @param string $name Table name
     * @param bool $is_view Whether table is a view
     * @return string|null Help URL or null if not available
     */
    function tableHelp($name, $is_view = false) {
        // BigQuery doesn't have built-in help URLs in Adminer
        return null;
    }

    /**
     * Get structured types (not applicable for BigQuery)
     *
     * @return array Empty array as BigQuery doesn't use traditional structured types
     */
    function structuredTypes() {
        return [];
    }

    /**
     * Check inheritance relationship (not applicable for BigQuery)
     *
     * @param string $table Table name
     * @return array Empty array as BigQuery doesn't support table inheritance
     */
    function inheritsFrom($table) {
        return [];
    }

    /**
     * Get tables that inherit from the given table (not applicable for BigQuery)
     *
     * @param string $table Table name
     * @return array Empty array as BigQuery doesn't support table inheritance
     */
    function inheritedTables($table) {
        return [];
    }

    /**
     * Execute a SELECT query with BigQuery-compatible SQL
     *
     * @param string $table Table name
     * @param array $select Selected columns (* for all)
     * @param array $where WHERE conditions
     * @param array $group GROUP BY columns  
     * @param array $order ORDER BY specifications
     * @param int $limit LIMIT count
     * @param int $page Page number (for OFFSET calculation)
     * @param bool $print Whether to print query
     * @return Result|false Query result or false on error
     */
    function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
        global $connection;
        
        if (!$connection || !$connection->bigQueryClient) {
            return false;
        }

        try {
            // Build BigQuery-compatible SELECT statement
            $selectClause = ($select == array("*")) ? "*" : implode(", ", array_map(function($col) {
                return "`" . str_replace("`", "``", $col) . "`";
            }, $select));

            $database = $_GET['db'] ?? $connection->datasetId ?? '';
            if (empty($database)) {
                return false;
            }

            // Construct fully qualified table name for BigQuery
            $fullTableName = "`" . $connection->projectId . "`.`" . $database . "`.`" . $table . "`";
            
            $query = "SELECT $selectClause FROM $fullTableName";

            // Add WHERE conditions
            if (!empty($where)) {
                $whereClause = [];
                foreach ($where as $condition) {
                    // Convert Adminer WHERE format to BigQuery format
                    $whereClause[] = convertAdminerWhereToBigQuery($condition);
                }
                $query .= " WHERE " . implode(" AND ", $whereClause);
            }

            // Add GROUP BY
            if (!empty($group)) {
                $query .= " GROUP BY " . implode(", ", array_map(function($col) {
                    return "`" . str_replace("`", "``", $col) . "`";
                }, $group));
            }

            // Add ORDER BY
            if (!empty($order)) {
                $orderClause = [];
                foreach ($order as $orderSpec) {
                    // Handle "column DESC" format
                    if (preg_match('/^(.+?)\s+(DESC|ASC)$/i', $orderSpec, $matches)) {
                        $orderClause[] = "`" . str_replace("`", "``", $matches[1]) . "` " . $matches[2];
                    } else {
                        $orderClause[] = "`" . str_replace("`", "``", $orderSpec) . "`";
                    }
                }
                $query .= " ORDER BY " . implode(", ", $orderClause);
            }

            // Add LIMIT and OFFSET
            if ($limit > 0) {
                $query .= " LIMIT " . (int)$limit;
                if ($page > 0) {
                    $offset = $page * $limit;
                    $query .= " OFFSET " . (int)$offset;
                }
            }

            if ($print) {
                echo "<p><code>$query</code></p>";
            }

            error_log("BigQuery SELECT: $query");
            
            // Execute query using the connection's query method
            return $connection->query($query);

        } catch (Exception $e) {
            error_log("BigQuery select error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format field value for display and processing
     *
     * @param mixed $val Field value
     * @param array $field Field information
     * @return mixed Formatted value
     */
    function value($val, array $field) {
        // For BigQuery, handle specific data type conversions for display
        
        // Handle null values
        if ($val === null) {
            return null;
        }
        
        // Handle complex types that may be JSON encoded
        if (preg_match('~json~i', $field['type']) || preg_match('~struct|record|array~i', $field['type'])) {
            // If it's already a JSON string, return as-is
            if (is_string($val) && (substr($val, 0, 1) === '{' || substr($val, 0, 1) === '[')) {
                return $val;
            }
            // If it's an array or object, convert to JSON
            if (is_array($val) || is_object($val)) {
                return json_encode($val);
            }
        }
        
        // Handle geography types - convert to text representation
        if (preg_match('~geography~i', $field['type'])) {
            if (is_string($val)) {
                return $val; // Already converted to text by convert_field()
            }
        }
        
        // Handle binary data (bytes)
        if (preg_match('~bytes|blob~i', $field['type']) && is_string($val)) {
            // Return binary data as-is for download handling
            return $val;
        }
        
        // For all other types, return the value as-is
        return $val;
    }

	/** Convert field in select and edit
	* @param array $field
	* @return string|void
	*/
	function convert_field(array $field) {
		// BigQuery specific field conversions for display
		if (preg_match('~geography~i', $field['type'])) {
			return "ST_AsText(" . idf_escape($field['field']) . ")";
		}
		if (preg_match('~json~i', $field['type'])) {
			return "TO_JSON_STRING(" . idf_escape($field['field']) . ")";
		}
		// Default: no conversion needed for most BigQuery types
		return null;
	}

	// Removed idf_escape method - using namespace-level function instead
}

/**
 * Escape identifier for BigQuery
 *
 * @param string $idf Identifier to escape
 * @return string Escaped identifier
 */
function idf_escape($idf) {
    return "`" . str_replace("`", "``", $idf) . "`";
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
        'procedure', 'routine', 'sequence', 'status', 'trigger',
        'type', 'variables', 'descidx', 'check', 'schema'
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

/**
 * Get supported SQL operators for BigQuery
 *
 * @return array List of supported operators
 */
function operators() {
    return array(
        "=", "!=", "<>", "<", "<=", ">", ">=",
        "IN", "NOT IN", "IS NULL", "IS NOT NULL",
        "LIKE", "NOT LIKE", "REGEXP", "NOT REGEXP"
    );
}

/**
 * Get available collations (BigQuery doesn't use collations like traditional SQL databases)
 * 
 * @return array Empty array as BigQuery handles collation automatically
 */
function collations() {
    // BigQuery handles collation automatically based on data types and locale settings
    // Return empty array as traditional collation management is not applicable
    return array();
}

/**
 * Get database collation (not applicable for BigQuery)
 * 
 * @param string $db Database name
 * @return string Empty string as BigQuery doesn't use collations
 */
function db_collation($db) {
    // BigQuery does not use traditional database collations
    return "";
}

/**
 * Get information schema database name (not applicable for BigQuery)
 * 
 * @param string $db Database name  
 * @return string Empty string as BigQuery doesn't have information_schema like traditional databases
 */
function information_schema($db) {
    // BigQuery has its own metadata structure, not information_schema
    return "";
}

/**
 * Check if a table is a view (BigQuery has views, tables, and materialized views)
 * 
 * @param array $table_status Table status information
 * @return bool True if table is a view
 */
function is_view($table_status) {
    // In BigQuery context, check if table type indicates it's a view
    return isset($table_status["Engine"]) && 
           (strtolower($table_status["Engine"]) === "view" || 
            strtolower($table_status["Engine"]) === "materialized view");
}

/**
 * Check if foreign key support is available (BigQuery doesn't support foreign keys)
 * 
 * @param array $table_status Table status information
 * @return bool False as BigQuery doesn't support foreign keys
 */
function fk_support($table_status) {
    // BigQuery does not support foreign keys
    return false;
}

/**
 * Get table indexes (BigQuery doesn't use traditional indexes)
 * 
 * @param string $table Table name
 * @param mixed $connection2 Optional connection parameter
 * @return array Empty array as BigQuery doesn't support traditional indexes
 */
function indexes($table, $connection2 = null) {
    // BigQuery does not use traditional database indexes
    return [];
}

/**
 * Get foreign keys for a table (BigQuery doesn't support foreign keys)
 * 
 * @param string $table Table name
 * @return array Empty array as BigQuery doesn't support foreign keys
 */
function foreign_keys($table) {
    // BigQuery does not support foreign keys
    return [];
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
        // In BigQuery context: $database = dataset name
        $actualDatabase = '';
        
        if (!empty($database)) {
            $actualDatabase = $database;
        } else {
            // Try to get from URL parameters or connection
            $actualDatabase = $_GET['db'] ?? $connection->datasetId ?? '';
        }
        
        if (empty($actualDatabase)) {
            error_log("tables_list: No database (dataset) context available");
            return [];
        }
        
        error_log("tables_list called with database: '$database', using actual: '$actualDatabase'");
        
        $dataset = $connection->bigQueryClient->dataset($actualDatabase);
        $tables = [];

        foreach ($dataset->tables() as $table) {
            $tables[$table->id()] = 'table';
        }

        return $tables;
    } catch (Exception $e) {
        error_log("Error listing tables for database '$database' (actual: '$actualDatabase'): " . $e->getMessage());
        return [];
    }
}

function table_status($name = '', $fast = false) {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        error_log("table_status: No connection available, returning empty array");
        return [];
    }

    try {
        // Get the dataset from URL parameter
        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        
        if (empty($database)) {
            error_log("table_status: No database (dataset) context available, returning empty array");
            return [];
        }
        
        error_log("table_status called with name param: '$name', fast: " . ($fast ? 'true' : 'false') . ", using database: '$database'");
        
        $dataset = $connection->bigQueryClient->dataset($database);
        $tables = [];

        if ($name) {
            // Get info for specific table only - return as indexed array for Adminer compatibility
            try {
                $table = $dataset->table($name);
                $tableInfo = $table->info();
                $result = [
                    'Name' => $table->id(),
                    'Engine' => 'BigQuery',
                    'Rows' => $tableInfo['numRows'] ?? 0,
                    'Data_length' => $tableInfo['numBytes'] ?? 0,
                    'Comment' => $tableInfo['description'] ?? '',
                    'Type' => $tableInfo['type'] ?? 'TABLE',
                    // Add additional fields that Adminer may expect
                    'Collation' => '',
                    'Auto_increment' => '',
                    'Create_time' => $tableInfo['creationTime'] ?? '',
                    'Update_time' => $tableInfo['lastModifiedTime'] ?? '',
                    'Check_time' => '',
                    'Data_free' => 0,
                    'Index_length' => 0,
                    'Max_data_length' => 0,
                    'Avg_row_length' => $tableInfo['numRows'] > 0 ? intval(($tableInfo['numBytes'] ?? 0) / $tableInfo['numRows']) : 0,
                ];
                // Return as indexed array with table name as key for Adminer compatibility
                $tables[$table->id()] = $result;
                error_log("table_status: returning specific table '$name' info as indexed array");
            } catch (Exception $e) {
                error_log("Error getting specific table '$name' info: " . $e->getMessage() . ", returning empty array");
                return [];
            }
        } else {
            // Get info for all tables in the dataset (but limit to fast fields if $fast = true)
            foreach ($dataset->tables() as $table) {
                $tableInfo = $table->info();
                $result = [
                    'Name' => $table->id(),
                    'Engine' => 'BigQuery', 
                    'Comment' => $tableInfo['description'] ?? '',
                ];
                
                // Add additional fields only if not in fast mode
                if (!$fast) {
                    $result += [
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
                    ];
                }
                
                // Use table name as key for Adminer compatibility
                $tables[$table->id()] = $result;
            }
            error_log("table_status: returning " . count($tables) . " tables as indexed array (fast: " . ($fast ? 'true' : 'false') . ")");
        }

        // Ensure we always return an array, never null
        $result = is_array($tables) ? $tables : [];
        error_log("table_status: final result type: " . gettype($result) . ", count: " . count($result) . ", keys: " . implode(',', array_keys($result)));
        return $result;
        
    } catch (Exception $e) {
        error_log("Error getting table status for name '$name' (database: '$database'): " . $e->getMessage() . ", returning empty array");
        return [];
    }
}


/**
 * Convert Adminer WHERE condition format to BigQuery SQL format
 *
 * @param string $condition Adminer WHERE condition (e.g., "`column` = 'value'")
 * @return string BigQuery compatible WHERE condition
 */
function convertAdminerWhereToBigQuery($condition) {
    // Handle basic operators and quoted identifiers
    // Convert MySQL-style backticks to BigQuery format if needed
    $condition = preg_replace('/`([^`]+)`/', '`$1`', $condition);
    
    // Handle string literals - ensure proper escaping for BigQuery
    $condition = preg_replace_callback("/'([^']*)'/", function($matches) {
        return "'" . str_replace("'", "\\'", $matches[1]) . "'";
    }, $condition);
    
    return $condition;
}

/**
 * Map BigQuery data types to Adminer-compatible types
 *
 * @param string $bigQueryType BigQuery type string
 * @return array Type information with 'type' and optional 'length'
 */
function mapBigQueryTypeToAdminer($bigQueryType) {
    // Handle parameterized types (e.g., STRING(100), NUMERIC(10,2))
    $baseType = preg_replace('/\(.*\)/', '', $bigQueryType);
    
    switch (strtoupper($baseType)) {
        case 'STRING':
        case 'BYTES':
            return ['type' => 'varchar', 'length' => null];
        
        case 'INT64':
        case 'INTEGER':
            return ['type' => 'bigint', 'length' => null];
        
        case 'FLOAT64':
        case 'FLOAT':
            return ['type' => 'double', 'length' => null];
        
        case 'NUMERIC':
        case 'BIGNUMERIC':
            return ['type' => 'decimal', 'length' => null];
        
        case 'BOOLEAN':
        case 'BOOL':
            return ['type' => 'tinyint', 'length' => 1];
        
        case 'DATE':
            return ['type' => 'date', 'length' => null];
        
        case 'TIME':
            return ['type' => 'time', 'length' => null];
        
        case 'DATETIME':
            return ['type' => 'datetime', 'length' => null];
        
        case 'TIMESTAMP':
            return ['type' => 'timestamp', 'length' => null];
        
        case 'GEOGRAPHY':
            return ['type' => 'geometry', 'length' => null];
        
        case 'JSON':
            return ['type' => 'json', 'length' => null];
        
        case 'ARRAY':
            return ['type' => 'text', 'length' => null]; // Arrays displayed as text
        
        case 'STRUCT':
        case 'RECORD':
            return ['type' => 'text', 'length' => null]; // Structs displayed as text
        
        default:
            return ['type' => 'text', 'length' => null]; // Fallback for unknown types
    }
}

function fields($table) {
    global $connection;

    if (!$connection || !$connection->bigQueryClient) {
        return [];
    }

    try {
        // Get database (dataset) from URL parameters or connection
        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        
        if (empty($database)) {
            error_log("fields: No database (dataset) context available for table '$table'");
            return [];
        }

        error_log("fields called for table: '$table' in database: '$database'");

        $dataset = $connection->bigQueryClient->dataset($database);
        $tableObj = $dataset->table($table);
        
        // Check if table exists first
        if (!$tableObj->exists()) {
            error_log("Table '$table' does not exist in dataset '$database'");
            return [];
        }
        
        $tableInfo = $tableObj->info();

        if (!isset($tableInfo['schema']['fields'])) {
            error_log("No schema fields found for table '$table'");
            return [];
        }

        $fields = [];
        foreach ($tableInfo['schema']['fields'] as $field) {
            // Map BigQuery types to MySQL-compatible types for Adminer
            $bigQueryType = $field['type'] ?? 'STRING';
            $adminerTypeInfo = mapBigQueryTypeToAdminer($bigQueryType);
            
            // Extract length information if present in BigQuery type
            $length = null;
            if (preg_match('/\((\d+(?:,\d+)?)\)/', $bigQueryType, $matches)) {
                $length = $matches[1];
            }
            
            // Build type string in Adminer expected format
            $typeStr = $adminerTypeInfo['type'];
            if ($length !== null) {
                $typeStr .= "($length)";
            } elseif (isset($adminerTypeInfo['length']) && $adminerTypeInfo['length'] !== null) {
                $typeStr .= "(" . $adminerTypeInfo['length'] . ")";
            }
            
            $fields[$field['name']] = [
                'field' => $field['name'],
                'type' => $typeStr,
                'full_type' => $typeStr,
                'null' => ($field['mode'] ?? 'NULLABLE') !== 'REQUIRED',
                'default' => null,
                'auto_increment' => false,
                'comment' => $field['description'] ?? '',
                'privileges' => ['select' => 1, 'insert' => 1, 'update' => 1, 'where' => 1, 'order' => 1]
            ];
        }

        error_log("fields: Successfully retrieved " . count($fields) . " fields for table '$table'");
        return $fields;
    } catch (Exception $e) {
        error_log("Error getting table fields for '$table': " . $e->getMessage());
        return [];
    }
}

/**
 * Global select function for BigQuery driver
 * Executes SELECT queries in Adminer
 *
 * @param string $table Table name
 * @param array $select Selected columns (* for all)
 * @param array $where WHERE conditions
 * @param array $group GROUP BY columns  
 * @param array $order ORDER BY specifications
 * @param int $limit LIMIT count
 * @param int $page Page number (for OFFSET calculation)
 * @param bool $print Whether to print query
 * @return Result|false Query result or false on error
 */
function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
    global $connection;
    
    if (!$connection || !$connection->bigQueryClient) {
        return false;
    }

    try {
        // Build BigQuery-compatible SELECT statement
        $selectClause = ($select == array("*")) ? "*" : implode(", ", array_map(function($col) {
            return "`" . str_replace("`", "``", $col) . "`";
        }, $select));

        $database = $_GET['db'] ?? $connection->datasetId ?? '';
        if (empty($database)) {
            return false;
        }

        // Construct fully qualified table name for BigQuery
        $fullTableName = "`" . $connection->projectId . "`.`" . $database . "`.`" . $table . "`";
        
        $query = "SELECT $selectClause FROM $fullTableName";

        // Add WHERE conditions
        if (!empty($where)) {
            $whereClause = [];
            foreach ($where as $condition) {
                // Convert Adminer WHERE format to BigQuery format
                $whereClause[] = convertAdminerWhereToBigQuery($condition);
            }
            $query .= " WHERE " . implode(" AND ", $whereClause);
        }

        // Add GROUP BY
        if (!empty($group)) {
            $query .= " GROUP BY " . implode(", ", array_map(function($col) {
                return "`" . str_replace("`", "``", $col) . "`";
            }, $group));
        }

        // Add ORDER BY
        if (!empty($order)) {
            $orderClause = [];
            foreach ($order as $orderSpec) {
                // Handle "column DESC" format
                if (preg_match('/^(.+?)\s+(DESC|ASC)$/i', $orderSpec, $matches)) {
                    $orderClause[] = "`" . str_replace("`", "``", $matches[1]) . "` " . $matches[2];
                } else {
                    $orderClause[] = "`" . str_replace("`", "``", $orderSpec) . "`";
                }
            }
            $query .= " ORDER BY " . implode(", ", $orderClause);
        }

        // Add LIMIT and OFFSET
        if ($limit > 0) {
            $query .= " LIMIT " . (int)$limit;
            if ($page > 0) {
                $offset = $page * $limit;
                $query .= " OFFSET " . (int)$offset;
            }
        }

        if ($print) {
            echo "<p><code>" . htmlspecialchars($query) . "</code></p>";
        }

        error_log("BigQuery SELECT: $query");
        
        // Execute query using the connection's query method
        return $connection->query($query);

    } catch (Exception $e) {
        error_log("BigQuery select error: " . $e->getMessage());
        return false;
    }
}

/** Convert field in select and edit (global function for Adminer)
* @param array $field
* @return string|void
*/
if (!function_exists('convert_field')) {
    function convert_field(array $field) {
        // BigQuery specific field conversions for display
        if (preg_match('~geography~i', $field['type'])) {
            return "ST_AsText(" . idf_escape($field['field']) . ")";
        }
        if (preg_match('~json~i', $field['type'])) {
            return "TO_JSON_STRING(" . idf_escape($field['field']) . ")";
        }
        // Default: no conversion needed for most BigQuery types
        return null;
    }
}


/** Get escaped error message (global function for Adminer) */
if (!function_exists('error')) {
    function error() {
        global $connection;
        if ($connection) {
            return h($connection->error());
        }
        return '';
    }
}

/** Get approximate number of rows (global function for Adminer) */
if (!function_exists('found_rows')) {
    /**
     * Get approximate number of rows
     * @param array $table_status Table status information
     * @param array $where WHERE conditions
     * @return int|null Approximate row count or null if not available
     */
    function found_rows($table_status, $where) {
        // If WHERE conditions are present, we can't provide a meaningful estimate
        // without running a potentially expensive COUNT query
        if (!empty($where)) {
            return null;
        }

        // Return row count from table metadata if available
        if (isset($table_status['Rows']) && is_numeric($table_status['Rows'])) {
            return (int)$table_status['Rows'];
        }

        // For BigQuery, getting exact row counts can be expensive
        // Return null to indicate count is not readily available
        return null;
    }
}

// Close the if block for BigQuery driver
}