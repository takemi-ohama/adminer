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
- PHP環境: 要確認・セットアップ
- Composer: 利用可能
- BigQueryクライアント: 要導入 (`google/cloud-bigquery`)