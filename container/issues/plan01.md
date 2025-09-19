# Adminer BigQuery Driver Plugin 開発計画

## プロジェクト概要
AdminerのドライバープラグインとしてBigQueryに接続し、読み取り中心のMVP機能を提供する。
Google Cloud BigQueryの標準SQLクエリ実行、データセット/テーブル/スキーマ閲覧、結果ページングを実現する。

**作成日**: 2025-09-18
**対象**: Adminer BigQuery Driver Plugin MVP

## 1. プロジェクト目標

### MVP目標
- AdminerでBigQueryに接続し、基本的なデータ閲覧・クエリ実行機能を提供
- READ-ONLYモードで安定動作を確認後、段階的にDML機能を追加
- プラグイン形式での配布を想定

### 完了条件
- [ ] ログイン画面でBigQueryドライバを選択可能
- [ ] GCPプロジェクトIDで接続可能
- [ ] データセット・テーブル・カラム情報の閲覧
- [ ] SELECTクエリ実行とページング機能
- [ ] EXPLAINによるスキャン量見積り表示

## 2. 技術仕様

### 開発環境
- **PHP**: 8.x以上 (要セットアップ)
- **依存ライブラリ**: `google/cloud-bigquery` (Composer経由)
- **認証**: サービスアカウントJSON + `GOOGLE_APPLICATION_CREDENTIALS`
- **配置**: `plugins/drivers/bigquery.php`

### アーキテクチャ
```
BigQueryドライバープラグイン
├── 接続管理 (connect)
├── 機能サポート定義 (support)
├── メタデータ取得
│   ├── データセット一覧 (databases)
│   ├── テーブル一覧 (tables/tableStatus)
│   └── スキーマ情報 (fields)
├── クエリ実行
│   ├── SELECT実行 (select)
│   ├── 汎用SQL実行 (query)
│   └── 実行計画 (explain/dryRun)
└── 結果処理・ページング
```

## 3. 実装タスク

### Phase 1: 開発環境セットアップ
1. **PHP環境構築**
   - container/Dockerfileで以下を設定・確認
      - PHPインストール・設定確認
      - Composerを使用した依存関係管理
      - `google/cloud-bigquery`ライブラリ導入

2. **プロジェクト準備**
   - 環境変数で設定されたgoogleのcredentialが機能していることを確認
      - gcloudコマンド、bigquery mcpが動くことを確認

### Phase 2: ドライバー骨格実装
1. **基本クラス構造**
   ```php
   // plugins/drivers/bigquery.php
   add_driver("bigquery", "BigQuery");
   class Db extends SqlDb {
       // Google Cloud BigQuery Client
       // 接続管理
       // エラーハンドリング
   }
   ```

2. **必須メソッド実装**
   - `connect($server, $username, $password)`: プロジェクト接続
   - `support($feature)`: 機能対応フラグ定義
   - エラーハンドリング・ログ出力

### Phase 3: メタデータ取得機能
1. **データセット管理**
   - `databases()`: listDatasets API → データセット一覧
   - BigQuery Dataset ↔ Adminer Database マッピング

2. **テーブル管理**
   - `tables($database)`: listTables API → テーブル/ビュー一覧
   - `tableStatus($database)`: メタデータ付きテーブル情報
   - Table/View/MaterializedView の種別判定

3. **スキーマ情報**
   - `fields($table)`: getTable API → カラム定義取得
   - BigQuery型 → Adminer型マッピング
   - NULL許可、説明文の取得

### Phase 4: クエリ実行機能
1. **SELECT実行**
   - `select($query, $limit, $offset)`: LIMIT/OFFSET対応
   - BigQuery Job非同期実行 + 同期的結果取得
   - 結果セット・ページング処理

2. **汎用SQL実行**
   - `query($sql)`: 任意SQL実行
   - MVPでは SELECT のみ許可 (READ-ONLY制約)
   - エラーメッセージの適切な変換

3. **実行計画**
   - `explain($query)`: dryRun実行
   - スキャンバイト数・推定コスト表示
   - クエリ最適化のヒント提供

### Phase 5: UI統合・ユーザビリティ
1. **ログイン画面統合**
   - `login-servers.php`プラグインとの連携
   - ドライバー選択肢への`bigquery`追加
   - プロジェクトID入力フォーム

2. **データ表示最適化**
   - TIMESTAMP/DATE/TIME型の表示形式
   - JSON/GEOGRAPHY型の表示対応
   - 大きな数値型の表示精度

3. **エラーメッセージ**
   - BigQueryエラーのAdminer形式変換
   - 認証失敗・権限不足の適切な表示
   - クォータ制限・課金警告

