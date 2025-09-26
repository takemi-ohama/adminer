# BigQuery Driver 未実装機能完全実装計画

## プロジェクト概要
adminer/drivers/mysql.inc.phpの解析に基づき、BigQueryドライバーの未実装機能を特定し、段階的実装計画を策定する。

**作成日**: 2025-09-26
**対象**: BigQuery Driver 未実装機能の完全実装

## 1. 機能分析結果

### 1.1 MySQLドライバー機能一覧（全59機能）

#### 1.1.1 基本接続・データベース操作
- `idf_escape` - 識別子エスケープ
- `get_databases` - データベース一覧取得
- `limit` - クエリ結果制限
- `limit1` - 単一行制限
- `db_collation` - データベース照合順序
- `logged_user` - ログインユーザー情報

#### 1.1.2 テーブル・構造操作
- `tables_list` - テーブル一覧
- `count_tables` - テーブル数カウント
- `table_status` - テーブル状態情報
- `is_view` - ビュー判定
- `fields` - フィールド情報
- `indexes` - インデックス情報
- `foreign_keys` - 外部キー情報
- `view` - ビュー定義取得
- `collations` - 照合順序一覧

#### 1.1.3 データ操作・管理
- `auto_increment` - 自動増分値
- `alter_table` - テーブル変更
- `alter_indexes` - インデックス変更
- `truncate_tables` - テーブル削除（データのみ）
- `drop_views` - ビュー削除
- `drop_tables` - テーブル削除
- `move_tables` - テーブル移動
- `copy_tables` - テーブルコピー

#### 1.1.4 高度な機能
- `trigger` - トリガー定義
- `triggers` - トリガー一覧
- `trigger_options` - トリガーオプション
- `routine` - ストアドプロシージャ/関数
- `routines` - プロシージャ一覧
- `routine_languages` - プロシージャ言語
- `routine_id` - プロシージャID

#### 1.1.5 システム・管理機能
- `explain` - 実行計画
- `found_rows` - 検索結果行数
- `create_sql` - CREATE文生成
- `truncate_sql` - TRUNCATE文生成
- `use_sql` - USE文生成
- `trigger_sql` - トリガーSQL生成
- `show_variables` - システム変数表示
- `show_status` - システム状態表示
- `process_list` - プロセス一覧
- `kill_process` - プロセス終了
- `connection_id` - 接続ID
- `max_connections` - 最大接続数

#### 1.1.6 データ型・スキーマ
- `convert_field` - フィールド変換
- `unconvert_field` - フィールド逆変換
- `types` - データ型一覧
- `type_values` - 型値一覧
- `schemas` - スキーマ一覧
- `get_schema` - スキーマ取得
- `set_schema` - スキーマ設定

### 1.2 BigQueryドライバー実装済み機能（20機能）

#### 1.2.1 基本機能（実装済み）
- `database` - データセット操作
- `table` - テーブル操作
- `columns` - カラム情報
- `sql` - SQLクエリ実行
- `view` - ビュー表示
- `materializedview` - マテリアライズドビュー

#### 1.2.2 データ操作（実装済み）
- `create_db` - データセット作成
- `create_table` - テーブル作成
- `insert` - データ挿入
- `update` - データ更新
- `delete` - データ削除
- `drop_table` - テーブル削除
- `truncate` - テーブル切り詰め
- `drop` - 削除操作
- `select` - データ選択
- `export` - データエクスポート

#### 1.2.3 サポート関数（実装済み）
- `get_databases`
- `tables_list`
- `table_status`
- `fields`

### 1.3 BigQueryで実装不可能な機能（18機能）

#### 1.3.1 BigQuery固有制約により不可能
- `foreignkeys` - 外部キー（BigQueryには存在しない）
- `indexes` - インデックス（BigQueryには存在しない）
- `transaction` - トランザクション（BigQueryは非対応）
- `processlist` - プロセス一覧（BigQueryジョブ管理は別体系）
- `kill` - プロセス終了（BigQueryジョブ管理は別体系）
- `privileges` - 権限管理（IAMで管理）
- `procedure` - ストアドプロシージャ（UDFは別実装）
- `routine` - ルーチン（UDFは別実装）
- `sequence` - シーケンス（BigQueryには存在しない）
- `trigger` - トリガー（BigQueryには存在しない）
- `event` - イベント（BigQueryには存在しない）
- `move_col` - カラム移動（ALTERで制限あり）
- `drop_col` - カラム削除（ALTERで制限あり）
- `descidx` - 降順インデックス（インデックス自体が存在しない）
- `check` - CHECK制約（BigQueryには存在しない）
- `analyze` - ANALYZE（BigQueryは自動最適化）
- `optimize` - OPTIMIZE（BigQueryは自動最適化）
- `repair` - REPAIR（BigQueryには存在しない）

## 2. 未実装機能リスト（21機能）

