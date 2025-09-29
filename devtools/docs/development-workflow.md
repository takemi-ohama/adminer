# BigQuery Adminer 開発ワークフロー

## 📁 プロジェクト構造 (2025-09更新)

新しいディレクトリ構造により、開発・テスト・運用の各フェーズが明確に分離されました。

```
adminer/
├── adminer/           # Adminerコア
├── plugins/           # プラグイン群
├── container/         # コンテナ関連（役割別分離）
│   ├── dev/          # 開発環境
│   ├── web/          # Webアプリケーション
│   └── e2e/          # E2Eテスト環境
├── container/docs/   # ドキュメント
└── container/issues/ # プロジェクト管理
```

## 🚀 開発環境セットアップ

### 1. 初回セットアップ

```bash
# プロジェクトクローン
git clone <repository>
cd adminer

# Docker network作成
docker network create adminer-net

# Google Cloud認証設定
# /home/hammer/google_credential.json に認証ファイル配置
```

### 2. 開発環境起動

```bash
# Webアプリケーション起動
cd devtools/web
docker compose up --build -d

# ブラウザでアクセス
open http://localhost:8080
```

## 🔧 開発フロー

### コード変更時の基本フロー

```bash
# 1. コード修正
# 2. Webコンテナ再ビルド・起動
cd devtools/web
docker compose down
docker compose up --build -d

# 3. 基本動作確認
curl -I http://localhost:8080

# 4. E2Eテスト実行
cd ../e2e
./run-e2e-tests.sh

# 5. 必要に応じてモンキーテスト
./run-monkey-test.sh
```

## 🧪 テスト戦略

### 1. 開発中の迅速テスト

```bash
# Docker Container テスト（高速）
cd devtools/web
docker exec adminer-bigquery-test curl -I "http://localhost/"
```

### 2. 包括的テスト

```bash
# E2Eテスト（推奨）
cd container/e2e
./run-e2e-tests.sh
```

### 3. 安定性テスト

```bash
# モンキーテスト
cd container/e2e
./run-monkey-test.sh
```

## 📦 ビルド・デプロイ

### 1. 本番用ビルド

```bash
# Webアプリケーション
cd devtools/web
docker compose build --no-cache
```

### 2. テストレポート生成

```bash
cd container/e2e
./run-e2e-tests.sh

# HTMLレポート表示
docker compose run --rm playwright-e2e npm run test:report
```

## 🔄 CI/CD統合

### GitHub Actions例

```yaml
name: BigQuery Adminer CI

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup Docker Network
        run: docker network create adminer-net

      - name: Build and Start Web Application
        run: |
          cd devtools/web
          docker compose up --build -d

      - name: Run E2E Tests
        run: |
          cd container/e2e
          ./run-e2e-tests.sh

      - name: Run Monkey Tests
        run: |
          cd container/e2e
          ./run-monkey-test.sh
```

## 📁 ディレクトリ別詳細

### devtools/web/
**役割**: Adminer Webアプリケーション関連
- `compose.yml`: Adminerサービス定義
- `Dockerfile`: Webコンテナ設定
- `index.php`: Adminer設定
- `plugins/`: Webアプリケーション用プラグイン

**主な操作**:
```bash
cd devtools/web
docker compose up -d      # 起動
docker compose down       # 停止
docker compose logs       # ログ確認
```

### container/e2e/
**役割**: E2Eテスト環境
- `compose.yml`: Playwrightテストサービス定義
- `Dockerfile`: E2Eテストコンテナ設定
- `tests/`: Playwrightテストスクリプト
- `run-*.sh`: テスト実行スクリプト

**主な操作**:
```bash
cd container/e2e
./run-e2e-tests.sh       # E2Eテスト実行
./run-monkey-test.sh     # モンキーテスト実行
```

### container/dev/
**役割**: 開発環境専用設定
- 開発者向けの設定やスクリプト

## 🐛 トラブルシューティング

### よくある問題と解決方法

#### 1. Adminerコンテナが起動しない
```bash
# ログ確認
cd devtools/web
docker compose logs adminer-bigquery-test

# 強制再ビルド
docker compose down
docker compose up --build -d
```

#### 2. E2Eテストが失敗する
```bash
# Adminerの起動状態確認
docker ps | grep adminer-bigquery-test

# ネットワーク確認
docker network ls | grep adminer-net

# テストログ詳細表示
cd container/e2e
DEBUG=pw:* ./run-e2e-tests.sh
```

#### 3. Google Cloud認証エラー
```bash
# 認証ファイル確認
ls -la /home/hammer/google_credential.json

# 環境変数確認
docker exec adminer-bigquery-test printenv | grep GOOGLE
```

## 📊 開発効率化Tips

### 1. 高速開発サイクル
- Docker Container テストを活用した迅速な動作確認
- E2Eテストは重要な変更時のみ実行

### 2. デバッグ効率化
```bash
# Webコンテナ内での直接デバッグ
docker exec -it adminer-bigquery-test bash

# リアルタイムログ監視
docker compose logs -f adminer-bigquery-test
```

### 3. テスト結果の活用
```bash
# テストレポート自動表示
cd container/e2e
./run-e2e-tests.sh && docker compose run --rm playwright-e2e npm run test:report
```

## 🔧 カスタマイズ

### 新しいE2Eテスト追加
```bash
cd container/e2e/tests
# 新しい .spec.js ファイル作成
# 既存のテストを参考にして実装
```

### 新しいプラグイン追加
```bash
cd plugins
# 新しいプラグイン実装
cd ../devtools/web
# compose.yml の volume設定確認・更新
```

---

この新しいワークフローにより、開発・テスト・運用の各段階が明確に分離され、保守性と開発効率が大幅に向上しています。