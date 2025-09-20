# BigQuery Adminer E2E Testing Suite

このディレクトリにはPlaywrightを使用したBigQuery AdminerドライバーのE2Eテストが含まれています。

## 概要

従来のcurlベースのテストに加えて、ブラウザベースのE2Eテストを提供し、実際のユーザーエクスペリエンスを検証します。

## ディレクトリ構成

```
container/e2e/
├── scripts/              # テスト実行スクリプト
├── tests/                # テストファイル
├── test-results/         # テスト結果とログ
├── composer.yml          # Dockerコンテナ設定
├── Dockerfile            # E2Eテスト環境
├── entrypoint.sh         # テスト環境セットアップ
├── playwright.config.js  # Playwright設定
└── package.json          # Node.js依存関係
```

## テスト構成

### テストファイル

#### 基本機能テスト
- `tests/basic-flow-test.spec.js` - 基本フロー（ログイン→DB選択→テーブル選択）
- `tests/bigquery-basic.spec.js` - 基本機能テスト
- `tests/bigquery-advanced.spec.js` - 高度な機能テスト

#### 参照系・更新系テスト
- `tests/bigquery-reference-test.spec.js` - 参照系機能テスト
- `tests/bigquery-crud-test.spec.js` - CRUD操作テスト
- `tests/reference-system-test.spec.js` - システム参照機能テスト

#### エラー検出・安定性テスト
- `tests/error-detection-test.spec.js` - エラー検出システムテスト
- `tests/create-table-error-test.js` - 「テーブルを作成」エラー検出テスト
- `tests/bigquery-monkey.spec.js` - モンキーテスト（ランダム操作）

#### デバッグ・調査用スクリプト
- `tests/debug-create-table-links.js` - リンク調査スクリプト
- `tests/find-create-table-comprehensive.js` - 包括的リンク調査
- `tests/test-create-table-direct.js` - 直接URL調査

### 実行スクリプト (`scripts/`)

#### メイン実行スクリプト
- `run-all-tests.sh` - 全E2Eテスト実行
- `run-basic-flow-test.sh` - 基本機能フローテスト
- `run-e2e-tests.sh` - 基本E2Eテスト

#### 機能別テストスクリプト
- `run-reference-tests.sh` - 参照系テスト
- `run-crud-tests.sh` - CRUD操作テスト
- `run-create-table-error-test.sh` - テーブル作成エラー検出テスト
- `run-monkey-test.sh` - モンキーテスト

#### コンテナ内実行用スクリプト
- `all-tests.sh` - コンテナ内全テスト実行
- `basic-flow-test.sh` - コンテナ内基本フローテスト
- `crud-test.sh` - コンテナ内CRUD操作テスト
- `reference-test.sh` - コンテナ内参照系テスト

## 実行方法

### 1. 簡単実行（推奨）

```bash
# プロジェクトルートから
cd container/e2e

# 基本フローテスト
./scripts/run-basic-flow-test.sh

# エラー検出テスト
./scripts/run-create-table-error-test.sh

# 全テスト実行
./scripts/run-all-tests.sh

# 参照系テスト
./scripts/run-reference-tests.sh

# 更新系テスト
./scripts/run-crud-tests.sh

# 安定性テスト
./scripts/run-monkey-test.sh
```

### 2. 手動実行

```bash
# Adminerコンテナ起動（先にwebディレクトリから）
(cd ../web && docker compose up -d adminer-bigquery-test)

# E2Eテスト実行
docker compose run --rm playwright-e2e npx playwright test

# 特定のテストファイル実行
docker compose run --rm playwright-e2e npx playwright test tests/basic-flow-test.spec.js

# 特定のブラウザのみテスト
docker compose run --rm playwright-e2e npx playwright test --project=chromium
```

### 3. デバッグモード

```bash
# ヘッド付きモード（ブラウザ画面表示）
docker compose run --rm playwright-e2e npx playwright test --headed

# デバッグモード
docker compose run --rm playwright-e2e npx playwright test --debug

# 特定のスクリプト実行
docker compose run --rm playwright-e2e node /app/container/e2e/tests/create-table-error-test.js
```

## テスト環境

### 対象ブラウザ

- Chromium (Desktop)
- Firefox (Desktop)
- WebKit/Safari (Desktop)
- Mobile Chrome (Pixel 5)
- Mobile Safari (iPhone 12)

### 環境変数

