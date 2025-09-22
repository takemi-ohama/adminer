# BigQuery ドライバーコンテナの設定手順と起動方法

## 1. 概要

このドキュメントでは、Adminer BigQuery ドライバーコンテナの設定から起動まで、運用担当者向けの詳細な手順を説明します。システム管理者やDevOpsエンジニアが本格的な運用環境でコンテナを展開する際の実践的なガイドです。

## 2. 事前準備

### 2.1 必要なツールとサービス

#### ローカル環境
```bash
# 必須ツール
- Docker: 20.10.0 以上
- Docker Compose: 2.0.0 以上
- curl: データ転送確認用
- jq: JSON処理用（推奨）

# 確認コマンド
docker --version
docker-compose --version
curl --version
jq --version
```

#### クラウド環境
```bash
# Google Cloud Platform
- アクティブなGCPプロジェクト
- BigQuery API の有効化
- サービスアカウントと認証キー
- 適切なIAM権限

# 確認コマンド
gcloud config list
gcloud services list --enabled --filter="name:bigquery"
```

### 2.2 ネットワーク要件

#### ポート設定
```bash
# 必須ポート
- 80/tcp   : HTTP (コンテナ内)
- 8080/tcp : HTTP (ホスト側、カスタマイズ可能)

# オプション
- 443/tcp  : HTTPS (SSL終端処理時)
- 9003/tcp : Xdebug (開発時)
```

#### アウトバウンド接続
```bash
# Google Cloud APIs
- bigquery.googleapis.com:443
- oauth2.googleapis.com:443
- www.googleapis.com:443

# Container Registry (イメージ取得時)
- gcr.io:443
- docker.io:443
```

### 2.3 セキュリティ要件

#### ファイルシステム権限
```bash
# 認証ファイル用ディレクトリ
mkdir -p /etc/adminer/secrets
chmod 700 /etc/adminer/secrets

# ログディレクトリ
mkdir -p /var/log/adminer
chmod 755 /var/log/adminer

# 設定ファイル用ディレクトリ
mkdir -p /etc/adminer/config
chmod 755 /etc/adminer/config
```

## 3. プロジェクト構成とセットアップ

### 3.1 プロジェクト構造の準備

```bash
# プロジェクトディレクトリの作成
mkdir -p adminer-bigquery-deployment
cd adminer-bigquery-deployment

# 必要なディレクトリ構成
mkdir -p {config,secrets,logs,scripts,docker}

# 最終的なディレクトリ構造
tree .
.
├── config/                 # 設定ファイル
├── secrets/               # 認証情報（.gitignore対象）
├── logs/                  # ログファイル
├── scripts/              # 運用スクリプト
├── docker/               # Docker関連ファイル
├── docker-compose.yml    # メイン構成
├── .env                  # 環境設定
└── README.md             # 運用手順
```

### 3.2 環境設定ファイルの作成

#### .env ファイル
```bash
# .env - 本番環境用設定
# ==============================

# プロジェクト基本情報
PROJECT_NAME=adminer-bigquery
ENVIRONMENT=production
VERSION=latest

# BigQuery設定
GOOGLE_CLOUD_PROJECT=your-gcp-project-id
BIGQUERY_LOCATION=US
BIGQUERY_DATASET_DEFAULT=your_default_dataset

# コンテナ設定
CONTAINER_NAME=adminer-bigquery-prod
RESTART_POLICY=unless-stopped

# ネットワーク設定
HOST_PORT=8080
INTERNAL_PORT=80
NETWORK_NAME=bigquery_network
SUBNET=172.20.0.0/16

# ボリューム設定
SECRETS_PATH=/etc/adminer/secrets
CONFIG_PATH=/etc/adminer/config
LOGS_PATH=/var/log/adminer

# セキュリティ設定
RUN_AS_USER=1001
RUN_AS_GROUP=1001

# リソース制限
MEMORY_LIMIT=1g
CPU_LIMIT=1.0
MEMORY_RESERVATION=512m
CPU_RESERVATION=0.5

# ヘルスチェック設定
HEALTH_CHECK_INTERVAL=30s
HEALTH_CHECK_TIMEOUT=10s
HEALTH_CHECK_RETRIES=3
HEALTH_CHECK_START_PERIOD=60s

# ログ設定
LOG_DRIVER=json-file
LOG_MAX_SIZE=100m
LOG_MAX_FILE=3
```

