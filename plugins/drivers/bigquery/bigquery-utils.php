<?php

namespace Adminer;

/**
 * BigQueryUtils - BigQuery用のユーティリティクラス
 *
 * bigquery.phpから分離されたユーティリティクラス
 */
class BigQueryUtils
{

	static function validateProjectId($projectId)
	{
		return preg_match('/^[a-z0-9][a-z0-9\\-]{4,28}[a-z0-9]$/i', $projectId) &&
			strlen($projectId) <= 30;
	}
	static function escapeIdentifier($identifier)
	{

		if (preg_match('/^`[^`]*`$/', $identifier)) {
			return $identifier;
		}

		$cleanIdentifier = trim($identifier, '`');
		return "`" . str_replace("`", "``", $cleanIdentifier) . "`";
	}
	static function logQuerySafely($query, $context = "QUERY")
	{
		$sanitizers = array(
			'/([\'"])[^\'\"]*\\1/' => '$1***REDACTED***$1',
			'/\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Z|a-z]{2,}\\b/' => '***EMAIL_REDACTED***'
		);
		$safeQuery = preg_replace(array_keys($sanitizers), array_values($sanitizers), $query);
		if (strlen($safeQuery) > 200) {
			$safeQuery = substr($safeQuery, 0, 200) . '... [TRUNCATED]';
		}
		error_log("BigQuery $context: $safeQuery");
	}

	static function convertValueForBigQuery($value, $fieldType)
	{

		if ($value === null) {
			return 'NULL';
		}

		$cleanValue = trim(str_replace('`', '', $value));
		$fieldType = strtolower($fieldType);

		if (strpos($fieldType, 'timestamp') !== false) {
			return "TIMESTAMP('" . str_replace("'", "''", $cleanValue) . "')";
		} elseif (strpos($fieldType, 'datetime') !== false) {
			return "DATETIME('" . str_replace("'", "''", $cleanValue) . "')";
		} elseif (strpos($fieldType, 'date') !== false) {
			return "DATE('" . str_replace("'", "''", $cleanValue) . "')";
		} elseif (strpos($fieldType, 'time') !== false) {
			return "TIME('" . str_replace("'", "''", $cleanValue) . "')";
		} elseif (strpos($fieldType, 'int') !== false || strpos($fieldType, 'float') !== false || strpos($fieldType, 'numeric') !== false || strpos($fieldType, 'decimal') !== false) {

			if (is_numeric($cleanValue)) {
				return $cleanValue;
			} else {
				throw new InvalidArgumentException('Invalid numeric value: ' . $cleanValue);
			}
		} elseif (strpos($fieldType, 'bool') !== false) {
			return (strtolower($cleanValue) === 'true' || $cleanValue === '1') ? 'TRUE' : 'FALSE';
		} else {
			return "'" . str_replace("'", "''", $cleanValue) . "'";
		}
	}

	static function formatComplexValue($value, $field)
	{
		$fieldType = strtolower($field['type'] ?? 'text');
		$typePatterns = array(
			'json' => array('json', 'struct', 'record', 'array'),
			'geography' => array('geography'),
			'binary' => array('bytes', 'blob'),
		);
		foreach ($typePatterns as $handlerType => $patterns) {
			if (self::matchesTypePattern($fieldType, $patterns)) {
				return self::handleTypeConversion($value, $handlerType);
			}
		}
		return $value;
	}
	private static function matchesTypePattern($fieldType, $patterns)
	{
		foreach ($patterns as $pattern) {
			if (strpos($fieldType, $pattern) !== false) {
				return true;
			}
		}
		return false;
	}
	private static function handleTypeConversion($value, $handlerType)
	{
		switch ($handlerType) {
			case 'json':
				return is_string($value) && (substr($value, 0, 1) === '{' || substr($value, 0, 1) === '[')
					? $value : json_encode($value);
			case 'geography':
			case 'binary':
				return is_string($value) ? $value : (string) $value;
			default:
				return $value;
		}
	}
	static function generateFieldConversion($field)
	{

		$fieldName = self::escapeIdentifier($field['field']);
		$fieldType = strtolower($field['type'] ?? '');

		// BigQuery固有データ型の変換マッピング
		$conversions = array(
			// 地理空間データの変換
			'geography' => "ST_AsText($fieldName)",
			'geom' => "ST_AsText($fieldName)",

			// JSON・構造化データの変換
			'json' => "TO_JSON_STRING($fieldName)",
			'struct' => "TO_JSON_STRING($fieldName)",
			'record' => "TO_JSON_STRING($fieldName)",
			'array' => "TO_JSON_STRING($fieldName)",

			// 日時データの変換
			'timestamp' => "TIMESTAMP_TRUNC($fieldName, MICROSECOND)",
			'datetime' => "DATETIME_TRUNC($fieldName, MICROSECOND)",
			'time' => "TIME_TRUNC($fieldName, MICROSECOND)",

			// バイナリデータの変換
			'bytes' => "TO_BASE64($fieldName)",
			'blob' => "TO_BASE64($fieldName)",

			// 数値データの精度制御
			'numeric' => "CAST($fieldName AS STRING)",
			'bignumeric' => "CAST($fieldName AS STRING)",
			'decimal' => "CAST($fieldName AS STRING)",

			// 論理データの明示化
			'boolean' => "IF($fieldName, 'true', 'false')",
			'bool' => "IF($fieldName, 'true', 'false')"
		);

		// パターンマッチングで最適な変換を選択
		foreach ($conversions as $typePattern => $conversion) {
			if (strpos($fieldType, $typePattern) !== false) {
				return $conversion;
			}
		}

		// デフォルト: 変換不要
		return null;
	}

	static function buildFullTableName($table, $database, $projectId)
	{
		return "`" . $projectId . "`.`" . $database . "`.`" . $table . "`";
	}

	/**
	 * BigQueryジョブの完了状態を包括的に確認する共通関数
	 *
	 * @param object $job BigQueryジョブオブジェクト
	 * @return bool ジョブが完了している場合はtrue
	 */
	static function isJobCompleted($job)
	{
		if (!$job) {
			return false;
		}

		$jobInfo = $job->info();
		$isJobComplete = false;

		// 方法1: job->isComplete()メソッドによる確認
		if ($job->isComplete()) {
			$isJobComplete = true;
		}

		// 方法2: status.state フィールドによる確認
		if (isset($jobInfo['status']['state']) && $jobInfo['status']['state'] === 'DONE') {
			$isJobComplete = true;
		}

		// 方法3: statistics の存在による確認
		if (isset($jobInfo['statistics'])) {
			$isJobComplete = true;
		}

		return $isJobComplete;
	}

	/**
	 * Process WHERE clause for BigQuery DML operations
	 *
	 * @param string $queryWhere The WHERE condition from Adminer
	 * @return string Properly formatted WHERE clause with WHERE prefix
	 * @throws InvalidArgumentException If WHERE condition is invalid
	 */
	static function processWhereClause($queryWhere)
	{
		if (empty($queryWhere) || trim($queryWhere) === '') {
			return '';
		}

		$convertedWhere = convertAdminerWhereToBigQuery($queryWhere);

		// Check if the converted WHERE already starts with WHERE keyword
		if (preg_match('/^\s*WHERE\s/i', $convertedWhere)) {
			return ' ' . $convertedWhere;
		} else {
			return ' WHERE ' . $convertedWhere;
		}
	}
}
