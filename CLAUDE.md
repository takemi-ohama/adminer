# Adminer BigQuery Driver Plugin 開発プロジェクト

## プロジェクト概要
AdminerのドライバープラグインとしてBigQueryに接続し、最低限の操作（クエリ実行・データセット/テーブル/スキーマ閲覧・結果ページング）を提供するMVPを開発する。

## 技術仕様
- **言語/ランタイム**: PHP 8.x
- **接続ライブラリ**: Google公式PHPクライアント (`google/cloud-bigquery`)
- **認証**: サービスアカウントJSON (`GOOGLE_APPLICATION_CREDENTIALS`)
- **SQL方言**: 標準SQL (Legacy非対応)
- **実行モデル**: BigQueryのJob非同期実行 + 結果ページング

## 実装方針
1. **Driver Plugin形式**: `plugins/drivers/bigquery.php` として実装
2. **READ-ONLY MVP**: 最初は読み取り中心で安定化、後にDML対応
3. **Adminerドライバーインターフェース準拠**: 既存の`mysql.inc.php`のシグネチャを参考

## ワークスペース構造
```
adminer/
├── adminer/                    # Adminerコア
│   └── drivers/               # 標準ドライバー (mysql, pgsql, etc.)
├── plugins/                   # プラグイン群
│   ├── drivers/              # ドライバープラグイン (elastic, mongo, etc.)
│   └── login-servers.php     # ログインサーバー設定
├── issues/                   # プロジェクト管理
│   ├── i01.md               # 開発指示書
│   └── report01.md          # 実装方針詳細
└── composer.json            # 依存関係管理
```

## 必要なドライバーメソッド
- `connect()`: GCPプロジェクトID接続
- `support()`: 機能対応状況定義
- `databases()`: データセット一覧 (BigQuery Dataset → Adminer Database)
- `tables()`/`tableStatus()`: テーブル/ビュー一覧
- `fields()`: テーブルスキーマ取得
- `select()`/`query()`: SQLクエリ実行 (LIMIT/OFFSET対応)
- `explain()`: dryRun実行 (スキャン見積り)

## データモデルマッピング
| Adminer | BigQuery | 備考 |
|---------|----------|------|
| Server | GCP Project | プロジェクトID |
| Database | Dataset | データセット |
| Schema | Dataset | 同一概念 |
| Table | Table/View/MaterializedView | 種別で区別 |

## 制約事項 (MVP)
- 外部キー/インデックス: 非対応
- トランザクション: 非対応
- IMPORT/EXPORT: 後続対応
- DML操作: 初期は非対応 (READ-ONLY)

## 開発環境
- PHP環境: 7.4+ (composer.json確認済み)
- Composer: 利用可能
- BigQueryクライアント: google/cloud-bigquery v1.34 (導入済み)

## 分析完了事項 (2025-09-18)
### ワークスペース構造分析結果
- Adminerコア: `adminer/` ディレクトリ（drivers/, include/, lang/, static/）
- プラグイン: `plugins/` ディレクトリ（既存ドライバープラグイン6個確認）
- 開発管理: `issues/`, `container/`, 設定ファイル群

### 既存ドライバー実装パターン分析
- **Elasticsearchプラグイン**: plugins/drivers/elastic.php (参考実装)
  - Driverクラス + support()関数パターン
  - connect(), select(), insert(), update(), delete()メソッド
- **MySQLドライバー**: adminer/drivers/mysql.inc.php (完全実装)
  - 50以上の関数実装、support()で機能定義

### 実装優先度確定
1. **基本接続**: connect(), support()
2. **メタデータ**: get_databases(), tables_list(), fields()
3. **クエリ実行**: select(), query(), Resultクラス
4. **拡張機能**: explain(), UI最適化