#### 開発環境用設定
```bash
# .env.development - 開発環境用設定
# ===================================

# 継承: .env の基本設定を引き継ぎ
include .env

# 開発環境用オーバーライド
ENVIRONMENT=development
VERSION=dev
CONTAINER_NAME=adminer-bigquery-dev

# ポート設定（競合回避）
HOST_PORT=8081

# デバッグ設定
XDEBUG_ENABLE=true
XDEBUG_HOST=host.docker.internal
XDEBUG_PORT=9003

# リソース制限（緩和）
MEMORY_LIMIT=2g
CPU_LIMIT=2.0

# ログ設定（詳細化）
LOG_LEVEL=debug
PHP_DISPLAY_ERRORS=On
PHP_ERROR_REPORTING=E_ALL
```

## 4. Docker Compose 設定

### 4.1 本番環境用 docker-compose.yml

```yaml
version: '3.8'

# ==============================================================================
# Adminer BigQuery - Production Configuration
# ==============================================================================

services:
  adminer-bigquery:
    image: ${PROJECT_NAME}:${VERSION}
    container_name: ${CONTAINER_NAME}
    restart: ${RESTART_POLICY}

    # ポート設定
    ports:
      - "${HOST_PORT}:${INTERNAL_PORT}"

    # 環境変数
    environment:
      # BigQuery設定
      - GOOGLE_CLOUD_PROJECT=${GOOGLE_CLOUD_PROJECT}
      - BIGQUERY_LOCATION=${BIGQUERY_LOCATION}
      - BIGQUERY_DATASET_DEFAULT=${BIGQUERY_DATASET_DEFAULT}

      # PHP設定
      - PHP_MEMORY_LIMIT=${MEMORY_LIMIT}
      - PHP_MAX_EXECUTION_TIME=300

      # セキュリティ設定
      - PHP_EXPOSE_PHP=Off
      - PHP_DISPLAY_ERRORS=Off

      # アプリケーション設定
      - ENVIRONMENT=${ENVIRONMENT}

    # シークレット管理
    secrets:
      - source: google_credentials
        target: /etc/google_credentials.json
        uid: '${RUN_AS_USER}'
        gid: '${RUN_AS_GROUP}'
        mode: 0600

    # ボリュームマウント
    volumes:
      # ログ永続化
      - type: bind
        source: ${LOGS_PATH}
        target: /var/log/apache2

      # 設定ファイル（読み取り専用）
      - type: bind
        source: ${CONFIG_PATH}
        target: /etc/adminer
        read_only: true

    # リソース制限
    deploy:
      resources:
        limits:
          cpus: '${CPU_LIMIT}'
          memory: ${MEMORY_LIMIT}
        reservations:
          cpus: '${CPU_RESERVATION}'
          memory: ${MEMORY_RESERVATION}

    # ヘルスチェック
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:${INTERNAL_PORT}/"]
      interval: ${HEALTH_CHECK_INTERVAL}
      timeout: ${HEALTH_CHECK_TIMEOUT}
      retries: ${HEALTH_CHECK_RETRIES}
      start_period: ${HEALTH_CHECK_START_PERIOD}

    # ネットワーク設定
    networks:
      - bigquery_network

    # セキュリティ設定
    security_opt:
      - no-new-privileges:true

    # ユーザー設定
    user: "${RUN_AS_USER}:${RUN_AS_GROUP}"

    # 読み取り専用ファイルシステム
    read_only: true
    tmpfs:
      - /tmp:size=100M
      - /var/tmp:size=100M

    # ログ設定
    logging:
      driver: ${LOG_DRIVER}
      options:
        max-size: ${LOG_MAX_SIZE}
        max-file: "${LOG_MAX_FILE}"

    # 依存関係
    depends_on:
      - log-router

# ==============================================================================
# 補助サービス
# ==============================================================================

  # ログ集約サービス
  log-router:
    image: fluent/fluent-bit:latest
    container_name: ${PROJECT_NAME}-logs
    volumes:
      - ${LOGS_PATH}:/var/log/input:ro
      - ./config/fluent-bit.conf:/fluent-bit/etc/fluent-bit.conf:ro
    networks:
      - bigquery_network

# ==============================================================================
# シークレット管理
# ==============================================================================

secrets:
  google_credentials:
    file: ${SECRETS_PATH}/credentials.json

# ==============================================================================
# ボリューム設定
# ==============================================================================

volumes:
  adminer_logs:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: ${LOGS_PATH}

# ==============================================================================
# ネットワーク設定
# ==============================================================================

networks:
  bigquery_network:
    driver: bridge
    ipam:
      config:
        - subnet: ${SUBNET}
    labels:
      - "project=${PROJECT_NAME}"
      - "environment=${ENVIRONMENT}"
```