### 2.1 高優先度：必須実装機能（12機能）

#### 2.1.1 基本操作機能
1. **`limit`** - クエリ結果制限（LIMIT句処理）
2. **`limit1`** - 単一行制限（LIMIT 1処理）
3. **`explain`** - 実行計画（BigQuery dry run）
4. **`found_rows`** - 検索結果行数（カウント機能）
5. **`error`** - エラー処理強化
6. **`logged_user`** - ログインユーザー情報表示

#### 2.1.2 システム情報機能
7. **`information_schema`** - スキーマ情報システム
8. **`db_collation`** - データセット照合順序情報
9. **`collations`** - 照合順序一覧
10. **`convert_field`** - フィールド変換（強化）
11. **`unconvert_field`** - フィールド逆変換（強化）

#### 2.1.3 管理機能
12. **`last_id`** - 最後の挿入ID（BigQuery適応版）

### 2.2 中優先度：推奨実装機能（6機能）

#### 2.2.1 データベース操作
1. **`create_database`** - データセット作成（強化版）
2. **`drop_databases`** - データセット削除
3. **`rename_database`** - データセット名変更

#### 2.2.2 テーブル操作
4. **`alter_table`** - テーブル変更（BigQuery制限内）
5. **`copy_tables`** - テーブルコピー
6. **`move_tables`** - テーブル移動（データセット間）

### 2.3 低優先度：将来実装機能（3機能）

#### 2.3.1 高度な機能
1. **`view`** - ビュー定義管理（強化版）
2. **`import_sql`** - SQLインポート（強化版）
3. **`auto_increment`** - 自動増分管理（BigQuery適応版）

## 3. 段階的実装計画

### Phase 1: 基本機能完成（優先度1-4）- 3日間

#### Sprint 1.1: クエリ制限・結果処理（1日）
```php
// 実装対象
- limit($query, $limit)
- limit1($query)
- found_rows()
- last_id()

// 実装ポイント
- BigQuery LIMIT句の適切な処理
- 結果行数カウント機能
- ページング機能との統合
```

#### Sprint 1.2: 実行計画・エラー処理（1日）
```php
// 実装対象
- explain($query)
- error() - 強化版

// 実装ポイント
- BigQuery dry run API活用
-詳細なエラー分類・メッセージ改善
- デバッグ情報の充実
```

#### Sprint 1.3: ユーザー・システム情報（1日）
```php
// 実装対象
- logged_user()
- information_schema($database)

// 実装ポイント
- サービスアカウント情報表示
- BigQuery INFORMATION_SCHEMA活用
```

### Phase 2: システム情報機能（優先度5-8）- 2日間

#### Sprint 2.1: 照合・変換機能（1日）
```php
// 実装対象
- db_collation($database)
- collations()
- convert_field($field) - 強化
- unconvert_field($field) - 強化

// 実装ポイント
- BigQuery照合順序の適切な処理
- データ型変換の最適化
```

### Phase 3: データベース管理機能（優先度9-11）- 2日間

#### Sprint 3.1: データセット操作（1日）
```php
// 実装対象
- create_database($database, $collation)
- drop_databases($databases)
- rename_database($from, $to)

// 実装ポイント
- BigQuery Dataset API活用
- 権限チェック機能
- エラーハンドリング強化
```

#### Sprint 3.2: テーブル管理（1日）
```php
// 実装対象
- alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning)
- copy_tables($tables, $target_db, $overwrite)
- move_tables($tables, $target_db, $overwrite)

// 実装ポイント
- BigQuery DDL制限への対応
- テーブル間コピー機能
- データセット間移動対応
```

### Phase 4: 高度機能・最適化（優先度12-14）- 2日間

#### Sprint 4.1: ビュー・インポート機能（1日）
```php
// 実装対象
- view($name) - 強化版
- import_sql($database, $file) - 強化版

// 実装ポイント
- BigQuery view定義の詳細表示
- 大容量SQLファイル処理
```

#### Sprint 4.2: 最適化・ポリッシュ（1日）
```php
// 実装対象
- auto_increment() - BigQuery適応版
- 全機能の統合テスト
- パフォーマンス最適化

// 実装ポイント
- 代替自動増分機能実装
- 包括的テスト実行
- レスポンス時間改善
```

## 4. 実装技術詳細

### 4.1 BigQuery特有の実装パターン

#### 4.1.1 limit/limit1関数実装
```php
function limit($query, $limit, $offset = 0) {
    // BigQuery LIMIT/OFFSET構文
    if ($limit !== null) {
        $query .= " LIMIT " . intval($limit);
        if ($offset > 0) {
            $query .= " OFFSET " . intval($offset);
        }
    }
    return $query;
}

function limit1($query) {
    return limit($query, 1);
}
```

