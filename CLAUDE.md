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

### 現在保存されているメモリー（2025-09-20更新）
- `e2e_script_integration_and_error_detection_2025-09-20`: **最新** - E2Eスクリプト統合・エラー検出システム強化
- `bigquery_env_var_standardization_completion_2025-09`: 環境変数標準化完了記録
- `bigquery_project_final_phase_2025-09`: BigQueryプロジェクト最終フェーズ記録
- `i03_e2e_testing_system_establishment_2025-09-20`: E2Eテスト環境確立
- `comprehensive_bigquery_project_analysis`: プロジェクト包括分析結果
- `directory_structure_update_2025-09`: ディレクトリ構造変更の完全記録
- `bigquery_env_var_authentication_fix_2025-09`: 認証エラー修正記録
- `pr_creation_workflow`: PR作成手順の記録
- `adminer_bigquery_analysis`: 初期詳細プロジェクト分析結果

### 記憶活用の重要ポイント（2025-09更新）
1. **最新情報の優先**: `bigquery_project_final_phase_2025-09` が最新の完全記録
2. **段階的な記憶参照**: 古い記憶から新しい記憶へ時系列で確認
3. **知見の継続更新**: プロジェクトの進展に合わせて新しい記憶を作成
4. **記憶の整理**: 古くなった記憶は適宜削除（`delete_memory`）

### 最新の活用実績（2025-09-20）
- **E2Eスクリプト統合**: 重複スクリプト統合とエラー検出システム強化
- **Playwright設定修正**: Docker環境での安定実行基盤確立
- **統一ログシステム**: タイムスタンプ付きログとエラーハンドリング統一
- **環境変数標準化**: プロジェクト全体のGOOGLE_CLOUD_PROJECT統一作業
- **包括的ドキュメント更新**: 10ファイル以上の一貫した変数名修正

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

### ⚠️ 重要: コード修正後のビルド要件
**コードを修正した後は必ずwebコンテナの再ビルドが必要です**
- Dockerコンテナ内のコードは初回ビルド時にコピーされるため、ホスト側の変更は自動反映されません
- 対象: plugins/drivers/bigquery.php, plugins/login-bigquery.php, container/web/index.php等

### 基本開発フロー
```bash
# 1. Webアプリケーション起動
cd container/web
docker compose up --build -d

# 2. 開発・コード修正
# BigQueryドライバープラグインの実装・修正

# 3. ⚠️ 必須: 修正後のリビルド
docker compose down
docker compose up --build -d

# 4. 基本動作確認
curl -I http://localhost:8080

# 5. E2Eテスト実行
cd ../e2e
./scripts/run-basic-flow-test.sh

# 6. 安定性テスト（必要に応じて）
./scripts/run-monkey-test.sh
```

### テスト環境の使い分け
- **高速確認**: Docker Container テスト（curl）
- **包括検証**: Playwright E2E テスト
- **安定性検証**: モンキーテスト（ランダム操作）

### 開発効率化のポイント
1. **環境分離**: Web開発とE2Eテストが独立
2. **段階的テスト**: 軽量→包括的の順でテスト実行
3. **自動化**: スクリプトによるワンコマンド実行

---

## プロジェクト記録

### Serena記憶の最終更新日時
**2025年09月20日 13:25:00**

最新記憶: `e2e_script_integration_and_error_detection_2025-09-20`
- E2Eテストスクリプトの大幅なリファクタリング完了
- 重複スクリプト統合と統一実行環境の構築（PR #23）
- Playwright設定修正とDocker環境安定化
- 包括的エラー検出システム（Fatal error、PHP Warning等）実装
- タイムスタンプ付きログ・エラーハンドリングの統一化
- 240テストケース認識と6.4秒での安定実行確認

主要記憶: `bigquery_env_var_authentication_fix_2025-09`
- $_ENV → getenv() による確実な環境変数取得実現
- PHP variables_order制約の技術的解決
- "Invalid credentials"エラー完全解消

基盤記憶: `bigquery_project_final_phase_2025-09`
- BigQueryドライバー完全実装とパフォーマンス分析
- マージ後クリーンアップワークフロー確立