### 4.2 開発環境用 docker-compose.dev.yml

```yaml
version: '3.8'

# ==============================================================================
# Adminer BigQuery - Development Configuration
# ==============================================================================

services:
  adminer-bigquery-dev:
    extends:
      service: adminer-bigquery
      file: docker-compose.yml

    # 開発用オーバーライド
    container_name: ${CONTAINER_NAME}

    # 開発用ポート設定
    ports:
      - "${HOST_PORT}:${INTERNAL_PORT}"
      - "9003:9003"  # Xdebug

    # 開発用環境変数
    environment:
      # デバッグ設定
      - PHP_DISPLAY_ERRORS=On
      - PHP_ERROR_REPORTING=E_ALL
      - XDEBUG_MODE=debug
      - XDEBUG_CLIENT_HOST=${XDEBUG_HOST}
      - XDEBUG_CLIENT_PORT=${XDEBUG_PORT}

    # ソースコードマウント（ホットリロード）
    volumes:
      - type: bind
        source: ./src
        target: /var/www/html
      - type: volume
        source: dev_vendor
        target: /var/www/html/vendor

    # セキュリティ設定（開発用に緩和）
    read_only: false
    security_opt: []

    # 開発用コマンド
    command: >
      bash -c "
        composer install --dev &&
        apache2-foreground
      "

volumes:
  dev_vendor:
    driver: local
```

## 5. 起動スクリプト

### 5.1 本番環境起動スクリプト

