# DooD環境でのテストコンテナ操作手順

## 1. 概要

このドキュメントでは、Docker-outside-of-Docker (DooD) 環境での BigQuery ドライバーテストコンテナの操作手順を説明します。Claude Code が稼働する `adminer-dev-1` コンテナから、テスト用の `adminer-bigquery-test` コンテナを操作する際の詳細な手順を提供します。

## 2. DooD環境の構成

### 2.1 環境構成図

```
Host System (~/google_credential.json)
├── adminer-dev-1 (Claude Code実行環境)
│   ├── /etc/google_credentials.json (マウント済み)
│   └── /home/ubuntu/work/adminer (プロジェクト)
│       └── container/tests/ (テスト環境定義)
└── adminer-bigquery-test (テスト対象コンテナ)
    ├── Port: 8080 → Host
    └── Network: adminer-net
```

### 2.2 重要な認証ファイルパス

| 環境 | パス | 説明 |
|------|------|------|
| ホスト | `~/google_credential.json` | 元の認証ファイル |
| Claude Code環境 | `/etc/google_credentials.json` | マウント済み認証ファイル |
| テストコンテナ | `/etc/google_credentials.json` | ボリュームマウントされた認証ファイル |

## 3. 事前確認と準備

### 3.1 現在の環境確認

```bash
# Claude Codeの実行環境確認
echo "Current container: $(hostname)"
pwd
ls -la /etc/google_credentials.json

# Docker daemon接続確認
docker version
docker ps
```

### 3.2 プロジェクト構成確認

```bash
# プロジェクトディレクトリ移動
cd /home/ubuntu/work/adminer

# 必要ファイルの存在確認
ls -la container/tests/
ls -la container/tests/compose.yml
ls -la container/tests/Dockerfile
```

### 3.3 既存ネットワークの確認

```bash
# adminer-netネットワークの確認
docker network ls | grep adminer-net

# ネットワークが存在しない場合は作成
if ! docker network ls --format "{{.Name}}" | grep -q "^adminer-net$"; then
    echo "Creating adminer-net network..."
    docker network create adminer-net
fi
```

## 4. テストコンテナの操作

### 4.1 テストコンテナの起動

#### 基本起動手順

```bash
# テスト環境ディレクトリに移動
cd /home/ubuntu/work/adminer/container/tests

# 既存コンテナの確認と停止
docker ps -a --filter "name=adminer-bigquery-test"

# 既存コンテナが動作中の場合は停止
docker-compose down

# テストコンテナの起動
docker-compose up -d

# 起動確認
docker-compose ps
```

#### 詳細な起動ログ確認

```bash
# ビルドログ付きで起動
docker-compose up -d --build

# リアルタイムログ確認
docker-compose logs -f

# 特定サービスのログのみ
docker-compose logs adminer-bigquery-test
```

### 4.2 コンテナの状態確認

#### ヘルスチェック

```bash
# コンテナの稼働状況
docker-compose ps

# ヘルスチェック状況
docker inspect adminer-bigquery-test --format='{{.State.Health.Status}}'

# 詳細なヘルス情報
docker inspect adminer-bigquery-test | jq '.[] | .State.Health'
```

#### ネットワーク接続確認

```bash
# ポートマッピング確認
docker port adminer-bigquery-test

# ネットワーク設定確認
docker inspect adminer-bigquery-test --format='{{.NetworkSettings.Networks}}'

# DooD環境での接続テスト（外部からのアクセス）
curl -I http://adminer-bigquery-test:80
```

### 4.3 接続テストとデバッグ

#### HTTP接続テスト

```bash
# 基本的な接続テスト
curl -f http://adminer-bigquery-test:80

# レスポンスヘッダー確認
curl -I http://adminer-bigquery-test:80

# HTMLレスポンスの一部確認
curl -s http://adminer-bigquery-test:80 | head -20

# デバッグ情報表示
curl -s http://adminer-bigquery-test:80?debug=1
```

#### BigQuery接続のテスト

```bash
# テスト用クエリページにアクセス
curl -s "http://adminer-bigquery-test:80?test=1" | grep -A 10 "テスト環境情報"

# ログイン画面の確認
curl -s http://adminer-bigquery-test:80 | grep -i "project\|bigquery\|credentials"
```

### 4.4 コンテナ内部の調査

#### コンテナ内部へのアクセス

