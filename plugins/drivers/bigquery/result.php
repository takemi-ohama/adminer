<?php

namespace Adminer;

use Exception;
use DateTime;
use DateTimeInterface;

/**
 * Result - BigQuery query result handler
 *
 * Separated from bigquery.php for better code organization
 */
class Result
{

	private $queryResults;
	private $rowNumber = 0;
	private $fieldsCache = null;
	private $iterator = null;
	private $isIteratorInitialized = false;
	public $num_rows = 0;
	public $job = null; // Phase 1: last_id()機能のためのジョブ参照

	function __construct($queryResults)
	{
		$this->queryResults = $queryResults;
		$this->job = $queryResults; // BigQueryジョブへの参照を保存

		try {
			$jobInfo = $queryResults->info();
			$this->num_rows = (int) ($jobInfo['totalRows'] ?? 0);
		} catch (Exception $e) {

			$this->num_rows = 0;
		}
	}
	function fetch_assoc()
	{
		try {
			if (!$this->isIteratorInitialized) {
				$this->iterator = $this->queryResults->getIterator();
				$this->isIteratorInitialized = true;
			}
			if ($this->iterator && $this->iterator->valid()) {
				$row = $this->iterator->current();
				$this->iterator->next();
				$processedRow = array();
				foreach ($row as $key => $value) {
					if (is_array($value)) {
						$processedRow[$key] = json_encode($value);
					} elseif (is_object($value)) {
						if ($value instanceof DateTime) {
							$processedRow[$key] = $value->format('Y-m-d H:i:s');
						} elseif ($value instanceof DateTimeInterface) {
							$processedRow[$key] = $value->format('Y-m-d H:i:s');
						} elseif (method_exists($value, 'format')) {
							try {
								$processedRow[$key] = $value->format('Y-m-d H:i:s');
							} catch (Exception $e) {
								$processedRow[$key] = (string) $value;
							}
						} elseif (method_exists($value, '__toString')) {
							$processedRow[$key] = (string) $value;
						} else {
							$processedRow[$key] = json_encode($value);
						}
					} elseif (is_null($value)) {
						$processedRow[$key] = null;
					} else {
						$processedRow[$key] = $value;
					}
				}
				$this->rowNumber++;
				return $processedRow;
			}
			return false;
		} catch (Exception $e) {
			error_log("Result fetch error: " . $e->getMessage());
			return false;
		}
	}
	function fetch_row()
	{
		$assoc = $this->fetch_assoc();
		return $assoc ? array_values($assoc) : false;
	}
	function num_fields()
	{
		if ($this->fieldsCache === null) {
			$this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? array();
		}
		return count($this->fieldsCache);
	}
	function fetch_field($offset = 0)
	{
		if ($this->fieldsCache === null) {
			$this->fieldsCache = $this->queryResults->info()['schema']['fields'] ?? array();
		}
		if (!isset($this->fieldsCache[$offset])) {
			return false;
		}
		$field = $this->fieldsCache[$offset];
		return (object) array(
			'name' => $field['name'],
			'type' => $this->mapBigQueryType($field['type']),
			'length' => null,
			'flags' => ($field['mode'] ?? 'NULLABLE') === 'REQUIRED' ? 'NOT NULL' : '',
			'charsetnr' => $this->getBigQueryCharsetNr($field['type']),
			'orgname' => $field['name'],
			'orgtable' => ''
		);
	}
	private function mapBigQueryType($bigQueryType)
	{
		$typeMap = array(
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
		);
		return $typeMap[strtoupper($bigQueryType)] ?? 'text';
	}

	private function getBigQueryCharsetNr($bigQueryType)
	{
		$baseType = strtoupper(preg_replace('/\([^)]*\)/', '', $bigQueryType));

		switch ($baseType) {
			case 'BYTES':

				return 63;
			case 'STRING':
			case 'JSON':

				return 33;
			case 'INT64':
			case 'INTEGER':
			case 'FLOAT64':
			case 'FLOAT':
			case 'NUMERIC':
			case 'BIGNUMERIC':
			case 'BOOLEAN':
			case 'BOOL':
			case 'DATE':
			case 'TIME':
			case 'DATETIME':
			case 'TIMESTAMP':

				return 63;
			case 'ARRAY':
			case 'STRUCT':
			case 'RECORD':
			case 'GEOGRAPHY':

				return 33;
			default:

				return 33;
		}
	}
}