```bash
#!/bin/bash
# scripts/start-production.sh - 本番環境起動スクリプト

set -euo pipefail

# ==============================================================================
# 設定
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
LOG_FILE="$PROJECT_ROOT/logs/startup.log"

# 色付きログ出力
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ==============================================================================
# ログ関数
# ==============================================================================

log() {
    local level=$1
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    echo -e "${timestamp} [${level}] ${message}" | tee -a "$LOG_FILE"
}

log_info() { log "${GREEN}INFO${NC}" "$@"; }
log_warn() { log "${YELLOW}WARN${NC}" "$@"; }
log_error() { log "${RED}ERROR${NC}" "$@"; exit 1; }
log_debug() { [[ ${DEBUG:-false} == "true" ]] && log "${BLUE}DEBUG${NC}" "$@"; }

# ==============================================================================
# 事前チェック
# ==============================================================================

preflight_checks() {
    log_info "Running preflight checks..."

    # Docker確認
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed or not in PATH"
    fi

    if ! command -v docker-compose &> /dev/null; then
        log_error "Docker Compose is not installed or not in PATH"
    fi

    # 設定ファイル確認
    if [[ ! -f "$PROJECT_ROOT/.env" ]]; then
        log_error "Environment file (.env) not found"
    fi

    if [[ ! -f "$PROJECT_ROOT/docker-compose.yml" ]]; then
        log_error "Docker Compose file not found"
    fi

    # 認証ファイル確認
    source "$PROJECT_ROOT/.env"
    if [[ ! -f "$SECRETS_PATH/credentials.json" ]]; then
        log_error "Google credentials file not found at $SECRETS_PATH/credentials.json"
    fi

    # ディレクトリ権限確認
    if [[ ! -w "$LOGS_PATH" ]]; then
        log_error "Log directory is not writable: $LOGS_PATH"
    fi

    log_info "Preflight checks completed successfully"
}

# ==============================================================================
# ネットワーク設定
# ==============================================================================

setup_network() {
    log_info "Setting up Docker network..."

    source "$PROJECT_ROOT/.env"

    # 既存ネットワークの確認
    if docker network ls --format "{{.Name}}" | grep -q "^${NETWORK_NAME}$"; then
        log_info "Network ${NETWORK_NAME} already exists"
    else
        # ネットワーク作成
        docker network create \
            --driver bridge \
            --subnet "${SUBNET}" \
            --label "project=${PROJECT_NAME}" \
            --label "environment=${ENVIRONMENT}" \
            "${NETWORK_NAME}"

        log_info "Created network: ${NETWORK_NAME}"
    fi
}

# ==============================================================================
# コンテナ起動
# ==============================================================================

start_containers() {
    log_info "Starting containers..."

    cd "$PROJECT_ROOT"

    # 既存コンテナの停止（安全な再起動）
    if docker-compose ps --quiet | grep -q .; then
        log_info "Stopping existing containers..."
        docker-compose down --timeout 30
    fi

    # コンテナビルド（必要な場合）
    if [[ ${BUILD:-false} == "true" ]]; then
        log_info "Building containers..."
        docker-compose build --no-cache
    fi

    # コンテナ起動
    log_info "Starting containers in background..."
    docker-compose up -d

    # 起動確認
    log_info "Waiting for containers to become healthy..."

    local max_attempts=30
    local attempt=1

    while [[ $attempt -le $max_attempts ]]; do
        if docker-compose ps --format "json" | jq -r '.Health' | grep -q "healthy"; then
            log_info "Containers are healthy"
            break
        fi

        log_debug "Health check attempt $attempt/$max_attempts"
        sleep 5
        ((attempt++))
    done

    if [[ $attempt -gt $max_attempts ]]; then
        log_error "Containers failed to become healthy within timeout"
    fi
}

# ==============================================================================
# 動作確認
# ==============================================================================

verify_deployment() {
    log_info "Verifying deployment..."

    source "$PROJECT_ROOT/.env"

    # HTTP接続確認
    local url="http://localhost:${HOST_PORT}"
    local max_attempts=10
    local attempt=1

    while [[ $attempt -le $max_attempts ]]; do
        if curl -f -s "$url" > /dev/null 2>&1; then
            log_info "HTTP endpoint is responding: $url"
            break
        fi

        log_debug "Connection attempt $attempt/$max_attempts to $url"
        sleep 3
        ((attempt++))
    done

    if [[ $attempt -gt $max_attempts ]]; then
        log_error "Failed to connect to $url"
    fi

    # BigQuery接続テスト（オプション）
    if [[ ${VERIFY_BIGQUERY:-false} == "true" ]]; then
        log_info "Testing BigQuery connectivity..."
        # 実際のBigQuery接続テストロジックをここに追加
    fi

    log_info "Deployment verification completed"
}

# ==============================================================================
# メイン処理
# ==============================================================================

show_status() {
    log_info "Deployment Status:"
    docker-compose ps

    source "$PROJECT_ROOT/.env"
    log_info "Application URL: http://localhost:${HOST_PORT}"
    log_info "Logs: docker-compose logs -f"
    log_info "Stop: docker-compose down"
}

cleanup_on_failure() {
    log_warn "Cleaning up after failure..."
    docker-compose down --timeout 10 || true
}

main() {
    # シグナルハンドリング
    trap cleanup_on_failure ERR

    log_info "Starting Adminer BigQuery deployment..."

    preflight_checks
    setup_network
    start_containers
    verify_deployment
    show_status

    log_info "Deployment completed successfully!"
}

# スクリプト実行
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
```

