<?php

/**
 * BigQuery設定とタイプマッピングクラス
 *
 * BigQueryドライバーで使用される設定定数とユーティリティメソッドを提供
 */
class BigQueryConfig
{

	public const TYPE_MAPPING = array(
		'STRING' => array('type' => 'varchar', 'length' => null),
		'BYTES' => array('type' => 'varchar', 'length' => null),
		'INT64' => array('type' => 'bigint', 'length' => null),
		'INTEGER' => array('type' => 'bigint', 'length' => null),
		'FLOAT64' => array('type' => 'double', 'length' => null),
		'FLOAT' => array('type' => 'double', 'length' => null),
		'NUMERIC' => array('type' => 'decimal', 'length' => null),
		'BIGNUMERIC' => array('type' => 'decimal', 'length' => null),
		'BOOLEAN' => array('type' => 'tinyint', 'length' => 1),
		'BOOL' => array('type' => 'tinyint', 'length' => 1),
		'DATE' => array('type' => 'date', 'length' => null),
		'TIME' => array('type' => 'time', 'length' => null),
		'DATETIME' => array('type' => 'datetime', 'length' => null),
		'TIMESTAMP' => array('type' => 'timestamp', 'length' => null),
		'GEOGRAPHY' => array('type' => 'geometry', 'length' => null),
		'JSON' => array('type' => 'json', 'length' => null),
		'ARRAY' => array('type' => 'text', 'length' => null),
		'STRUCT' => array('type' => 'text', 'length' => null),
		'RECORD' => array('type' => 'text', 'length' => null),
	);
	public const DANGEROUS_SQL_PATTERNS = array(
		'ddl_dml' => '/;\\s*(?:DROP|ALTER|CREATE|DELETE|INSERT|UPDATE|TRUNCATE)\\s+/i',
		'union_injection' => '/UNION\\s+(?:ALL\\s+)?SELECT/i',
		'block_comments' => '/\\/\\*.*?\\*\\//s',
		'line_comments' => '/--[^\\r\\n]*/i',
		'execute_commands' => '/\\b(?:EXEC|EXECUTE|SP_)\\b/i',
	);
	public const SUPPORTED_FEATURES = array(
		'database' => true,
		'table' => true,
		'columns' => true,
		'sql' => true,
		'view' => true,
		'materializedview' => true,
	);
	public const UNSUPPORTED_FEATURES = array(
		'foreignkeys' => false,
		'indexes' => false,
		'processlist' => false,
		'kill' => false,
		'transaction' => false,
		'comment' => false,
		'drop_col' => false,
		'dump' => false,
		'event' => false,
		'move_col' => false,
		'privileges' => false,
		'procedure' => false,
		'routine' => false,
		'sequence' => false,
		'status' => false,
		'trigger' => false,
		'type' => false,
		'variables' => false,
		'descidx' => false,
		'check' => false,
		'schema' => false,
	);
	public const CACHE_CONFIG = array(
		'credentials_ttl' => 86400,
		'location_ttl' => 86400,
		'databases_ttl' => 600,
		'tables_ttl' => 600,
		'fields_ttl' => 300,
		'apcu_shm_size' => '64M',
		'connection_pool_max' => 3,
	);
	static function mapType($bigQueryType)
	{
		$baseType = strtoupper(preg_replace('/\\(.*\\)/', '', $bigQueryType));
		return self::TYPE_MAPPING[$baseType] ?? array('type' => 'text', 'length' => null);
	}
	static function isDangerousQuery($query)
	{
		foreach (self::DANGEROUS_SQL_PATTERNS as $pattern) {
			if (preg_match($pattern, $query)) {
				return true;
			}
		}
		return false;
	}
	static function isFeatureSupported($feature)
	{
		return self::SUPPORTED_FEATURES[$feature] ??
			(self::UNSUPPORTED_FEATURES[$feature] ?? false);
	}
}