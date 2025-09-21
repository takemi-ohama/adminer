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

### 現在保存されているメモリー（2025-09-21更新）
- `playwright_e2e_comprehensive_restoration_2025-09-21`: **最新** - PlaywrightE2Eテストスイート完全修復とMCP手法確立
- `e2e_comprehensive_testing_and_ui_issues_2025-09-21`: E2E包括テスト結果と技術課題分析
- `i03_implementation_progress_2025-09-21`: i03.md実装進捗と次期課題
- `i03_task_completion_stage2_2025-09-21`: i03.md Stage2完了記録
- `i03_e2e_testing_system_establishment_2025-09-20`: E2Eテスト環境確立
- `i03_authentication_breakthrough_2025-09-20`: 認証突破とテスト成功記録
- `bigquery_project_final_phase_2025-09`: BigQueryプロジェクト最終フェーズ記録
- `bigquery_env_var_standardization_completion_2025-09`: 環境変数標準化完了記録
- `bigquery_env_var_authentication_fix_2025-09`: 認証エラー修正記録
- `directory_structure_update_2025-09`: ディレクトリ構造変更の完全記録
- `comprehensive_bigquery_project_analysis`: プロジェクト包括分析結果
- `pr_creation_workflow`: PR作成手順の記録
- `adminer_bigquery_analysis`: 初期詳細プロジェクト分析結果

### 記憶活用の重要ポイント（2025-09-21更新）
1. **最新情報の優先**: `playwright_e2e_comprehensive_restoration_2025-09-21` が最新のE2E修復記録
2. **技術課題の系統管理**: `e2e_comprehensive_testing_and_ui_issues_2025-09-21`でUI課題を包括管理
3. **知見の継続更新**: プロジェクトの進展に合わせて新しい記憶を作成・古い記憶を削除
4. **MCP Playwright活用**: 手動検証→自動テスト修復の新手法確立

### 最新の活用実績（2025-09-21）
- **Playwright E2E完全修復**: 14テストケース全て正常動作、MCP Playwright手動検証手法確立
- **Docker環境基盤修正**: ボリュームマウント問題とファイルコピーバグの根本解決
- **ロバストUI自動化**: 柔軟セレクター戦略と包括的エラーハンドリング実装
- **技術課題体系化**: AdminerコアのJushライブラリ依存とJavaScript未定義関数問題の特定
- **品質保証基盤確立**: BigQueryプラグインの継続的テスト実行環境完成

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
**2025年09月21日 16:15:00**

最新記憶: `copilot_pr_review_comprehensive_handling_2025-09-21`
- PR #29において7つのCopilot指摘事項を3回の修正サイクルで完全解決
- `/fix`コマンドによる自動レビュー対応ワークフローの確立
- Result生成例外安全性・正規表現最適化・null coalescing operator適用等のコード品質向上
- 段階的修正アプローチによるレビュー効率化手法の確立
- モダンPHP機能活用とコードメンテナンス性向上の包括的改善

主要記憶: `i03_sql_command_completion_2025-09-21`
- SQL Command機能完全修正（0件表示→正常表示）とBigQueryドライバー安定化完了
- store_result()メソッド・explain関数・Result強化による包括的修正
- container/issues/i03.md #7指示による重要機能修正の完了記録

基盤記憶: `playwright_e2e_comprehensive_restoration_2025-09-21`
- Playwright E2Eテストスイート完全修復とMCP Playwright手法確立
- 14テストケース全正常動作とロバストUI要素検出パターン実装

保存記憶: `bigquery_env_var_authentication_fix_2025-09`
- $_ENV → getenv() による確実な環境変数取得実現とPHP制約解決

## 重要な技術的発見 (2025年9月21日最終更新)

### Copilot PR Review活用手法 (2025年9月21日新規確立)
- **段階的修正アプローチ**: 7つの指摘事項を3回に分けて確実に解決
- **Claude Code `/fix`コマンド**: 自動検出→修正→コミット→プッシュの完全自動化
- **継続的品質向上**: commit後の自動再レビューによるレビューサイクル確立
- **モダンPHP活用**: null coalescing operator(??)や例外安全性パターンの適用

### SQL Command機能修復知見 (2025年9月21日完全解決)
- **store_result()パターン**: `$last_result`プロパティによる結果保存の必須実装
- **explain関数グローバル実装**: BigQuery EXPLAIN文対応の関数追加要件
- **Result強化**: `num_rows`・`charsetnr`等プロパティの包括的実装必要性
- **静的リソース配置**: `externals/`ディレクトリのJushライブラリ問題解決

### PHP環境変数とDocker最適化
- **getenv()関数**: variables_order制約を回避する確実なアクセス方法
- **GOOGLE_CLOUD_PROJECT**: 公式標準環境変数による一貫した設定
- **直接マウント**: 開発効率化のための`../../:/app`マウント方式

### MCP Playwright・E2Eテスト基盤
- **手動検証→自動修復**: UI状態確認による確実なテスト修正手法
- **ロバストセレクター**: 複数フォールバック対応の要素検出戦略
- **効果**: 推測試行錯誤を排除、確実修正により開発効率大幅向上
- **原因1**: `playwright.config.js`のtestDir設定とプロジェクト定義の不整合
- **原因2**: Docker環境でのファイルコピー先とPlaywright設定パスの不一致
- **原因3**: entrypoint.shでのディレクトリ/ファイル混同によるコピー失敗
- **解決**: 設定ファイル明示（`--config`パラメータ）と統一パス管理

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
