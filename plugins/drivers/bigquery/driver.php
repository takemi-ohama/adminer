<?php

namespace Adminer;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\Core\Exception\ServiceException;
use Exception;
use InvalidArgumentException;

/**
 * Driver - BigQuery driver class
 *
 * Separated from bigquery.php for better code organization
 */
class Driver {

	static $instance;
	static $extensions = array("BigQuery");
	static $jush = "sql";
	static $operators = array(
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

	/** @var array BigQuery table partitioning configuration */
	public $partitionBy = array();

	/** @var array Unsigned numeric type definitions */
	public $unsigned = array();

	/** @var array Generated column definitions */
	public $generated = array();

	/** @var array Enum field length restrictions */
	public $enumLength = array();

	/** @var array Functions available for INSERT operations */
	public $insertFunctions = array();

	/** @var array Functions available for field editing operations */
	public $editFunctions = array();

	/** @var array Database functions available for use in queries */
	public $functions = array();

	/** @var array Field grouping configuration for query operations */
	public $grouping = array();

	protected $types = array(
		array("INT64" => 0, "INTEGER" => 0, "FLOAT64" => 0, "FLOAT" => 0, "NUMERIC" => 0, "BIGNUMERIC" => 0),
		array("STRING" => 0, "BYTES" => 0),
		array("DATE" => 0, "TIME" => 0, "DATETIME" => 0, "TIMESTAMP" => 0),
		array("BOOLEAN" => 0, "BOOL" => 0),
		array("ARRAY" => 0, "STRUCT" => 0, "JSON" => 0, "GEOGRAPHY" => 0)
	);

	static function connect($server, $username, $password) {
		$db = new Db();
		if ($db->connect($server, $username, $password)) {
			return $db;
		}
		return false;
	}
	function tableHelp($name, $is_view = false) {
		return null;
	}
	function structuredTypes() {
		$allTypes = array();
		foreach ($this->types as $typeGroup) {
			$allTypes = array_merge($allTypes, array_keys($typeGroup));
		}
		return $allTypes;
	}
	function inheritsFrom($table) {
		return array();
	}
	function inheritedTables($table) {
		return array();
	}
	function select($table, $select, $where, $group, $order = array(), $limit = 1, $page = 0, $print = false) {
		return select($table, $select, $where, $group, $order, $limit, $page, $print);
	}
	function value($val, $field) {
		return BigQueryUtils::formatComplexValue($val, $field);
	}
	function convert_field(array $field) {
		// BigQuery SELECT * との併用問題を回避するため、フィールド変換を無効化
		// Adminerが SELECT * を使用する際に不正なSQL生成を防ぐ
		return null;
	}

	function hasCStyleEscapes(): bool {
		return false;
	}

	function warnings() {

		return array();
	}

	function engines() {
		return array('BigQuery');
	}

	function types() {
		return array(
			'Numbers' => array(
				'INT64' => 0,
				'INTEGER' => 0,
				'FLOAT64' => 0,
				'FLOAT' => 0,
				'NUMERIC' => 0,
				'BIGNUMERIC' => 0
			),
			'Strings' => array(
				'STRING' => 0,
				'BYTES' => 0
			),
			'Date and time' => array(
				'DATE' => 0,
				'TIME' => 0,
				'DATETIME' => 0,
				'TIMESTAMP' => 0
			),
			'Boolean' => array(
				'BOOLEAN' => 0,
				'BOOL' => 0
			),
			'Complex' => array(
				'ARRAY' => 0,
				'STRUCT' => 0,
				'JSON' => 0,
				'GEOGRAPHY' => 0
			)
		);
	}

	function enumLength($field) {

		return array();
	}

	function unconvertFunction($field) {

		return null;
	}

	function insert($table, $set) {

		return insert($table, $set);
	}

	function update($table, $set, $queryWhere = '', $limit = 0) {

		return update($table, $set, $queryWhere, $limit);
	}

	function delete($table, $queryWhere = '', $limit = 0) {

		return delete($table, $queryWhere, $limit);
	}

	function allFields(): array {
		$return = array();
		try {
			foreach (tables_list() as $table => $type) {
				$tableFields = fields($table);
				foreach ($tableFields as $field) {
					$return[$table][] = $field;
				}
			}
			return $return;
		} catch (Exception $e) {
			error_log("BigQuery allFields error: " . $e->getMessage());
			return array();
		}
	}

	function convertSearch(string $idf, array $val, array $field): string {

		return $idf;
	}

	function dropTables($tables) {
		// 共通メソッドを使用してテーブル削除を実行
		return $this->executeForTables("DROP TABLE {table}", $tables, "DROP_TABLE");
	}

	/**
	 * 共通SQL実行メソッド - connectionとtableのチェック、エラーハンドリングを統一
	 * @param string $sql SQL文
	 * @param string $logOperation ログ用操作名
	 * @param string|null $table テーブル名（フルネーム構築用、オプション）
	 * @param string|null $database データベース名（オプション、$_GET['db']より優先）
	 * @return mixed クエリ実行結果
	 */
	public function executeSql($sql, $logOperation, $table = null, $database = null) {
		global $connection;

		// 基本接続チェック
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		try {
			// データベース名取得（引数→接続設定→$_GET['db']の順で優先）
			if ($database === null) {
				$database = $_GET['db'] ?? ($connection && isset($connection->datasetId) ? $connection->datasetId : '');
			}

			// データベース名が空の場合はエラーを返す
			if (empty($database)) {
				if ($connection) {
					$connection->error = "$logOperation failed: No database specified";
				}
				return false;
			}

			// テーブル名が指定されている場合、フルテーブル名を構築
			if ($table !== null && !empty($database)) {
				$projectId = $connection && isset($connection->projectId) ? $connection->projectId : 'default';
				$fullTableName = BigQueryUtils::buildFullTableName($table, $database, $projectId);
				$sql = str_replace('{table}', $fullTableName, $sql);
			}

			// SQL実行ログ
			BigQueryUtils::logQuerySafely($sql, $logOperation);

			// クエリ実行
			return $connection->query($sql);
		} catch (Exception $e) {
			if ($connection) {
				$connection->error = "$logOperation failed: " . $e->getMessage();
			}
			BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
			return false;
		}
	}

	/**
	 * 複数テーブルに対する同一SQL実行（MySQLのapply_queriesパターン）
	 * @param string $sqlTemplate SQL文テンプレート（{table}をプレースホルダーとして使用）
	 * @param array $tables テーブル名の配列
	 * @param string $logOperation ログ用操作名
	 * @param string|null $database データベース名（オプション）
	 * @return bool 全て成功した場合true、1つでも失敗した場合false
	 */
	public function executeForTables($sqlTemplate, $tables, $logOperation, $database = null) {
		global $connection;

		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		$errors = array();
		$successCount = 0;

		foreach ($tables as $table) {
			if (empty($table)) {
				continue;
			}

			$result = $this->executeSql($sqlTemplate, $logOperation, $table, $database);
			if ($result !== false) {
				$successCount++;
			} else {
				$errors[] = "$logOperation failed for table: $table";
			}
		}

		// エラーハンドリング
		if (!empty($errors) && $connection) {
			$connection->error = implode('; ', $errors);
		}

		return $successCount > 0;
	}

	/**
	 * 複数データベース（データセット）に対する操作実行
	 * @param array $databases データベース名の配列
	 * @param string $logOperation ログ用操作名
	 * @param callable $callback 各データベースに対する処理関数
	 * @return bool 1つでも成功した場合true
	 */
	public function executeForDatabases($databases, $logOperation, $callback) {
		global $connection;

		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		$errors = array();
		$successCount = 0;

		try {
			foreach ($databases as $database) {
				if (empty($database)) {
					continue;
				}

				try {
					// コールバック関数を実行
					$result = $callback($database, $connection);
					if ($result) {
						$successCount++;
						BigQueryUtils::logQuerySafely("$logOperation $database", $logOperation);
					} else {
						$errors[] = "$logOperation failed for database: $database";
					}
				} catch (Exception $e) {
					$errors[] = "$logOperation '$database' failed: " . $e->getMessage();
					BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
				}
			}

			// エラーハンドリング
			if (!empty($errors) && $connection) {
				$connection->error = implode('; ', $errors);
			}

			return $successCount > 0;
		} catch (Exception $e) {
			if ($connection) {
				$connection->error = "$logOperation failed: " . $e->getMessage();
			}
			BigQueryUtils::logQuerySafely($e->getMessage(), $logOperation . '_ERROR');
			return false;
		}
	}

	function explain($query) {
		global $connection;
		if (!$connection || !isset($connection->bigQueryClient)) {
			return false;
		}

		try {

			$explainQuery = "EXPLAIN " . $query;
			BigQueryUtils::logQuerySafely($explainQuery, "EXPLAIN");
			$result = $connection->query($explainQuery);
			return $result;
		} catch (Exception $e) {
			BigQueryUtils::logQuerySafely($e->getMessage(), 'EXPLAIN_ERROR');
			return false;
		}
	}

	function css() {
		return "
		<style>
		/* BigQuery非対応機能を非表示 - より強い優先度で適用 */

		/* Database画面のSearch data in tables機能を非表示 */
		.search-tables {
			display: none !important;
			visibility: hidden !important;
		}

		/* Analyze機能を非表示 */
		.analyze,
		input[value='Analyze'],
		input[type='submit'][value='Analyze'],
		a[href*='analyze'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Optimize機能を非表示 */
		.optimize,
		input[value='Optimize'],
		input[type='submit'][value='Optimize'],
		a[href*='optimize'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Repair機能を非表示 */
		.repair,
		input[value='Repair'],
		input[type='submit'][value='Repair'],
		a[href*='repair'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Check機能を非表示 */
		.check,
		input[value='Check'],
		input[type='submit'][value='Check'],
		a[href*='check'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Move機能を非表示 */
		.move,
		input[value='Move'],
		input[type='submit'][value='Move'],
		a[href*='move'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Copy機能を非表示 */
		.copy,
		input[value='Copy'],
		input[type='submit'][value='Copy'],
		a[href*='copy'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Import機能を非表示 */
		.import,
		input[value='Import'],
		input[type='submit'][value='Import'],
		a[href*='import'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Export機能（一部）を非表示 */
		select[name='format'] option[value='csv+excel'],
		select[name='format'] option[value='xml'] {
			display: none !important;
		}

		/* Index関連機能を非表示 */
		.indexes,
		a[href*='indexes'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Foreign key関連機能を非表示 */
		.foreign-keys,
		a[href*='foreign'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Trigger関連機能を非表示 */
		.triggers,
		a[href*='trigger'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Event関連機能を非表示 */
		.events,
		a[href*='event'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Routine関連機能を非表示 */
		.routines,
		a[href*='routine'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Sequence関連機能を非表示 */
		.sequences,
		a[href*='sequence'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* User types関連機能を非表示 */
		.user-types,
		a[href*='type'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Auto increment機能を非表示 */
		input[name*='auto_increment'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Comment機能を非表示（テーブルレベル） */
		input[name='Comment'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Collation機能を非表示 */
		select[name*='collation'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* FullText検索機能を非表示 */
		input[type='submit'][value*='Fulltext'] {
			display: none !important;
			visibility: hidden !important;
		}

		/* Truncate/Dropボタンの明示的な表示 */
		input[value='Truncate'],
		input[type='submit'][value='Truncate'],
		input[name='truncate'] {
			display: inline-block !important;
			visibility: visible !important;
		}

		input[value='Drop'],
		input[type='submit'][value='Drop'],
		input[name='drop'] {
			display: inline-block !important;
			visibility: visible !important;
		}

		/* BigQuery対応機能のラベル改善 */
		body.bigquery .h2 {
			position: relative;
		}

		body.bigquery .h2:after {
			content: ' (BigQuery)';
			font-size: 0.8em;
			color: #666;
		}
		</style>
		<script>
		// *** BigQuery強制表示機能 - 複数タイミングで実行 ***
		function forceBigQueryButtonsDisplay() {
			console.log('BigQuery強制表示実行開始');

			// BigQueryドライバー使用時にbody要素にクラス追加
			if (document.querySelector('title') && document.querySelector('title').textContent.includes('BigQuery')) {
				document.body.classList.add('bigquery');
			}

			// 非対応ボタンを非表示（TruncateとDropは除外）
			var buttonsToHide = [
				'input[value=\"Analyze\"]',
				'input[value=\"Optimize\"]',
				'input[value=\"Repair\"]',
				'input[value=\"Check\"]',
				'input[value=\"Move\"]',
				'input[value=\"Copy\"]',
				'input[value=\"Import\"]'
			];

			buttonsToHide.forEach(function(selector) {
				var elements = document.querySelectorAll(selector);
				elements.forEach(function(element) {
					element.style.display = 'none';
					element.style.visibility = 'hidden';
				});
			});

			// *** 重要：Selected フィールドセットの強制表示 ***
			var selectedFieldsets = document.querySelectorAll('fieldset');
			selectedFieldsets.forEach(function(fieldset) {
				var legend = fieldset.querySelector('legend');
				if (legend && legend.textContent.includes('Selected')) {
					console.log('Selected fieldset found, forcing display');
					fieldset.style.setProperty('display', 'block', 'important');
					fieldset.style.setProperty('visibility', 'visible', 'important');
					fieldset.style.setProperty('opacity', '1', 'important');

					// fieldset内のdivも強制表示
					var divs = fieldset.querySelectorAll('div');
					divs.forEach(function(div) {
						div.style.setProperty('display', 'block', 'important');
						div.style.setProperty('visibility', 'visible', 'important');
						div.style.setProperty('opacity', '1', 'important');
					});
				}
			});

			// Truncate/Dropボタンの最強レベルでの強制表示
			var buttonsToShow = [
				'input[name=\"truncate\"]',
				'input[name=\"drop\"]'
			];

			buttonsToShow.forEach(function(selector) {
				var elements = document.querySelectorAll(selector);
				console.log('Found buttons for', selector, ':', elements.length);
				elements.forEach(function(element) {
					// ボタン自体を最強レベルで表示
					element.style.setProperty('display', 'inline-block', 'important');
					element.style.setProperty('visibility', 'visible', 'important');
					element.style.setProperty('opacity', '1', 'important');

					// 親要素チェーンも最強レベルで表示
					var parent = element.parentElement;
					while (parent && parent.tagName !== 'BODY') {
						if (parent.tagName === 'FIELDSET' || parent.tagName === 'DIV') {
							parent.style.setProperty('display', parent.tagName === 'FIELDSET' ? 'block' : 'block', 'important');
							parent.style.setProperty('visibility', 'visible', 'important');
							parent.style.setProperty('opacity', '1', 'important');
						}
						parent = parent.parentElement;
					}
				});
			});

			console.log('BigQuery強制表示実行完了');
		}

		// 複数のタイミングで確実に実行
		// 1. DOMContentLoaded（通常のタイミング）
		document.addEventListener('DOMContentLoaded', forceBigQueryButtonsDisplay);

		// 2. window.load（全リソース読み込み完了後）
		window.addEventListener('load', forceBigQueryButtonsDisplay);

		// 3. 即座実行（既にDOMが読み込まれている場合）
		if (document.readyState === 'loading') {
			// まだ読み込み中
		} else {
			// 既に読み込み完了
			forceBigQueryButtonsDisplay();
		}

		// 4. 遅延実行（最後の保険）
		setTimeout(forceBigQueryButtonsDisplay, 500);
		setTimeout(forceBigQueryButtonsDisplay, 1000);
		</script>
		";
	}
}