```bash
# コンテナ内部にアクセス
docker exec -it adminer-bigquery-test /bin/bash

# ファイル構成確認
docker exec adminer-bigquery-test ls -la /var/www/html/

# 認証ファイル確認
docker exec adminer-bigquery-test ls -la /etc/google_credentials.json

# PHP設定確認
docker exec adminer-bigquery-test php -i | grep -i google
```

#### ログファイルの確認

```bash
# Apache エラーログ
docker exec adminer-bigquery-test tail -f /var/log/apache2/error.log

# Apache アクセスログ
docker exec adminer-bigquery-test tail -f /var/log/apache2/access.log

# PHP エラーログ
docker exec adminer-bigquery-test tail -f /var/log/php_errors.log
```

### 4.5 設定の動的変更

#### 環境変数の変更

```bash
# 環境変数の確認
docker exec adminer-bigquery-test env | grep -i bigquery

# 環境変数の一時的な変更（再起動で元に戻る）
docker exec adminer-bigquery-test bash -c 'export GOOGLE_CLOUD_PROJECT=new-project && env | grep GOOGLE_CLOUD'

# Docker Composeでの環境変数変更
# container/tests/compose.yml を編集後
docker-compose up -d --force-recreate
```

#### 設定ファイルの変更

```bash
# PHP設定の確認
docker exec adminer-bigquery-test php --ini

# 設定ファイルの一時的な変更
docker exec adminer-bigquery-test bash -c '
echo "memory_limit = 1G" >> /usr/local/etc/php/conf.d/custom.ini
'

# Apache設定の確認
docker exec adminer-bigquery-test apache2ctl -S
```

## 5. 実際のテスト手順

### 5.1 基本機能テスト

#### ログインテスト

```bash
# テスト用のログインデータを準備
cat > /tmp/login_test.sh << 'EOF'
#!/bin/bash
curl -X POST http://adminer-bigquery-test:80 \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "auth[driver]=bigquery" \
  -d "auth[server]=nyle-carmo-analysis" \
  -d "auth[username]=" \
  -d "auth[password]=/etc/google_credentials.json" \
  -d "auth[db]=" \
  -c /tmp/cookies.txt \
  -L
EOF

chmod +x /tmp/login_test.sh
/tmp/login_test.sh
```

#### データセット一覧取得テスト

```bash
# ログイン後のデータセット一覧画面
curl -b /tmp/cookies.txt http://adminer-bigquery-test:80 | grep -i dataset

# JSON形式でのAPI レスポンス確認（もしあれば）
curl -b /tmp/cookies.txt \
  -H "Accept: application/json" \
  http://adminer-bigquery-test:80
```

### 5.2 エラー処理テスト

#### 不正な認証情報でのテスト

```bash
# 存在しない認証ファイルでのテスト
curl -X POST http://adminer-bigquery-test:80 \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "auth[driver]=bigquery" \
  -d "auth[server]=nyle-carmo-analysis" \
  -d "auth[password]=/nonexistent/credentials.json"
```

#### 不正なプロジェクトIDでのテスト

```bash
# 存在しないプロジェクトIDでのテスト
curl -X POST http://adminer-bigquery-test:80 \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "auth[driver]=bigquery" \
  -d "auth[server]=nonexistent-project-id" \
  -d "auth[password]=/etc/google_credentials.json"
```

### 5.3 パフォーマンステスト

#### レスポンス時間測定

```bash
# 基本的なレスポンス時間
time curl -s http://adminer-bigquery-test:80 > /dev/null

# 詳細な時間測定
curl -w "Total time: %{time_total}s\nConnect time: %{time_connect}s\n" \
  -s -o /dev/null http://adminer-bigquery-test:80

# 複数回実行での平均測定
for i in {1..5}; do
  curl -w "%{time_total}\n" -s -o /dev/null http://adminer-bigquery-test:80
done | awk '{sum+=$1} END {print "Average: " sum/NR "s"}'
```

#### リソース使用量監視

```bash
# CPU・メモリ使用量の監視
docker stats adminer-bigquery-test --no-stream

# 継続監視（別ターミナルで実行）
docker stats adminer-bigquery-test

# ディスク使用量
docker exec adminer-bigquery-test df -h
```

## 6. トラブルシューティング

### 6.1 よくある問題と解決方法

#### 問題1: コンテナが起動しない

