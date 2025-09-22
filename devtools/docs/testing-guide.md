# BigQuery Adminer Testing Guide

AdminerのBigQueryドライバーにおける包括的なテスト方法を説明します。

## 📁 新しいディレクトリ構造 (2025-09更新)

テスト環境のディレクトリ構造が整理され、役割が明確になりました：

```
container/
├── web/               # Webアプリケーション関連
│   ├── compose.yml    # Adminerサービス定義
│   ├── Dockerfile     # Webコンテナ設定
│   └── 関連ファイル
│
├── e2e/              # E2Eテスト関連
│   ├── compose.yml   # Playwrightテストサービス定義
│   ├── Dockerfile    # E2Eテストコンテナ設定
│   ├── tests/        # Playwrightテストスクリプト
│   └── run-*.sh      # テスト実行スクリプト
│
└── dev/              # 開発環境関連
    └── 開発用設定
```

## テスト種別

### 1. Docker Container テスト（基本）
curl を使用したコンテナ内部からのテスト実行

### 2. Playwright E2E テスト（推奨）
ブラウザベースの実際のユーザーエクスペリエンステスト

### 3. モンキーテスト（安定性検証）
ランダムな操作によるアプリケーション安定性テスト

## 1. Docker Container テスト

### 1.1 基本実行

```bash
# コンテナ起動
cd container/web
docker compose up --build -d

# 基本接続テスト
docker exec adminer-bigquery-test curl -I "http://localhost/?bigquery=nyle-carmo-analysis&username="
```

### 1.2 認証・セッションテスト

```bash
# ログイン実行
docker exec adminer-bigquery-test bash -c '
  curl -s -c /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=" \
    -d "auth[driver]=bigquery&auth[server]=nyle-carmo-analysis&auth[username]=&auth[password]=&auth[db]=" \
    -X POST
'

# 認証後データアクセス
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db"
'
```

### 1.3 エラー検出テスト

```bash
# Fatal Error検出
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&table=member_info" \
    | grep -E "(Fatal error|TypeError|Error|Exception|Warning)" | head -3
'
```

### 1.4 機能別テストURL

#### データベース（データセット）一覧
```bash
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username="
'
```

#### テーブル一覧
```bash
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db"
'
```

#### テーブル構造表示
```bash
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&table=member_info"
'
```

#### データ選択画面
```bash
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&select=member_info"
'
```

## 2. Playwright E2E テスト

### 2.1 基本実行（推奨）

```bash
# プロジェクトルートから
cd container/e2e
./run-e2e-tests.sh
```

### 2.2 手動実行

```bash
# 1. Adminerコンテナ起動（webディレクトリから）
cd container/web
docker compose up -d adminer-bigquery-test

# 2. E2Eテスト実行（e2eディレクトリから）
cd ../e2e
docker compose run --rm playwright-e2e npm test
```

### 2.3 モンキーテスト実行

```bash
# プロジェクトルートから
cd container/e2e
./run-monkey-test.sh
```

### 2.3 ブラウザ別実行

```bash
# Chromiumのみ
docker compose --profile e2e run --rm playwright-e2e npm run test:chromium

# Firefoxのみ
docker compose --profile e2e run --rm playwright-e2e npm run test:firefox

# WebKitのみ
docker compose --profile e2e run --rm playwright-e2e npm run test:webkit
```

### 2.4 デバッグモード

```bash
# ヘッド付きモード（ブラウザ画面表示）
docker compose --profile e2e run --rm playwright-e2e npm run test:headed

# デバッグモード（ステップ実行）
docker compose --profile e2e run --rm playwright-e2e npm run test:debug
```

### 2.5 テストレポート表示

```bash
# HTMLレポート表示
docker compose --profile e2e run --rm playwright-e2e npm run test:report
```

## 3. テストケース一覧

### 3.1 Basic Tests（基本機能）

