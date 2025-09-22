# E2Eテスト実行ガイド - BigQuery Adminer Plugin

## 概要
i03.md #4で確立されたE2Eテスト手法の完全マニュアル。
スクリプト指定型のコンテナを使用した自動テスト実行システム。

## テスト環境構成

### アーキテクチャ
```
container/
├── web/                    # Webアプリケーション環境
│   ├── compose.yml         # Adminerサービス（ポート8080）
│   └── Dockerfile          # BigQueryドライバー組み込み
└── e2e/                    # E2Eテスト環境
    ├── compose.yml         # Playwrightテストサービス
    ├── Dockerfile          # スクリプト指定実行型
    ├── scripts/            # テスト実行スクリプト
    │   ├── reference-test.sh   # 参照系テスト
    │   ├── crud-test.sh       # 更新系テスト
    │   └── all-tests.sh       # 全テスト
    ├── tests/              # Playwrightテストファイル
    ├── run-reference-tests.sh  # 参照系実行（ホスト側）
    ├── run-crud-tests.sh      # 更新系実行（ホスト側）
    └── run-all-tests.sh       # 全テスト実行（ホスト側）
```

### ネットワーク構成
- **adminer_net**: Docker Compose外部ネットワーク
- **adminer-bigquery-test**: WebコンテナでAdminerが起動（localhost:8080）
- **playwright-e2e**: E2EテストコンテナからWebコンテナに接続

## テスト実行方法

### 前提条件
1. Web環境が起動している必要があります
```bash
cd container/web
docker compose up -d
```

2. 環境変数設定確認
- `GOOGLE_CLOUD_PROJECT`: BigQueryプロジェクトID
- `GOOGLE_APPLICATION_CREDENTIALS`: サービスアカウントJSONパス

### 基本実行コマンド

#### 1. 参照系テスト（推奨：最初に実行）
```bash
cd container/e2e
./run-reference-tests.sh
```

**テスト内容:**
- ログイン・認証確認
- データセット一覧表示
- テーブル一覧表示と構造確認
- SQLクエリ実行機能
- ナビゲーション機能
- 検索・フィルタ機能（未実装機能の検出）
- エクスポート機能（未実装機能の検出）
- ページネーション機能
- エラーハンドリング確認

#### 2. 更新系テスト
```bash
cd container/e2e
./run-crud-tests.sh
```

**テスト内容:**
- データセット作成
- テーブル作成
- レコード挿入（INSERT）
- レコード更新（UPDATE）
- レコード削除（DELETE）

#### 3. 全テスト実行
```bash
cd container/e2e
./run-all-tests.sh
```

### 手動テスト実行

#### コンテナ内での直接実行
```bash
cd container/e2e
docker compose run --rm playwright-e2e reference-test.sh
docker compose run --rm playwright-e2e crud-test.sh
docker compose run --rm playwright-e2e all-tests.sh
```

#### 個別テストファイル実行
```bash
# 参照系のみ
docker compose run --rm playwright-e2e npx playwright test tests/reference-system-test.spec.js

# 更新系のみ
docker compose run --rm playwright-e2e npx playwright test tests/bigquery-crud-test.spec.js
```

## ログとレポート

### ログ保存場所
- **実行ログ**: `container/e2e/test-results/`
  - `reference_test_YYYYMMDD_HHMMSS.log`
  - `crud_test_YYYYMMDD_HHMMSS.log`
  - `all_tests_YYYYMMDD_HHMMSS.log`

### Playwrightレポート
- **HTML レポート**: `container/e2e/playwright-report/index.html`
- **スクリーンショット**: `container/e2e/test-results/`
- **ビデオ録画**: 失敗時に自動生成

## エラー対応

### よくある問題と対処法

#### 1. "Web環境が起動していません"
```bash
cd container/web
docker compose up -d
# 起動確認
docker compose ps
```

#### 2. "接続できません"
```bash
# ネットワーク確認
docker network ls | grep adminer_net

# コンテナ間通信確認
docker compose -f container/web/compose.yml exec adminer-bigquery-test curl -I http://localhost
```

#### 3. "認証エラー"
環境変数を確認してください:
```bash
# Web環境の環境変数確認
docker compose -f container/web/compose.yml exec adminer-bigquery-test printenv | grep GOOGLE
```

#### 4. "テストファイルが見つからない"
```bash
# E2E環境リビルド
cd container/e2e
docker compose build --no-cache playwright-e2e
```

## 高度な使用法

### カスタムテストスクリプト作成
```bash
# 新しいテストスクリプト作成
cat > container/e2e/scripts/custom-test.sh << 'EOF'
#!/bin/bash
echo "カスタムテスト実行"
npx playwright test tests/specific-test.spec.js --reporter=line
EOF

chmod +x container/e2e/scripts/custom-test.sh

# 実行
docker compose run --rm playwright-e2e custom-test.sh
```

### 並列テスト実行
```bash
# 複数ブラウザでの並列実行
docker compose run --rm playwright-e2e npx playwright test --workers=3
```

### デバッグモード
```bash
# ヘッドレスモード無効化
docker compose run --rm playwright-e2e npx playwright test --headed

# ステップ実行
docker compose run --rm playwright-e2e npx playwright test --debug
```

## パフォーマンス最適化

### テスト実行時間短縮
1. **必要なテストのみ実行**: 特定のspecファイル指定
2. **並列実行**: `--workers` オプション使用
3. **ブラウザ選択**: `--project=chromium` で単一ブラウザ指定

### リソース使用量最適化
1. **コンテナ削除**: `docker compose run --rm` で自動削除
2. **イメージクリーンアップ**: 定期的な `docker system prune`

## CI/CD連携

### GitHub Actions例
```yaml
- name: E2E Test
  run: |
    cd container/web
    docker compose up -d
    cd ../e2e
    ./run-all-tests.sh
```

### テスト結果の判定
- **終了コード 0**: 全テスト成功
- **終了コード 非0**: テスト失敗またはエラー
- **ログファイル**: 詳細なエラー情報

## セキュリティ注意事項

### 認証情報の管理
- サービスアカウントJSONファイルは`.gitignore`に追加
- 環境変数での認証情報設定を推奨
- 本番環境の認証情報は使用しない

### ネットワークセキュリティ
- テスト環境は隔離されたDockerネットワークを使用
- 外部への不要なアクセスは制限

## トラブルシューティング

### ログ分析方法
1. **実行ログ確認**: タイムスタンプ付きログで実行過程を追跡
2. **Playwrightレポート**: ブラウザでの操作詳細を確認
3. **スクリーンショット**: 失敗時の画面状態を確認

### 問題報告
問題が発生した場合は以下の情報を含めて報告:
- 実行コマンド
- エラーメッセージ
- ログファイルの該当部分
- 環境情報（Docker バージョン、OS等）

---

## 重要な注意事項

⚠️ **必須**: テスト実行前にWebコンテナが起動していることを確認
⚠️ **推奨**: 参照系テストを先に実行し、未実装機能を特定
⚠️ **注意**: 更新系テストは新規データセット・テーブルで実行

この手法により、BigQueryドライバーの全機能を体系的にテストできます。