#### 4.1.2 explain関数実装
```php
function explain($query) {
    global $bigQueryClient, $currentDatabase;

    try {
        $job = $bigQueryClient->query($query)
            ->dryRun(true)  // BigQuery dry run
            ->defaultDataset($currentDatabase);

        $queryResults = $bigQueryClient->runQuery($job);

        return [
            'totalBytesProcessed' => $queryResults->info()['totalBytesProcessed'] ?? 0,
            'estimatedCost' => calculateQueryCost($queryResults->info()['totalBytesProcessed'] ?? 0),
            'cacheHit' => $queryResults->info()['cacheHit'] ?? false,
            'queryPlan' => $queryResults->info()['queryPlan'] ?? []
        ];
    } catch (Exception $e) {
        error_log("BigQuery explain error: " . $e->getMessage());
        return false;
    }
}
```

#### 4.1.3 found_rows関数実装
```php
function found_rows() {
    global $last_result;

    if ($last_result instanceof Result) {
        return $last_result->num_rows ?? 0;
    }
    return 0;
}
```

### 4.2 information_schema活用

#### 4.2.1 BigQuery INFORMATION_SCHEMA対応
```php
function information_schema($database) {
    return [
        'SCHEMATA' => "`{$database}`.INFORMATION_SCHEMA.SCHEMATA",
        'TABLES' => "`{$database}`.INFORMATION_SCHEMA.TABLES",
        'COLUMNS' => "`{$database}`.INFORMATION_SCHEMA.COLUMNS",
        'TABLE_OPTIONS' => "`{$database}`.INFORMATION_SCHEMA.TABLE_OPTIONS",
        'PARTITIONS' => "`{$database}`.INFORMATION_SCHEMA.PARTITIONS",
        'ROUTINES' => "`{$database}`.INFORMATION_SCHEMA.ROUTINES"
    ];
}
```

## 5. 実装制約・注意事項

### 5.1 BigQuery固有制約

#### 5.1.1 DDL制限
- `ALTER TABLE` でのカラム削除不可（将来対応予定）
- 一部のデータ型変更制限
- パーティション変更制限

#### 5.1.2 権限制限
- IAM権限による機能制限
- データセット・テーブル作成権限要確認
- クエリ実行権限の段階的制御

### 5.2 コスト・パフォーマンス考慮

#### 5.2.1 API呼び出し最適化
- メタデータ取得のバッチ処理
- キャッシュ機能活用
- 不要な INFORMATION_SCHEMA クエリ削減

#### 5.2.2 クエリコスト管理
- dry run による事前コスト見積り
- 大容量スキャン警告機能
- クエリ制限機能の実装

## 6. テスト戦略

### 6.1 段階的テスト

#### Phase 1テスト
```bash
# 基本機能テスト
- SELECT文のlimit/offset動作
- explain機能の正確性
- エラーハンドリング確認
- ユーザー情報表示確認
```

#### Phase 2テスト
```bash
# システム情報テスト
- INFORMATION_SCHEMA活用確認
- データ型変換の正確性
- 照合順序情報表示
```

#### Phase 3テスト
```bash
# 管理機能テスト
- データセット作成・削除・名前変更
- テーブル変更・コピー・移動
- 権限エラー処理確認
```

### 6.2 E2Eテスト連携

#### 6.2.1 Playwright MCPテスト拡張
```javascript
// 未実装機能のUI動作確認
- limit機能のページング動作
- explain結果の表示確認
- エラーメッセージの適切性
- 管理操作の安全性確認
```

## 7. 成功評価基準

### 7.1 機能完成度
- ✅ **Phase 1**: 基本クエリ機能100%動作
- ✅ **Phase 2**: システム情報表示機能完成
- ✅ **Phase 3**: 管理機能安全実装
- ✅ **Phase 4**: 全機能統合・最適化完了

### 7.2 品質基準
- ✅ **エラー率**: 0% (正常系)
- ✅ **レスポンス**: 平均応答時間 < 3秒
- ✅ **安定性**: 24時間連続稼働
- ✅ **セキュリティ**: 権限制御100%動作

### 7.3 ユーザビリティ
- ✅ **直感性**: MySQL経験者が迷わず操作可能
- ✅ **エラー対応**: 分かりやすいエラーメッセージ
- ✅ **パフォーマンス**: ストレスフリーな操作体験

## 8. 実装開始準備

### 8.1 即時実行項目
1. **PROGRESS.md作成** - 進捗管理ファイル
2. **作業ブランチ作成** - feature/未実装機能実装
3. **Phase 1実装開始** - limit/explain機能から着手

### 8.2 継続的改善
- 各Phase完了後のPlaywright MCPテスト実行
- 機能実装後の即時コミット・PR作成
- ユーザーフィードバック収集・反映

---

**この計画により、BigQueryドライバーはAdminer標準機能の90%以上をカバーし、実用的で完成度の高いプロダクションレディなドライバーとして完成します。**