```bash
# エラー詳細の確認
docker-compose logs adminer-bigquery-test

# コンテナの詳細状態確認
docker inspect adminer-bigquery-test | jq '.[0].State'

# ポート競合の確認
ss -tlnp | grep :8080
netstat -tlnp | grep :8080

# 解決手順
docker-compose down
docker system prune -f
docker-compose up -d --build
```

#### 問題2: ネットワーク接続エラー

```bash
# ネットワーク設定の確認
docker network inspect adminer-net

# コンテナのネットワーク接続確認
docker exec adminer-bigquery-test ping -c 3 google.com

# DNS設定確認
docker exec adminer-bigquery-test nslookup bigquery.googleapis.com

# 解決手順
docker network prune
docker-compose down
docker network create adminer-net
docker-compose up -d
```

#### 問題3: 認証ファイルが読めない

```bash
# ファイルの存在と権限確認
ls -la /etc/google_credentials.json
docker exec adminer-bigquery-test ls -la /etc/google_credentials.json

# ファイル内容の確認（セキュリティに注意）
docker exec adminer-bigquery-test head -5 /etc/google_credentials.json

# 解決手順
# ホスト側での権限調整（Claude Code環境では実行不可の場合があります）
chmod 644 /etc/google_credentials.json
```

### 6.2 デバッグ手法

#### ログレベルの調整

```bash
# PHP デバッグモードの有効化
docker exec adminer-bigquery-test bash -c '
echo "display_errors = On" >> /usr/local/etc/php/conf.d/debug.ini
echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/debug.ini
'

# Apache ログレベル変更
docker exec adminer-bigquery-test bash -c '
echo "LogLevel debug" >> /etc/apache2/apache2.conf
'

# 設定反映
docker exec adminer-bigquery-test apache2ctl graceful
```

#### ネットワークトラフィックの監視

```bash
# コンテナ内からの外部通信確認
docker exec adminer-bigquery-test bash -c '
curl -v https://bigquery.googleapis.com 2>&1 | head -20
'

# Docker ネットワークでのパケット監視（可能な場合）
docker run --rm --net container:adminer-bigquery-test \
  nicolaka/netshoot tcpdump -i eth0 -n
```

### 6.3 パフォーマンス最適化

#### メモリ使用量の最適化

```bash
# 現在のメモリ使用量確認
docker exec adminer-bigquery-test cat /proc/meminfo

# PHP メモリ制限の調整
docker exec adminer-bigquery-test bash -c '
echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/memory.ini
'

# Apache プロセス数の最適化
docker exec adminer-bigquery-test bash -c '
echo "ServerLimit 2" >> /etc/apache2/apache2.conf
echo "MaxRequestWorkers 10" >> /etc/apache2/apache2.conf
'
```

## 7. 自動化スクリプト

### 7.1 包括的テストスクリプト

