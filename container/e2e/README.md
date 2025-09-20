# BigQuery Adminer E2E Testing Suite

このディレクトリにはPlaywrightを使用したBigQuery AdminerドライバーのE2Eテストが含まれています。

## 概要

従来のcurlベースのテストに加えて、ブラウザベースのE2Eテストを提供し、実際のユーザーエクスペリエンスを検証します。

## テスト構成

### テストファイル

- `tests/bigquery-basic.spec.js` - 基本機能テスト
  - ログイン機能
  - データセット表示
  - テーブル一覧
  - テーブル構造表示
  - データ選択画面

- `tests/bigquery-advanced.spec.js` - 高度な機能テスト
  - 複数データセット処理
  - エラーハンドリング
  - セッション管理
  - ナビゲーション
  - ページ構造検証

### 設定ファイル

- `playwright.config.js` - Playwright設定
- `package.json` - Node.js dependencies

## 実行方法

### 1. 簡単実行（推奨）

```bash
# プロジェクトルートから
cd container/e2e
./run-e2e-tests.sh
```

### 2. 手動実行

```bash
# Adminerコンテナ起動 (先にwebディレクトリから)
(cd ../web && docker compose up -d adminer-bigquery-test)

# E2Eテスト実行
docker compose run --rm playwright-e2e npm test

# 特定のブラウザのみテスト
docker compose run --rm playwright-e2e npm run test:chromium
```

### 3. デバッグモード

```bash
# ヘッド付きモード（ブラウザ画面表示）
docker compose run --rm playwright-e2e npm run test:headed

# デバッグモード
docker compose run --rm playwright-e2e npm run test:debug
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
- `GOOGLE_CLOUD_PROJECT` - テスト対象のBigQueryプロジェクト

## 出力ファイル

### テストレポート

```bash
# HTMLレポート表示
docker compose run --rm playwright-e2e npm run test:report
```

- `playwright-report/` - HTML形式の詳細レポート
- `test-results/` - JSON形式のテスト結果

### スクリーンショット・動画

テスト失敗時に自動的にキャプチャされます：

- `test-results/` ディレクトリ内
- 失敗したテストのスクリーンショット
- 失敗したテストの録画動画

## テストケース詳細

### Basic Tests (bigquery-basic.spec.js)

1. **Login Page Load**
   - ページ読み込みエラーなし
   - 必要フォーム要素の存在

2. **Authentication**
   - BigQueryドライバーでのログイン
   - 認証成功後の画面遷移

3. **Dataset Navigation**
   - データセット一覧表示
   - prod_carmo_db選択

4. **Table Listing**
   - テーブル一覧表示
   - member_infoテーブル存在確認

5. **Table Structure**
   - member_infoスキーマ表示
   - エラーなし確認

6. **Data Selection Access**
   - データ選択画面アクセス
   - クエリフォーム存在確認

### Advanced Tests (bigquery-advanced.spec.js)

1. **Multiple Datasets**
   - 複数データセット処理
   - データセット情報表示

2. **Table Information**
   - テーブルメタデータ表示
   - 統計情報表示

3. **Schema Display**
   - 詳細スキーマ情報
   - フィールド型情報

4. **Navigation**
   - パンくずナビゲーション
   - リンク機能

5. **Error Handling**
   - 存在しないリソースアクセス
   - 適切なエラー処理

6. **Session Management**
   - セッション保持
   - 認証状態維持

## トラブルシューティング

### よくある問題

1. **Adminerコンテナが起動しない**
   ```bash
   docker compose logs adminer-bigquery-test
   ```

2. **認証情報エラー**
   - Google認証ファイルの存在確認
   - 環境変数設定確認

3. **ネットワーク接続エラー**
   - Docker networkの確認
   - コンテナ間通信確認

### デバッグ情報取得

```bash
# テスト詳細ログ
DEBUG=pw:* docker compose --profile e2e run --rm playwright-e2e npm test

# Adminerログ確認
docker compose logs adminer-bigquery-test
```

## CI/CD統合

GitHub Actions等でのCI実行例：

```yaml
- name: Run E2E Tests
  run: |
    cd container/e2e
    ./run-e2e-tests.sh
```

## 注意事項

1. **Google Cloud認証**
   - 有効なサービスアカウントキーが必要
   - BigQueryプロジェクトへのアクセス権限必要

2. **ネットワーク**
   - `adminer-net` Dockerネットワークが必要
   - ポート8080が利用可能である必要

3. **リソース使用量**
   - Playwrightはメモリを多く使用
   - 複数ブラウザテストは時間がかかる
