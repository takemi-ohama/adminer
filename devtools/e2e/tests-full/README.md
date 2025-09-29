# BigQuery Adminer E2E 包括テストスイート

## 概要
このディレクトリには、BigQuery Adminer プラグインの全機能を包括的にテストするPlaywright E2Eテストスイートが含まれています。

## テストファイル構成

### 📋 テストスクリプト
| ファイル名 | 機能 | テスト対象 |
|-----------|------|-----------|
| `01-authentication-login.spec.js` | 認証・ログイン | BigQuery接続、ドライバー選択、プロジェクト認証 |
| `02-database-dataset-operations.spec.js` | データセット操作 | データセット一覧、作成、削除、選択 |
| `03-table-schema-operations.spec.js` | テーブル・スキーマ操作 | テーブル一覧、作成、スキーマ表示、構造確認 |
| `04-sql-query-execution.spec.js` | SQLクエリ実行 | SELECT実行、EXPLAIN、結果表示、エラーハンドリング |
| `05-data-modification.spec.js` | データ変更操作 | INSERT、UPDATE、DELETE、検索、一括操作 |
| `06-ui-navigation-menu.spec.js` | UI・ナビゲーション | メニュー操作、レスポンシブ、BigQuery固有UI |
| `07-import-export.spec.js` | インポート・エクスポート | データエクスポート、インポート、ファイル形式 |

### 🚀 実行スクリプト
| スクリプト名 | 用途 | 実行時間 | 使用場面 |
|-------------|------|----------|----------|
| `run-smoke-test.sh` | スモークテスト | ~2分 | 基本動作確認、CI/CD |
| `run-critical-path-tests.sh` | クリティカルパステスト | ~5分 | 重要機能確認 |
| `run-all-tests.sh` | 包括テスト | ~15分 | 完全検証 |
| `run-individual-test.sh` | 個別テスト | ~2分 | デバッグ、開発中 |
| `run-regression-test.sh` | リグレッションテスト | ~20分 | 機能改修後の検証 |

## 使用方法

### 🔥 クイックスタート
```bash
# 1. Webサーバー起動（前提条件）
cd ../../container/web
docker compose up -d

# 2. テストディレクトリに移動
cd ../e2e/tests-full

# 3. 基本動作確認
./run-smoke-test.sh

# 4. 包括テスト実行
./run-all-tests.sh
```

### 🎯 個別テスト実行
```bash
# テスト一覧表示
./run-individual-test.sh

# 認証テスト実行
./run-individual-test.sh 1

# SQLクエリテストをデバッグモードで実行
./run-individual-test.sh 4 --debug

# UIテストをブラウザ表示で実行
./run-individual-test.sh 6 --headed
```

### 🔍 段階的テスト戦略
```bash
# ステップ1: 環境確認
./run-smoke-test.sh

# ステップ2: 重要機能確認
./run-critical-path-tests.sh

# ステップ3: 完全テスト
./run-all-tests.sh

# ステップ4（機能改修時）: 回帰テスト
./run-regression-test.sh --baseline previous-report.txt --compare
```

## 前提条件

### 🐳 Docker環境
- **Webサーバー**: `adminer-bigquery-web` コンテナが起動していること
- **確認方法**: `docker ps | grep adminer-bigquery-web`
- **起動方法**: `cd ../../container/web && docker compose up -d`

### 🔑 BigQuery認証
- **環境変数**: `GOOGLE_CLOUD_PROJECT`, `GOOGLE_APPLICATION_CREDENTIALS`
- **設定ファイル**: `../../container/web/.env`
- **認証ファイル**: サービスアカウントJSONキーが配置されていること

### 🎭 Playwright環境
- **Node.js**: 16.x以上
- **Playwright**: 自動インストール
- **ブラウザ**: Chromium（自動ダウンロード）

## テスト結果

### 📊 結果ファイル
- **ログ**: `test-results/` ディレクトリに自動保存
- **レポート**: `playwright-report/index.html`
- **スクリーンショット**: 失敗時に自動生成
- **動画**: ヘッドレスモード時に記録

### 📈 結果分析
```bash
# 最新のテスト結果確認
ls -la test-results/ | head -10

# 失敗テストの詳細確認
cat test-results/individual-test-*.txt

# Playwrightレポートをブラウザで表示
npx playwright show-report
```

## トラブルシューティング

### ❌ よくある問題と解決方法

#### 1. Webサーバー接続エラー
```bash
# 症状: "❌ Adminer Web サーバーに接続できません"
# 解決:
cd ../../container/web
docker compose restart
curl -I http://localhost:8080
```

#### 2. BigQuery認証エラー
```bash
# 症状: 認証テストが失敗
# 解決:
docker compose exec web env | grep GOOGLE
# 環境変数が正しく設定されているか確認
```

#### 3. テスト実行タイムアウト
```bash
# 症状: テストが途中で止まる
# 解決: 個別デバッグ実行
./run-individual-test.sh 1 --debug --headed
```

#### 4. UI要素が見つからない
```bash
# 症状: "Element not found" エラー
# 解決: ヘッドありモードで画面確認
./run-individual-test.sh 6 --headed
```

### 🔧 デバッグ手順
1. **スモークテスト**: 基本環境確認
2. **個別テスト**: 問題箇所の特定
3. **ヘッドありモード**: 実際のブラウザ動作確認
4. **ログ分析**: 詳細エラー情報の確認

## 開発・運用ガイド

### 🚀 CI/CDでの使用
```yaml
# GitHub Actions例
- name: Run BigQuery Adminer E2E Tests
  run: |
    cd devtools/e2e/tests-full
    ./run-smoke-test.sh
    ./run-critical-path-tests.sh
```

### 📝 機能追加時のテスト追加
1. 既存テストファイルに新しいテストケースを追加
2. 新しい機能カテゴリの場合は新しい`.spec.js`ファイルを作成
3. `run-all-tests.sh`にテストファイルを追加
4. `run-critical-path-tests.sh`に重要テストを追加

### 🔄 定期実行
```bash
# cron例（毎日午前2時に実行）
0 2 * * * cd /path/to/adminer/devtools/e2e/tests-full && ./run-all-tests.sh
```

## 参考情報

### 📚 関連ドキュメント
- [Playwright公式ドキュメント](https://playwright.dev/)
- [BigQuery Adminer開発ガイド](../../docs/development-workflow.md)
- [テスト戦略詳細](../../docs/testing-guide.md)

### 🐛 バグ報告
テスト失敗やバグを発見した場合：
1. 失敗ログファイルを保存
2. 再現手順を記録
3. Issues作成: `devtools/issues/`
4. テスト結果とログを添付

---

**最終更新**: 2025年9月21日
**メンテナ**: BigQuery Adminer開発チーム