- `BASE_URL` - AdminerのベースURL (default: http://adminer-bigquery-test)
- `GOOGLE_CLOUD_PROJECT` - テスト対象のBigQueryプロジェクト (default: adminer-test-472623)

## 出力ファイル

### テストレポート

```bash
# HTMLレポート表示
npx playwright show-report
```

- `playwright-report/` - HTML形式の詳細レポート
- `test-results/` - JSON形式のテスト結果とログ

### スクリーンショット・動画

テスト失敗時に自動的にキャプチャされます：

- `test-results/` ディレクトリ内
- 失敗したテストのスクリーンショット
- 失敗したテストの録画動画
- 実行ログファイル（タイムスタンプ付き）

## エラー検出システム

### 包括的エラー検出機能

最新のE2Eテストスイートは、以下のエラーを自動検出します：

1. **Fatal/Parse/PHPエラー**
   - Fatal error、Parse error、Warning、Notice
   - 未定義関数エラー（Call to undefined function）
   - BigQuery特有エラー（idf_escapeエラー等）

2. **ブラウザ/JavaScript エラー**
   - コンソールエラー（404 Not Found等）
   - ページエラー（未定義JavaScript関数等）
   - HTTPステータスエラー

3. **Adminer UI エラー**
   - `.error`セレクタによるエラー要素検出
   - 正規表現パターンマッチング
   - 未実装機能エラー（not supported/not implemented）

### サーバーログ監視

- Apacheエラーログ監視
- PHPエラーログ監視
- Dockerコンテナログ監視

## テストケース詳細

### Basic Flow Tests (`basic-flow-test.spec.js`)

1. **Login Process**
   - BigQueryドライバー選択確認
   - ログイン処理
   - 認証成功確認

2. **Database Navigation**
   - データセット一覧表示
   - データセット選択
   - ナビゲーション確認

3. **Table Operations**
   - テーブル一覧表示
   - テーブル選択とデータ表示
   - エラー検出とサーバーログ監視

### Error Detection Tests (`error-detection-test.spec.js`)

1. **Fatal Error Detection**
   - idf_escape()関数エラー再現テスト
   - 包括的エラー検出実行

2. **Create Table Error Test**
   - 「テーブルを作成」未実装エラー検出
   - 代替セレクター探索
   - サーバーログ確認

3. **Server Log Monitoring**
   - Apache/PHPエラーログ監視
   - コンテナログ解析

### Reference System Tests

1. **Data Display Functions**
   - SELECT クエリ実行
   - テーブル構造表示
   - データページング

2. **Schema Information**
   - フィールド情報表示
   - データ型確認

### CRUD Operation Tests

1. **Create Operations**
   - データセット作成テスト
   - テーブル作成テスト

2. **Update/Delete Operations**
   - データ更新テスト
   - レコード削除テスト

## トラブルシューティング

### よくある問題

1. **Adminerコンテナが起動しない**
   ```bash
   docker compose logs adminer-bigquery-test
   ```

2. **認証情報エラー**
   - Google認証ファイルの存在確認
   - 環境変数設定確認（GOOGLE_CLOUD_PROJECT）

3. **ネットワーク接続エラー**
   - Docker networkの確認（adminer_net）
   - コンテナ間通信確認

4. **テストファイルが見つからない**
   - Volumeマウント設定確認
   - entrypoint.shでのファイルコピー確認

### デバッグ情報取得

```bash
# テスト詳細ログ
DEBUG=pw:* docker compose run --rm playwright-e2e npx playwright test

# Adminerログ確認
docker compose logs adminer-bigquery-test

# コンテナ内ファイル確認
docker compose run --rm playwright-e2e ls -la /app/container/e2e/tests/

# 環境変数確認
docker compose run --rm playwright-e2e env | grep -E "(BASE_URL|GOOGLE_CLOUD_PROJECT)"
```

## CI/CD統合

GitHub Actions等でのCI実行例：

```yaml
- name: Setup Web Environment
  run: |
    cd container/web
    docker compose up -d

- name: Run E2E Tests
  run: |
    cd container/e2e
    ./scripts/run-all-tests.sh

- name: Run Error Detection Tests
  run: |
    cd container/e2e
    ./scripts/run-create-table-error-test.sh
```

## 注意事項

1. **Google Cloud認証**
   - 有効なサービスアカウントキーが必要
   - BigQueryプロジェクトへのアクセス権限必要
   - GOOGLE_CLOUD_PROJECT環境変数設定必要

2. **ネットワーク**
   - `adminer-net` Dockerネットワークが必要
   - ポート8080が利用可能である必要

3. **リソース使用量**
   - Playwrightはメモリを多く使用
   - 複数ブラウザテストは時間がかかる

4. **実行順序**
   - 参照系テスト → 更新系テストの順で実行推奨
   - Web環境が先に起動している必要

## 開発履歴

- **2025-09-20**: エラー検出システム強化、「テーブルを作成」エラー検出テスト追加
- **2025-09-19**: 包括的テストスイート構築、参照系・更新系テスト分離
- **2025-09-18**: 基本E2E環境構築、モンキーテスト実装