1. **Login Page Load** - ログインページ読み込み
2. **BigQuery Authentication** - BigQuery認証
3. **Dataset Display** - データセット表示
4. **Table Listing** - テーブル一覧
5. **Table Structure** - テーブル構造表示
6. **Data Selection Access** - データ選択画面

### 3.2 Advanced Tests（高度な機能）

1. **Multiple Datasets** - 複数データセット処理
2. **Table Information** - テーブル詳細情報
3. **Schema Display** - スキーマ詳細表示
4. **Navigation Links** - ナビゲーション機能
5. **Error Handling** - エラー処理
6. **Session Management** - セッション管理
7. **Page Structure** - ページ構造検証

## 4. 成功判定基準

### 4.1 Docker Container テスト

```bash
# エラーなし確認（何も出力されない = 成功）
docker exec adminer-bigquery-test bash -c '
  curl -s -b /tmp/cookies.txt "URL" | grep -E "(Fatal error|TypeError)" | head -3
'
```

### 4.2 E2E テスト

- 全テストケースの PASS
- スクリーンショット・動画キャプチャでの視覚確認
- HTMLレポートでの詳細分析

## 5. トラブルシューティング

### 5.1 共通問題

#### Adminerコンテナが起動しない
```bash
# ログ確認
docker compose logs adminer-bigquery-test

# 強制再起動
docker compose down && docker compose up --build -d
```

#### 認証エラー
```bash
# 認証ファイル確認
ls -la /home/hammer/google_credential.json

# 環境変数確認
docker exec adminer-bigquery-test printenv | grep GOOGLE
```

### 5.2 E2E特有の問題

#### Playwrightコンテナエラー
```bash
# 詳細ログ表示
DEBUG=pw:* docker compose --profile e2e run --rm playwright-e2e npm test

# コンテナ再ビルド
docker compose build playwright-e2e
```

#### ネットワーク接続エラー
```bash
# Dockerネットワーク確認
docker network ls | grep adminer

# コンテナ間通信確認
docker exec playwright-e2e ping adminer-bigquery-test
```

## 6. 継続的テスト実行

### 6.1 開発時のテストサイクル

```bash
# 1. コード修正後の基本確認
cd container/web
docker compose up --build -d

# 2. 快速テスト（curlベース）
docker exec adminer-bigquery-test bash -c '
  curl -s -c /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=" \
    -d "auth[driver]=bigquery&auth[server]=nyle-carmo-analysis&auth[username]=&auth[password]=&auth[db]=" -X POST
'

# 3. 包括的テスト（E2E）
./run-e2e-tests.sh
```

### 6.2 CI/CD統合

```yaml
# GitHub Actions 例
- name: Build and Test Adminer BigQuery
  run: |
    cd container/web
    docker compose up --build -d adminer-bigquery-test
    cd ../e2e
    ./run-e2e-tests.sh
```

## 7. テスト環境要件

### 7.1 必要なファイル・設定

- Google Cloud サービスアカウントキー
- BigQuery プロジェクトアクセス権限
- Docker & Docker Compose
- 外部Dockerネットワーク `adminer-net`

### 7.2 ポート使用

- `8080`: Adminer Web UI
- Playwright: 動的ポート使用

### 7.3 推奨リソース

- メモリ: 4GB以上（Playwright使用時）
- CPU: 2コア以上
- ディスク: 5GB以上の空き容量

## 8. テスト結果の活用

### 8.1 Docker Container テスト結果

- エラーログの分析
- レスポンス内容の確認
- パフォーマンス測定

### 8.2 E2E テスト結果

- HTMLレポートでの詳細分析
- 失敗時のスクリーンショット・動画確認
- 複数ブラウザでの互換性確認

### 8.3 継続的改善

- テストケースの追加・改良
- パフォーマンス目標値の設定
- 新機能実装時のテスト拡張

---

このガイドに従って、BigQuery Adminerドライバーの品質を継続的に保証・向上させることができます。