```bash
#!/bin/bash
# test-suite.sh - DooD環境での包括的テストスクリプト

set -euo pipefail

# 色付きログ出力
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_error() { echo -e "${RED}[ERROR]${NC} $*"; }

# 設定
CONTAINER_NAME="adminer-bigquery-test"
TEST_URL="http://adminer-bigquery-test:80"
CREDENTIALS_PATH="/etc/google_credentials.json"

# テスト結果追跡
TESTS_PASSED=0
TESTS_FAILED=0

# 個別テスト関数
test_container_running() {
    log_info "Testing: Container running status"
    if docker ps --format "{{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
        log_info "✅ Container is running"
        ((TESTS_PASSED++))
    else
        log_error "❌ Container is not running"
        ((TESTS_FAILED++))
        return 1
    fi
}

test_http_response() {
    log_info "Testing: HTTP response"
    if curl -f -s --max-time 10 "$TEST_URL" > /dev/null; then
        log_info "✅ HTTP endpoint responding"
        ((TESTS_PASSED++))
    else
        log_error "❌ HTTP endpoint not responding"
        ((TESTS_FAILED++))
        return 1
    fi
}

test_credentials_file() {
    log_info "Testing: Credentials file accessibility"
    if docker exec "$CONTAINER_NAME" test -r "$CREDENTIALS_PATH"; then
        log_info "✅ Credentials file is accessible"
        ((TESTS_PASSED++))
    else
        log_error "❌ Credentials file not accessible"
        ((TESTS_FAILED++))
        return 1
    fi
}

test_bigquery_login() {
    log_info "Testing: BigQuery login functionality"

    local response=$(curl -s -X POST "$TEST_URL" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "auth[driver]=bigquery" \
        -d "auth[server]=nyle-carmo-analysis" \
        -d "auth[password]=$CREDENTIALS_PATH")

    if echo "$response" | grep -qi "dataset\|database"; then
        log_info "✅ BigQuery login successful"
        ((TESTS_PASSED++))
    else
        log_warn "⚠️  BigQuery login may have failed (check manually)"
        ((TESTS_FAILED++))
    fi
}

test_php_configuration() {
    log_info "Testing: PHP configuration"

    local php_info=$(docker exec "$CONTAINER_NAME" php -i)

    if echo "$php_info" | grep -q "memory_limit"; then
        log_info "✅ PHP configuration accessible"
        ((TESTS_PASSED++))
    else
        log_error "❌ PHP configuration issue"
        ((TESTS_FAILED++))
        return 1
    fi
}

# メインテスト実行
run_all_tests() {
    log_info "Starting DooD environment test suite..."
    log_info "Target: $TEST_URL"
    echo ""

    # 前提条件確認
    if ! command -v docker &> /dev/null; then
        log_error "Docker not available in this environment"
        exit 1
    fi

    # テスト実行
    test_container_running || true
    test_http_response || true
    test_credentials_file || true
    test_bigquery_login || true
    test_php_configuration || true

    # 結果サマリー
    echo ""
    log_info "Test Results Summary:"
    echo "✅ Passed: $TESTS_PASSED"
    echo "❌ Failed: $TESTS_FAILED"
    echo "📊 Total:  $((TESTS_PASSED + TESTS_FAILED))"

    if [ "$TESTS_FAILED" -eq 0 ]; then
        log_info "🎉 All tests passed!"
        exit 0
    else
        log_error "💥 Some tests failed. Check the output above."
        exit 1
    fi
}

# スクリプト実行
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    cd /home/ubuntu/work/adminer/container/tests
    run_all_tests
fi
```

### 7.2 継続監視スクリプト

```bash
#!/bin/bash
# monitor-dood.sh - DooD環境でのテストコンテナ監視

CONTAINER_NAME="adminer-bigquery-test"
MONITOR_INTERVAL=30
LOG_FILE="/tmp/dood-monitor.log"

monitor_container() {
    while true; do
        {
            echo "=== Monitor Report $(date) ==="

            # コンテナ状態
            if docker ps --format "{{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
                echo "Container Status: ✅ Running"

                # リソース使用量
                docker stats "$CONTAINER_NAME" --no-stream --format \
                    "CPU: {{.CPUPerc}} | Memory: {{.MemUsage}} | Net I/O: {{.NetIO}}"

                # HTTP応答チェック
                if curl -f -s --max-time 5 http://adminer-bigquery-test:80 > /dev/null; then
                    echo "HTTP Status: ✅ Responding"
                else
                    echo "HTTP Status: ❌ Not responding"
                fi

            else
                echo "Container Status: ❌ Not running"
            fi

            echo ""
        } | tee -a "$LOG_FILE"

        sleep "$MONITOR_INTERVAL"
    done
}

# バックグラウンド実行
if [[ "$1" == "--daemon" ]]; then
    monitor_container &
    echo "Monitor started in background (PID: $!)"
    echo "$!" > /tmp/dood-monitor.pid
else
    monitor_container
fi
```

## 8. ベストプラクティス

### 8.1 DooD環境での注意事項

#### セキュリティ考慮事項
- 認証ファイルのパーミッション管理に注意
- コンテナ間での機密情報の適切な共有
- ネットワーク設定でのセキュリティ境界の維持

#### パフォーマンス最適化
- 不要なコンテナの定期的なクリーンアップ
- ログファイルサイズの監視と回転
- リソース制限の適切な設定

#### 運用管理
- 定期的な接続テストの実行
- エラーログの監視と分析
- バックアップ・リストア手順の確立

### 8.2 推奨運用手順

```bash
# 日次チェック項目
1. docker ps | grep adminer-bigquery-test
2. curl -f http://adminer-bigquery-test:80
3. docker logs --tail 20 adminer-bigquery-test
4. df -h  # ディスク容量確認

# 週次メンテナンス
1. docker-compose down && docker-compose up -d
2. docker system prune -f
3. テストスクリプトの実行

# 問題発生時の対応
1. ログの確認と保存
2. 設定ファイルのバックアップ
3. 段階的なトラブルシューティング実行
```

---

このガイドに従って、DooD環境でのBigQueryドライバーテストコンテナを効率的に操作・管理してください。