### 5.2 開発環境起動スクリプト

```bash
#!/bin/bash
# scripts/start-development.sh - 開発環境起動スクリプト

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# 開発環境設定の読み込み
export $(grep -v '^#' "$PROJECT_ROOT/.env.development" | xargs)

log_info() {
    echo -e "\033[0;32m[INFO]\033[0m $*"
}

log_warn() {
    echo -e "\033[1;33m[WARN]\033[0m $*"
}

start_development() {
    log_info "Starting development environment..."

    cd "$PROJECT_ROOT"

    # 開発用コンテナ起動
    docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

    # ログ表示
    log_info "Development environment started"
    log_info "Application: http://localhost:${HOST_PORT}"
    log_info "Xdebug: Port ${XDEBUG_PORT}"

    # ログ監視開始（オプション）
    if [[ ${FOLLOW_LOGS:-true} == "true" ]]; then
        log_info "Following container logs (Ctrl+C to exit)..."
        docker-compose logs -f
    fi
}

start_development
```

## 6. 運用管理スクリプト

### 6.1 ヘルスチェックスクリプト

```bash
#!/bin/bash
# scripts/health-check.sh - ヘルスチェックスクリプト

source "$(dirname "$0")/../.env"

check_container_health() {
    local container_name="$1"
    local health_status

    health_status=$(docker inspect --format='{{.State.Health.Status}}' "$container_name" 2>/dev/null || echo "not_found")

    case $health_status in
        "healthy")
            echo "✅ $container_name: Healthy"
            return 0
            ;;
        "unhealthy")
            echo "❌ $container_name: Unhealthy"
            return 1
            ;;
        "starting")
            echo "⏳ $container_name: Starting"
            return 2
            ;;
        "not_found")
            echo "❓ $container_name: Not found"
            return 3
            ;;
        *)
            echo "❓ $container_name: Unknown status ($health_status)"
            return 4
            ;;
    esac
}

check_http_endpoint() {
    local url="http://localhost:${HOST_PORT}"

    if curl -f -s --max-time 10 "$url" > /dev/null; then
        echo "✅ HTTP endpoint: Responding ($url)"
        return 0
    else
        echo "❌ HTTP endpoint: Not responding ($url)"
        return 1
    fi
}

check_bigquery_connectivity() {
    # BigQuery接続テスト（実装例）
    local test_query="SELECT 1 as test_connection"

    # 実際のBigQueryテストロジックをここに実装
    echo "ℹ️  BigQuery connectivity: Test skipped (implement as needed)"
}

main() {
    echo "🔍 Health Check Report - $(date)"
    echo "=================================="

    local exit_code=0

    # コンテナヘルスチェック
    check_container_health "$CONTAINER_NAME" || exit_code=$?

    # HTTPエンドポイントチェック
    check_http_endpoint || exit_code=$?

    # BigQuery接続チェック
    check_bigquery_connectivity

    # リソース使用状況
    echo ""
    echo "📊 Resource Usage:"
    docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}" "$CONTAINER_NAME"

    exit $exit_code
}

main "$@"
```

### 6.2 バックアップ・リストアスクリプト

```bash
#!/bin/bash
# scripts/backup.sh - 設定バックアップスクリプト

BACKUP_DIR="/var/backups/adminer-bigquery"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_FILE="$BACKUP_DIR/backup-$TIMESTAMP.tar.gz"

create_backup() {
    mkdir -p "$BACKUP_DIR"

    tar -czf "$BACKUP_FILE" \
        --exclude='logs/*' \
        --exclude='secrets/credentials.json' \
        .

    echo "Backup created: $BACKUP_FILE"
}

restore_backup() {
    local backup_file="$1"

    if [[ -f "$backup_file" ]]; then
        tar -xzf "$backup_file"
        echo "Backup restored from: $backup_file"
    else
        echo "Backup file not found: $backup_file"
        exit 1
    fi
}

case "${1:-backup}" in
    backup)
        create_backup
        ;;
    restore)
        restore_backup "$2"
        ;;
    *)
        echo "Usage: $0 {backup|restore backup_file}"
        exit 1
        ;;
esac
```

