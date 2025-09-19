# Adminer BigQuery Testing Comprehensive Guide

## テスト実行方法の体系的まとめ

### 1. テスト種別と使い分け

#### Docker Container テスト (curl-based)
- **用途**: 基本動作確認、CI/CD、API レベルテスト
- **特徴**: 軽量、高速、自動化容易
- **実行**: `docker exec` + `curl` コマンド

#### Playwright E2E テスト (browser-based)
- **用途**: UX検証、ブラウザ互換性、視覚的確認
- **特徴**: 実際のユーザー操作シミュレーション
- **実行**: `./run-e2e-tests.sh`

### 2. Docker Container テスト詳細手順

#### 基本セットアップ
```bash
cd container/tests
docker compose up --build -d
```

#### 認証セッション確立
```bash
# Cookieファイル作成 + ログイン
docker exec adminer-bigquery-test bash -c '
  curl -s -c /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=" \
    -d "auth[driver]=bigquery&auth[server]=nyle-carmo-analysis&auth[username]=&auth[password]=&auth[db]=" \
    -X POST
'
```

#### 機能別テストURL体系
1. **ログイン**: `/?bigquery=PROJECT&username=`
2. **データセット一覧**: `/?bigquery=PROJECT&username=`
3. **テーブル一覧**: `/?bigquery=PROJECT&username=&db=DATASET`
4. **テーブル構造**: `/?bigquery=PROJECT&username=&db=DATASET&table=TABLE`
5. **データ選択**: `/?bigquery=PROJECT&username=&db=DATASET&select=TABLE`

#### エラー検出パターン
```bash
# Fatal Error検出コマンド
curl ... | grep -E "(Fatal error|TypeError|Error|Exception|Warning)" | head -3
```

### 3. Playwright E2E テスト詳細手順

#### 簡単実行 (推奨)
```bash
cd container/tests
./run-e2e-tests.sh
```

#### 手動実行
```bash
# 1. Adminerコンテナ起動
docker compose up -d adminer-bigquery-test

# 2. E2Eテスト実行
docker compose --profile e2e run --rm playwright-e2e npm test
```

#### ブラウザ別・モード別実行
```bash
# Chromium単体
docker compose --profile e2e run --rm playwright-e2e npm run test:chromium

# ヘッド付きモード
docker compose --profile e2e run --rm playwright-e2e npm run test:headed

# デバッグモード
docker compose --profile e2e run --rm playwright-e2e npm run test:debug
```

#### テストレポート
```bash
# HTMLレポート表示
docker compose --profile e2e run --rm playwright-e2e npm run test:report
```

### 4. テストケース構成

#### Basic Tests (bigquery-basic.spec.js)
1. Login Page Load - ログインページ読み込み
2. BigQuery Authentication - 認証処理
3. Dataset Display - データセット表示
4. Table Listing - テーブル一覧
5. Table Structure - テーブル構造表示
6. Data Selection Access - データ選択画面

#### Advanced Tests (bigquery-advanced.spec.js)
1. Multiple Datasets - 複数データセット処理
2. Table Information - テーブル詳細情報
3. Schema Display - スキーマ詳細表示
4. Navigation Links - ナビゲーション機能
5. Error Handling - エラー処理
6. Session Management - セッション管理
7. Page Structure - ページ構造検証

### 5. 成功判定基準

#### Docker Container テスト
- エラーメッセージの非存在 (grep結果が空)
- 期待するHTMLコンテンツの存在
- 正常なHTTPステータスコード

#### E2E テスト
- 全テストケースのPASS
- スクリーンショット・動画での視覚確認
- HTMLレポートでの詳細分析

### 6. トラブルシューティング体系

#### 認証問題
```bash
# 認証ファイル確認
ls -la /home/hammer/google_credential.json

# 環境変数確認
docker exec adminer-bigquery-test printenv | grep GOOGLE
```

#### コンテナ問題
```bash
# ログ確認
docker compose logs adminer-bigquery-test

# 強制再起動
docker compose down && docker compose up --build -d
```

#### E2E特有問題
```bash
# 詳細ログ
DEBUG=pw:* docker compose --profile e2e run --rm playwright-e2e npm test

# ネットワーク確認
docker network ls | grep adminer
```

### 7. 開発ワークフロー統合

#### 日常開発サイクル
1. **コード修正**
2. **Docker Container基本テスト** (1-2分)
3. **問題なければE2Eテスト** (5-10分)
4. **全テストPASSでcommit**

#### CI/CD統合
```yaml
# GitHub Actions統合例
- name: Test BigQuery Driver
  run: |
    cd container/tests
    docker compose up --build -d adminer-bigquery-test
    ./run-e2e-tests.sh
```

### 8. Docker環境構成

#### コンテナ構成
- `adminer-bigquery-test`: メインアプリケーション
- `playwright-e2e`: E2Eテスト実行環境 (profile: e2e)

#### ネットワーク
- `adminer-net`: Docker外部ネットワーク
- コンテナ間通信: `http://adminer-bigquery-test`

#### ボリュームマウント
- 認証ファイル: `/home/hammer/google_credential.json`
- テストファイル: `./e2e:/app/tests`
- 結果出力: `./test-results`, `./playwright-report`

### 9. テスト結果活用

#### Docker Container テスト
- API レベルの動作確認
- パフォーマンス測定
- エラーログ分析

#### E2E テスト
- ユーザーエクスペリエンス検証
- ブラウザ互換性確認
- 視覚的回帰テスト

### 10. 効率化のポイント

#### セッション管理
- Cookieファイル再利用でログイン省略
- 複数テストでのセッション共有

#### 段階的テスト
1. 基本機能テスト (curl)
2. 問題なければ包括テスト (E2E)
3. 特定ブラウザでの詳細確認

#### 自動化
- `run-e2e-tests.sh` で1コマンド実行
- CI/CD統合での自動テスト
- テスト結果の自動保存・通知

この体系的なテスト手順により、BigQuery Adminerドライバーの品質を効率的に保証できる。