### Phase 6: テスト・検証
1. **基本動作確認**
   - 各種BigQueryデータ型でのテスト
   - パーティションテーブルでの動作確認
   - 大きなデータセットでのページング確認

2. **エラーケーステスト**
   - 認証失敗時の挙動
   - 権限不足時の挙動
   - ネットワーク障害時の挙動

3. **パフォーマンステスト**
   - 大量データでの応答時間
   - 複数同時接続での安定性
   - メモリ使用量の測定

## 4. データモデル設計

### Adminer ↔ BigQuery マッピング
| Adminer概念 | BigQuery対応 | 実装方針 |
|------------|-------------|----------|
| Server | GCP Project | $server パラメータをプロジェクトIDとして使用 |
| Database | Dataset | listDatasets() → databases() |
| Schema | Dataset | Adminer互換のため、Dataset=Schema として扱う |
| Table | Table/View/MaterializedView | tableStatus()で種別を区別 |
| Column | Field | BigQuery Schema → Adminer field 形式変換 |
| Index/PK/FK | 非対応 | support()でfalse返却 |
| Transaction | 非対応 | support()でfalse返却 |

### BigQuery型マッピング
```php
// BigQuery → Adminer 型変換例
$typeMap = [
    'STRING' => 'varchar',
    'INT64' => 'bigint',
    'FLOAT64' => 'double',
    'BOOL' => 'boolean',
    'NUMERIC' => 'decimal',
    'DATETIME' => 'datetime',
    'DATE' => 'date',
    'TIME' => 'time',
    'TIMESTAMP' => 'timestamp',
    'JSON' => 'json',
    'GEOGRAPHY' => 'text'
];
```

## 5. セキュリティ・権限設計

### 認証方式
1. **サービスアカウント** (推奨)
   - `GOOGLE_APPLICATION_CREDENTIALS` 環境変数
   - JSON keyファイルのセキュアな配置
   - Webディレクトリ外への配置必須

2. **最小権限原則**
   - MVP: `roles/bigquery.dataViewer` + `roles/bigquery.user`
   - 将来のDML対応: `roles/bigquery.dataEditor`
   - 管理機能: `roles/bigquery.admin` (必要時のみ)

### セキュリティ制約
- MVP段階ではINSERT/UPDATE/DELETE/DDL禁止
- クエリタイムアウト設定 (デフォルト: 30秒)
- スキャン量制限の検討 (大量データアクセス防止)

## 6. 配布・デプロイメント

### プラグイン配布
1. **単体プラグイン**
   - `adminer-plugins.php` 経由での読み込み
   - GitHubでのオープンソース配布
   - 使用方法ドキュメント整備

2. **Docker統合** (将来)
   - 公式Adminer Dockerイメージへの統合
   - `ADMINER_PLUGINS` 環境変数での有効化
   - プリセット設定の提供

### 設定例
```php
// adminer-plugins.php
return [
    new AdminerBigQueryDriver([
        'defaultProject' => getenv('BQ_PROJECT'),
        'location' => getenv('BQ_LOCATION') ?: 'US',
        'readOnly' => true,
        'queryTimeout' => 30
    ]),
    new AdminerLoginServers([
        'BigQuery Production' => [
            'server' => getenv('BQ_PROJECT'),
            'driver' => 'bigquery'
        ]
    ])
];
```

## 7. 将来拡張計画

### Phase 2 機能 (DML対応)
- INSERT/UPDATE/DELETE/MERGE 対応
- DDL操作 (CREATE/ALTER/DROP TABLE)
- ユーザー定義関数 (UDF) サポート

### Phase 3 機能 (高度な統合)
- LOAD/EXPORT ジョブのウィザード
- Cloud Storageとの連携
- ジョブ履歴・モニタリング画面

### Phase 4 機能 (最適化・運用)
- クエリ最適化アドバイザー
- パーティション・クラスタリング設定UI
- 課金見積り・アラート機能

## 8. 成功指標・KPI

### 技術的成功指標
- [ ] 基本CRUD操作の100%動作
- [ ] 10GB以上のテーブルでの安定動作
- [ ] 1秒以内の初期接続時間
- [ ] エラー時の適切なメッセージ表示

### ユーザビリティ指標
- [ ] 既存Adminerユーザーの学習コストゼロ
- [ ] BigQuery初心者でも直感的な操作
- [ ] SQLクエリのリアルタイム実行

### 運用指標
- [ ] セキュリティ脆弱性ゼロ
- [ ] メモリリークなし
- [ ] 24時間連続運用可能

---

この開発計画に基づいて、Phase 1から順次実装を進めてください。各フェーズ完了時には動作確認とテストを実施し、問題があれば次フェーズに進む前に解決してください。
