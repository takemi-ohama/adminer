# Adminer BigQuery Driver Plugin 開発プロジェクト

## プロジェクト概要
AdminerのドライバープラグインとしてBigQueryに接続し、最低限の操作（クエリ実行・データセット/テーブル/スキーマ閲覧・結果ページング）を提供するMVPを開発する。
このプロジェクトでは、claude codeのやり取りは全て日本語で応答してください。

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

## ワークスペース構造 (2025-09更新)
```
adminer/
├── adminer/                    # Adminerコア
│   └── drivers/               # 標準ドライバー (mysql, pgsql, etc.)
├── plugins/                   # プラグイン群
│   ├── drivers/              # ドライバープラグイン (elastic, mongo, etc.)
│   └── login-servers.php     # ログインサーバー設定
├── container/                 # コンテナ環境（役割別分離）
│   ├── dev/                  # 開発環境関連
│   ├── web/                  # Webアプリケーション関連（旧tests）
│   │   ├── compose.yml       # Adminerサービス定義
│   │   ├── Dockerfile        # Webコンテナ設定
│   │   └── index.php         # Adminer設定
│   └── e2e/                  # E2Eテスト環境（新規分離）
│       ├── compose.yml       # Playwrightテストサービス
│       ├── tests/            # E2Eテストスクリプト
│       └── run-*.sh          # テスト実行スクリプト
├── container/docs/           # ドキュメント
│   ├── testing-guide.md      # テスト方法ガイド
│   └── development-workflow.md # 開発ワークフローガイド
├── container/issues/         # プロジェクト管理
│   ├── i01.md               # 開発指示書
│   └── report*.md           # 実装方針詳細
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

## 分析完了事項 (2025-09-18/19)
### ワークスペース構造分析結果
- Adminerコア: `adminer/` ディレクトリ（drivers/, include/, lang/, static/）
- プラグイン: `plugins/` ディレクトリ（既存ドライバープラグイン6個確認）
- 開発管理: `container/issues/`, `container/` （役割別分離完了）, 設定ファイル群
- ドキュメント: `container/docs/` （テスト・開発ガイド整備完了）

### ディレクトリ構造改善 (2025-09-19)
- **container/tests** → **container/web** （役割明確化）
- **container/e2e** 新規作成（E2E環境分離）
- **Playwright E2E テスト環境** 構築完了
- **モンキーテスト** 実装・検証完了
- **開発ワークフロー** 文書化完了

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

## 開発補助ツール - Serena MCP

### Serena MCPの積極利用
このプロジェクトでは **Serena MCP** を活用してコードベースの効率的な分析・編集を行います。
ファイル全体を読み込む前に、必ずSerenaの分析ツールを使用してトークン使用量を最適化してください。

### 主要な利用方法

#### 1. コード分析時の基本パターン
```
1. get_symbols_overview でファイル構造の把握
2. find_symbol で特定のクラス・関数の詳細取得
3. find_referencing_symbols で依存関係の確認
4. 必要な場合のみ Read ツールでファイル全体を読み込み
```

#### 2. 実装開発時の編集パターン
```
1. find_symbol で編集対象の特定
2. replace_symbol_body でメソッド・クラス全体の置き換え
3. insert_after_symbol / insert_before_symbol で新規追加
4. search_for_pattern で横断的なパターン検索
```

### 重要な注意事項
- ⚠️ **必須**: ファイル全体読み込み前にシンボリックツールを使用
- ⚠️ **禁止**: 既にファイル内容を把握している場合の重複読み込み
- ✅ **推奨**: メモリー機能を活用した分析結果の保存・参照

### プロジェクト固有の使用例

#### BigQueryドライバー実装時
```bash
# 1. 既存ドライバーの分析
mcp__serena__get_symbols_overview plugins/drivers/elastic.php
mcp__serena__find_symbol Driver plugins/drivers/elastic.php

# 2. 新規実装時のシンボル編集
mcp__serena__insert_after_symbol
mcp__serena__replace_symbol_body

# 3. 分析結果の記憶
mcp__serena__write_memory bigquery_implementation_notes
```

#### プロジェクト進行管理
```bash
# メモリー管理
mcp__serena__list_memories           # 保存済み分析結果の確認
mcp__serena__read_memory [name]      # 過去の分析結果参照
mcp__serena__write_memory [name]     # 新しい分析結果保存
```

### 現在保存されているメモリー
- `adminer_bigquery_analysis`: 詳細なプロジェクト分析結果
- `pr_creation_workflow`: PR作成手順の記録
- `directory_structure_update_2025-09`: ディレクトリ構造変更の完全記録
- `playwright_mcp_testing_workflow`: Playwright MCPテスト手順とベストプラクティス

このSerena MCPの活用により、大規模なコードベースでも効率的かつ精密な開発を実現します。

## Playwright MCP テスト手順 (2025-09追加)

### DooD環境での Playwright MCP テスト
Claude Code環境から `adminer-bigquery-test` コンテナへの接続テスト手順

#### 基本テストフロー
```bash
# 1. コンテナ状況確認
docker ps | grep adminer-bigquery-test

# 2. Playwright MCP テスト実行
# - browser_navigate でアクセス
# - browser_click で操作
# - browser_wait_for で待機（重要）
# - browser_snapshot で状態確認
```

#### 重要な技術ポイント
- **DooD接続形式**: `http://[コンテナ名]` でアクセス
- **ナビゲーション待機**: BigQuery認証処理のため3秒待機必須
- **セレクター戦略**: 複数マッチ回避のため具体的CSS/IDセレクター使用
- **エラーハンドリング**: 権限制限テーブルへの対応

#### 検証可能な機能
- BigQuery接続・認証プロセス
- データセット一覧表示
- テーブル構造表示
- Adminer UI ナビゲーション

このPlaywright MCPテストにより、実際のブラウザ操作に近い形でのE2E検証が可能です。

## 開発・テスト手順 (2025-09更新)

### 基本開発フロー
```bash
# 1. Webアプリケーション起動
cd container/web
docker compose up --build -d

# 2. 開発・コード修正
# BigQueryドライバープラグインの実装・修正

# 3. 基本動作確認
curl -I http://localhost:8080

# 4. E2Eテスト実行
cd ../e2e
./run-e2e-tests.sh

# 5. 安定性テスト（必要に応じて）
./run-monkey-test.sh
```

### テスト環境の使い分け
- **高速確認**: Docker Container テスト（curl）
- **包括検証**: Playwright E2E テスト
- **安定性検証**: モンキーテスト（ランダム操作）

### 開発効率化のポイント
1. **環境分離**: Web開発とE2Eテストが独立
2. **段階的テスト**: 軽量→包括的の順でテスト実行
3. **自動化**: スクリプトによるワンコマンド実行
