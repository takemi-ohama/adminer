<?php

namespace Adminer;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\ServiceException;
use Exception;
use InvalidArgumentException;

if (function_exists('Adminer\\add_driver')) {
	add_driver("bigquery", "Google BigQuery");
}

if (isset($_GET["bigquery"])) {
	define('Adminer\DRIVER', "bigquery");

	require_once __DIR__ . '/bigquery/AdminerLoginBigQuery.php';
	require_once __DIR__ . '/bigquery/adminer-bigquery-css.php';
	require_once __DIR__ . '/bigquery/Db.php';
	require_once __DIR__ . '/bigquery/bigquery-utils.php';
	require_once __DIR__ . '/bigquery/result.php';
	require_once __DIR__ . '/bigquery/driver.php';
	require_once __DIR__ . '/bigquery/BigQueryCacheManager.php';
	require_once __DIR__ . '/bigquery/BigQueryConnectionPool.php';
	require_once __DIR__ . '/bigquery/BigQueryConfig.php';

	function idf_escape($idf) {
		return BigQueryUtils::escapeIdentifier($idf);
	}

	function support($feature) {
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

	function dumpOutput() {
		// BigQuery用のExport出力オプション
		return array(
			'text' => 'Open', // ブラウザで表示
			'file' => 'Save', // ファイル保存
		);
	}

	function dumpFormat() {
		// BigQuery用のExport形式オプション
		return array(
			'csv' => 'CSV',
			'json' => 'JSON',
			'sql' => 'SQL',
		);
	}

	function dumpHeaders($identifier, $multi_table = false) {
		// $identifier, $multi_table パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
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

	function operators() {
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
	function collations() {

		return array(
			"unicode:cs" => "Unicode (大文字小文字区別)",
			"unicode:ci" => "Unicode (大文字小文字区別なし)",
			"" => "(デフォルト)"
		);
	}
	function db_collation($db) {

		if (!$db) {
			return "";
		}

		// BigQueryのデフォルト照合順序を返す
		// Unicode照合順序（大文字小文字区別）がデフォルト
		return "unicode:cs";
	}
	function information_schema($db) {
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
	function is_view($table_status) {
		return isset($table_status["Engine"]) &&
			(strtolower($table_status["Engine"]) === "view" ||
				strtolower($table_status["Engine"]) === "materialized view");
	}
	function fk_support($table_status) {
		// $table_status パラメータは関数シグネチャ互換性のため保持（BigQueryでは外部キー未対応）
		return false;
	}
	function indexes($table, $connection2 = null) {
		// $table, $connection2 パラメータは関数シグネチャ互換性のため保持（BigQueryではインデックス未対応）
		return array();
	}
	function foreign_keys($table) {
		// $table パラメータは関数シグネチャ互換性のため保持（BigQueryでは外部キー未対応）
		return array();
	}
	function logged_user() {
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
	function get_databases($flush = false) {
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
	function tables_list($database = '') {
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
	function table_status($name = '', $fast = false) {
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
	function fields($table) {
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
	function select($table, array $select, array $where, array $group, array $order = array(), $limit = 1, $page = 0, $print = false) {
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
					$processedCondition = BigQueryUtils::convertAdminerWhereToBigQuery($condition);
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
		function convert_field(array $field) {
			// BigQuery SELECT * との併用問題を回避するため、フィールド変換を無効化
			// Adminerが SELECT * を使用する際に不正なSQL生成を防ぐ
			// $field パラメータは関数シグネチャ互換性のため保持（未使用）
			return null;
		}
	}
	if (!function_exists('unconvert_field')) {
		function unconvert_field(array $field, $value) {

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
		function error() {
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
		function found_rows($table_status, $where) {
			if (!empty($where)) {
				return null;
			}
			if (isset($table_status['Rows']) && is_numeric($table_status['Rows'])) {
				return (int) $table_status['Rows'];
			}
			return null;
		}

		function insert($table, $set) {
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

		function update($table, $set, $queryWhere = '', $limit = 0) {
			// $limit パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
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

				$setParts = [];
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
						error_log("BigQuery UPDATE failed: $errorMessage");
						$connection->error = "UPDATE failed: $errorMessage";
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
				$connection->error = "UPDATE ServiceException: $errorMessage";
				return false;
			} catch (Exception $e) {
				$errorMessage = $e->getMessage();
				BigQueryUtils::logQuerySafely($errorMessage, 'UPDATE_ERROR');
				$connection->error = "UPDATE Exception: $errorMessage";
				return false;
			}
		}

		function delete($table, $queryWhere = '', $limit = 0) {
			// $limit パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
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

		function last_id() {
			global $connection;

			if ($connection && isset($connection->last_result)) {
				if ($connection->last_result instanceof Result && isset($connection->last_result->job)) {
					return $connection->last_result->job->id();
				}
			}

			return null;
		}

		function create_database($database, $collation) {

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

		function drop_databases($databases) {

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

		function rename_database($old_name, $new_name) {

			global $connection;

			if (!$connection || !isset($connection->bigQueryClient)) {
				return false;
			}

			try {
				// データセット名の検証
				if (
					!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $old_name) ||
					!preg_match('/^[a-zA-Z0-9_]{1,1024}$/', $new_name)
				) {
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

		function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
			// $foreign, $engine, $collation, $auto_increment, $partitioning パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
			// $driver 変数は互換性のため宣言（未使用）
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

					$schemaFields = [];
					foreach ($fields as $field) {
						if (isset($field[1]) && is_array($field[1])) {

							$fieldName = trim(str_replace('`', '', $field[1][0] ?? ''));
							$fieldType = trim($field[1][1] ?? 'STRING');
							$fieldMode = ($field[1][3] ?? false) ? 'REQUIRED' : 'NULLABLE';

							if (!empty($fieldName)) {
								$schemaFields[] = [
									'name' => $fieldName,
									'type' => strtoupper($fieldType),
									'mode' => $fieldMode
								];
							}
						}
					}

					if (empty($schemaFields)) {
						return false;
					}

					$tableOptions = [
						'schema' => ['fields' => $schemaFields]
					];

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

		function copy_tables($tables, $target_db, $overwrite) {
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

		function move_tables($tables, $views, $target) {
			// $views パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
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

		function auto_increment($table = null) {
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

	function show_unsupported_feature_message($feature, $reason = '') {

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

	function query($query) {
		global $connection;
		if ($connection && method_exists($connection, 'query')) {
			return $connection->query($query);
		}
		return false;
	}

	function schema() {
		show_unsupported_feature_message('schema', 'BigQuery uses datasets instead of traditional schemas. Dataset information is available in the main database view.');
		return;
	}

	function bigquery_view($name) {

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

	function import_sql($file) {

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


	function parseBigQueryStatements($sqlContent) {
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

	function isCommentOnly($statement) {
		$trimmed = trim($statement);
		return empty($trimmed) ||
			strpos($trimmed, '--') === 0 ||
			(strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/') !== false);
	}

	function truncate_table($table) {
		global $driver;

		if (!$driver) {
			return false;
		}

		// 共通メソッドを使用してTRUNCATE文を実行
		$sql = "TRUNCATE TABLE {table}";
		return $driver->executeSql($sql, "TRUNCATE", $table) !== false;
	}

	function check_table($table) {
		// $table パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
		show_unsupported_feature_message('check');
		return false;
	}

	function optimize_table($table) {
		// $table パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
		show_unsupported_feature_message('optimize');
		return false;
	}

	function repair_table($table) {
		// $table パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
		show_unsupported_feature_message('repair');
		return false;
	}

	function analyze_table($table) {
		// $table パラメータは関数シグネチャ互換性のため保持（BigQueryでは未使用）
		show_unsupported_feature_message('analyze');
		return false;
	}
}