## 重要な技術的発見 (2025年9月20日)

### PHP環境変数アクセスの注意点
- **$_ENV配列**: PHPの`variables_order`設定に依存（デフォルト: `GPCS`）
- **getenv()関数**: 設定に関係なく確実にシステム環境変数にアクセス可能
- **推奨**: Dockerコンテナ環境では`getenv()`を使用すべき

### Google Cloud環境変数の標準化
- **GOOGLE_CLOUD_PROJECT**: Google Cloud公式推奨の標準環境変数
- **自動設定**: GCP環境（Cloud Run、Compute Engine等）で自動的に設定
- **BigQueryClient**: projectIdパラメータ省略時のフォールバック変数として使用

## 次期開発課題 (2025年9月20日 新規指示)

### 未実装機能の完全実装 (container/issues/i03.md)
1. **ソート、編集、作成、ダウンロード機能**: 現在未実装の全Adminer機能を実装
2. **包括的E2Eテスト**: container/e2eを使用した全メニュー・ボタン・リンクのテスト
3. **参照系・更新系テスト分離**:
   - 参照系: 既存データでのエラー検証・修正
   - 更新系: 新規データセット・テーブル作成による安全な更新テスト
4. **段階的実装**: 参照系完了→更新系シナリオ作成→最終検証

### パフォーマンス改善実装 (container/issues/i02.md)
1. **ボトルネック解消**: report05.mdに基づくBigQuery API接続高速化
2. **Profiler活用**: 実装済みProfilerでの処理時間計測と報告
3. **APCu警告対応**: report06.mdに基づくstub登録による警告解消

これらの課題により、BigQueryドライバーは完全な機能実装と最適なパフォーマンスを達成します。

## E2Eテスト手法 (2025年9月20日 i03.md #4で確立)

### 🎯 スクリプト指定実行型テスト環境
i03.md #4で確立された安定したE2Eテスト手法。常駐型ではなく、スクリプト指定で自動実行する方式。

#### 基本テスト実行方法
```bash
# 1. Web環境起動（必須前提条件）
cd container/web
docker compose up -d

# 2. 参照系テスト実行（推奨：最初に実行）
cd ../e2e
./scripts/run-reference-tests.sh

# 3. 更新系テスト実行
./scripts/run-crud-tests.sh

# 4. 全テスト実行
./scripts/run-all-tests.sh
```

#### コンテナ内直接実行
```bash
cd container/e2e
docker compose run --rm playwright-e2e reference-test.sh
docker compose run --rm playwright-e2e crud-test.sh
docker compose run --rm playwright-e2e all-tests.sh
```

#### 個別テストファイル実行
```bash
# 参照系機能テスト
docker compose run --rm playwright-e2e npx playwright test tests/reference-system-test.spec.js

# 更新系機能テスト
docker compose run --rm playwright-e2e npx playwright test tests/bigquery-crud-test.spec.js
```

### 📊 ログとレポート管理
- **実行ログ**: `container/e2e/test-results/` に自動保存（タイムスタンプ付き）
- **Playwrightレポート**: `container/e2e/playwright-report/index.html`
- **スクリーンショット・動画**: 失敗時に自動生成

### 🔍 テスト戦略
1. **参照系テスト**: 既存データでの表示・ナビゲーション・検索機能検証
2. **更新系テスト**: 新規データセット・テーブル作成による安全な更新操作
3. **段階的実行**: 参照系完了後に更新系を実行
4. **エラー検出**: 画面とログの両方でエラー確認

### ⚠️ 重要な注意点
- **Web環境前提**: テスト実行前に必ずWebコンテナが起動していること
- **認証設定**: `GOOGLE_CLOUD_PROJECT`、`GOOGLE_APPLICATION_CREDENTIALS`環境変数必須
- **段階的実行**: 参照系でエラー修正後に更新系テストを実行
- **ログ保存**: tokenオーバーフロー対策として実行状況を定期記録

### 📖 詳細ガイド
完全なE2Eテスト手順は `container/docs/e2e-testing-guide.md` を参照してください。