## 7. 監視とログ管理

### 7.1 Fluentd設定

```conf
# config/fluent-bit.conf - ログ集約設定

[SERVICE]
    Flush         5
    Log_Level     info
    Daemon        off

[INPUT]
    Name              tail
    Path              /var/log/input/apache2/*.log
    Parser            apache
    Tag               apache.access

[INPUT]
    Name              tail
    Path              /var/log/input/bigquery/*.log
    Parser            json
    Tag               bigquery.application

[FILTER]
    Name              grep
    Match             apache.access
    Regex             message (ERROR|WARN|authentication)

[OUTPUT]
    Name              stdout
    Match             *
    Format            json_lines

[OUTPUT]
    Name              file
    Match             bigquery.*
    Path              /var/log/output/
    File              bigquery.log
```

### 7.2 監視スクリプト

```bash
#!/bin/bash
# scripts/monitor.sh - 継続監視スクリプト

MONITOR_INTERVAL=60
LOG_FILE="/var/log/adminer/monitor.log"

monitor_loop() {
    while true; do
        {
            echo "=== Monitor Report - $(date) ==="

            # ヘルスチェック
            bash "$(dirname "$0")/health-check.sh"

            # リソース使用量
            docker stats --no-stream "$CONTAINER_NAME"

            # ディスク容量
            df -h /var/log/adminer

            echo ""
        } | tee -a "$LOG_FILE"

        sleep "$MONITOR_INTERVAL"
    done
}

# バックグラウンド実行
if [[ "$1" == "--daemon" ]]; then
    monitor_loop &
    echo "Monitor started in background (PID: $!)"
    echo "$!" > /var/run/adminer-monitor.pid
else
    monitor_loop
fi
```

## 8. トラブルシューティング

### 8.1 よくある問題と解決手順

#### 問題1: コンテナが起動しない

```bash
# 診断手順
docker-compose ps                    # コンテナ状態確認
docker-compose logs                  # ログ確認
docker inspect CONTAINER_NAME        # 詳細情報確認

# 一般的な解決策
docker-compose down                  # 完全停止
docker system prune                  # クリーンアップ
docker-compose up -d                # 再起動
```

#### 問題2: BigQuery接続エラー

```bash
# 認証確認
ls -la /etc/adminer/secrets/
cat /etc/adminer/secrets/credentials.json | jq .type

# 権限確認
gcloud auth activate-service-account --key-file=/path/to/credentials.json
bq ls --project_id=YOUR_PROJECT_ID

# ネットワーク確認
docker exec CONTAINER_NAME curl -I https://bigquery.googleapis.com
```

### 8.2 ログ分析ツール

```bash
# scripts/log-analyzer.sh - ログ分析ツール

analyze_errors() {
    echo "Top 10 Error Patterns:"
    grep -i error /var/log/adminer/*.log | \
        awk '{print $NF}' | \
        sort | uniq -c | sort -nr | head -10
}

analyze_performance() {
    echo "Response Time Analysis:"
    grep "response_time" /var/log/adminer/apache2/access.log | \
        awk '{sum+=$NF; count++} END {print "Average:", sum/count "ms"}'
}

analyze_authentication() {
    echo "Authentication Events:"
    grep -i "auth\|login" /var/log/adminer/*.log | \
        tail -20
}

case "${1:-all}" in
    errors) analyze_errors ;;
    performance) analyze_performance ;;
    auth) analyze_authentication ;;
    all)
        analyze_errors
        echo ""
        analyze_performance
        echo ""
        analyze_authentication
        ;;
esac
```

---

このガイドに従って、安全で効率的なAdminer BigQueryドライバーコンテナの運用を行ってください。