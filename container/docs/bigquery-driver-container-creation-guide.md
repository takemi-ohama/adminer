# BigQuery ドライバーを利用したコンテナ作成方法

## 1. 概要

このドキュメントでは、Adminer BigQuery ドライバーを組み込んだDockerコンテナの作成方法について説明します。開発者やDevOpsエンジニアが、本格運用環境やCI/CD環境でBigQueryドライバー付きAdminerコンテナを構築する際の指針を提供します。

## 2. コンテナ設計思想

### 2.1 設計原則

- **最小権限**: 必要最小限のBigQuery権限のみ
- **セキュリティ**: 認証情報の安全な管理
- **スケーラビリティ**: 複数環境での展開対応
- **保守性**: バージョン管理とアップデート対応

### 2.2 コンテナ構成パターン

#### パターンA: 単体コンテナ
```
adminer-bigquery:latest
├── Adminer Core
├── BigQuery Driver Plugin
├── PHP Dependencies
└── Web Server (Apache/Nginx)
```

#### パターンB: マルチステージビルド
```
Build Stage:
├── Composer install
├── Asset compilation
└── Dependency optimization

Runtime Stage:
├── Minimal base image
├── Runtime dependencies only
└── Security hardening
```

## 3. Dockerfileの作成

### 3.1 基本的なDockerfile

```dockerfile
# ==============================================================================
# Multi-stage build for Adminer BigQuery Driver
# ==============================================================================

# Build stage
FROM php:8.3-cli AS builder

# Install build dependencies
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /build

# Copy dependency files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application source
COPY . .

# Build assets if needed
RUN if [ -f "package.json" ]; then \
    curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && \
    apt-get install -y nodejs && \
    npm ci && npm run build; \
    fi

# ==============================================================================
# Runtime stage
# ==============================================================================
FROM php:8.3-apache AS runtime

# Install runtime dependencies
RUN apt-get update && apt-get install -y \
    # System utilities
    curl \
    unzip \
    # SSL support
    ca-certificates \
    # Clean up
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    && docker-php-ext-enable pdo

# Configure Apache
RUN a2enmod rewrite ssl headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy built application from builder stage
COPY --from=builder /build .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure PHP
RUN { \
    echo 'date.timezone = UTC'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    } > /usr/local/etc/php/conf.d/bigquery.ini

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
```

### 3.2 セキュリティ強化版Dockerfile

```dockerfile
FROM php:8.3-apache AS secure-runtime

# Create non-root user
RUN groupadd -r adminer && useradd -r -g adminer adminer

# Install dependencies with security considerations
RUN apt-get update && apt-get install -y \
    --no-install-recommends \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# Install PHP extensions
RUN docker-php-ext-install pdo

# Security: Remove unnecessary packages and clean up
RUN apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/cache/apt/* /tmp/* /var/tmp/*

# Configure Apache with security headers
COPY container/apache-security.conf /etc/apache2/conf-available/security.conf
RUN a2enconf security && a2enmod headers rewrite

# Copy application
COPY --chown=adminer:adminer . /var/www/html

# Set restrictive permissions
RUN chmod -R 750 /var/www/html \
    && find /var/www/html -type f -exec chmod 640 {} \;

# Security: Remove sensitive files
RUN find /var/www/html -name "*.md" -delete \
    && find /var/www/html -name ".git*" -delete \
    && find /var/www/html -name "composer.*" -delete

# PHP security configuration
RUN { \
    echo 'expose_php = Off'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /var/log/php_errors.log'; \
    echo 'session.cookie_secure = On'; \
    echo 'session.cookie_httponly = On'; \
    echo 'session.use_strict_mode = On'; \
    } > /usr/local/etc/php/conf.d/security.ini

# Switch to non-root user
USER adminer

EXPOSE 80
CMD ["apache2-foreground"]
```

### 3.3 開発用Dockerfile

```dockerfile
FROM php:8.3-apache AS development

# Development tools
RUN apt-get update && apt-get install -y \
    git \
    vim \
    curl \
    unzip \
    # Debug tools
    strace \
    tcpdump \
    && rm -rf /var/lib/apt/lists/*

# Install Xdebug for development
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Xdebug configuration
RUN { \
    echo 'xdebug.mode=debug'; \
    echo 'xdebug.client_host=host.docker.internal'; \
    echo 'xdebug.client_port=9003'; \
    echo 'xdebug.start_with_request=yes'; \
    } > /usr/local/etc/php/conf.d/xdebug.ini

# Development PHP settings
RUN { \
    echo 'display_errors = On'; \
    echo 'error_reporting = E_ALL'; \
    echo 'log_errors = On'; \
    echo 'html_errors = On'; \
    } > /usr/local/etc/php/conf.d/development.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
VOLUME ["/var/www/html"]

EXPOSE 80 9003
CMD ["apache2-foreground"]
```

## 4. コンテナ構成ファイル

### 4.1 Docker Compose設定

```yaml
# docker-compose.yml - Production ready
version: '3.8'

services:
  adminer-bigquery:
    build:
      context: .
      dockerfile: Dockerfile
      target: runtime
    container_name: adminer-bigquery
    restart: unless-stopped

    ports:
      - "8080:80"

    environment:
      # BigQuery configuration
      - GOOGLE_CLOUD_PROJECT=${GOOGLE_CLOUD_PROJECT}
      - BIGQUERY_LOCATION=${BIGQUERY_LOCATION:-US}

    # Secrets management
    secrets:
      - google_credentials

    # Volume mounts
    volumes:
      # Log persistence
      - adminer_logs:/var/log/apache2
      # Optional: custom configuration
      - ./config/adminer.php:/var/www/html/config.php:ro

    # Resource limits
    deploy:
      resources:
        limits:
          cpus: '1.0'
          memory: 1G
        reservations:
          cpus: '0.5'
          memory: 512M

    # Health check
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s

    # Networking
    networks:
      - bigquery_network

    # Security
    security_opt:
      - no-new-privileges:true
    read_only: true
    tmpfs:
      - /tmp
      - /var/tmp

# Secrets configuration
secrets:
  google_credentials:
    file: ${GOOGLE_CREDENTIALS_PATH:-./secrets/credentials.json}

# Volumes
volumes:
  adminer_logs:
    driver: local

# Networks
networks:
  bigquery_network:
    driver: bridge
    ipam:
      config:
        - subnet: 172.20.0.0/16
```

### 4.2 開発用Docker Compose

```yaml
# docker-compose.dev.yml
version: '3.8'

services:
  adminer-bigquery-dev:
    build:
      context: .
      dockerfile: Dockerfile
      target: development
    container_name: adminer-bigquery-dev

    ports:
      - "8080:80"
      - "9003:9003"  # Xdebug

    environment:
      - GOOGLE_CLOUD_PROJECT=development-project
      - XDEBUG_MODE=debug

    volumes:
      # Source code mounting for hot reload
      - .:/var/www/html
      - /var/www/html/vendor  # Exclude vendor

    # Development overrides
    command: >
      bash -c "
        composer install &&
        apache2-foreground
      "

networks:
  default:
    name: adminer_dev_network
```

### 4.3 環境変数設定

```bash
# .env.example
# Copy to .env and customize

# BigQuery Configuration
GOOGLE_CLOUD_PROJECT=your-project-id
BIGQUERY_LOCATION=US

# Security
GOOGLE_CREDENTIALS_PATH=./secrets/credentials.json

# Container Configuration
ADMINER_PORT=8080
CONTAINER_NAME=adminer-bigquery

# Resource Limits
MEMORY_LIMIT=1G
CPU_LIMIT=1.0

# Networking
SUBNET=172.20.0.0/16

# Logging
LOG_LEVEL=info
```

## 5. ビルドスクリプト

### 5.1 自動ビルドスクリプト

```bash
#!/bin/bash
# build.sh - Automated container build script

set -euo pipefail

# Configuration
PROJECT_NAME="adminer-bigquery"
VERSION=${VERSION:-$(date +%Y%m%d-%H%M%S)}
REGISTRY=${REGISTRY:-""}
BUILD_TARGET=${BUILD_TARGET:-"runtime"}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

# Pre-build validation
validate_environment() {
    log_info "Validating build environment..."

    # Check Docker
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed or not in PATH"
    fi

    # Check required files
    local required_files=(
        "Dockerfile"
        "composer.json"
        "plugins/drivers/bigquery.php"
    )

    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            log_error "Required file not found: $file"
        fi
    done

    log_info "Environment validation completed"
}

# Build function
build_container() {
    log_info "Building container: $PROJECT_NAME:$VERSION"

    # Build arguments
    local build_args=(
        --target "$BUILD_TARGET"
        --tag "$PROJECT_NAME:$VERSION"
        --tag "$PROJECT_NAME:latest"
    )

    # Add registry prefix if specified
    if [[ -n "$REGISTRY" ]]; then
        build_args+=(--tag "$REGISTRY/$PROJECT_NAME:$VERSION")
        build_args+=(--tag "$REGISTRY/$PROJECT_NAME:latest")
    fi

    # Build with BuildKit for better performance
    DOCKER_BUILDKIT=1 docker build "${build_args[@]}" .

    log_info "Build completed successfully"
}

# Test function
test_container() {
    log_info "Running container tests..."

    # Basic smoke test
    local container_id
    container_id=$(docker run -d -p 0:80 "$PROJECT_NAME:latest")

    # Wait for container to start
    sleep 10

    # Get mapped port
    local port
    port=$(docker port "$container_id" 80 | cut -d: -f2)

    # Test HTTP response
    if curl -f "http://localhost:$port" > /dev/null 2>&1; then
        log_info "Container test passed"
    else
        log_error "Container test failed"
    fi

    # Cleanup
    docker stop "$container_id" > /dev/null
    docker rm "$container_id" > /dev/null
}

# Main execution
main() {
    log_info "Starting BigQuery Adminer container build"

    validate_environment
    build_container
    test_container

    log_info "Build process completed successfully"
    log_info "Container available as: $PROJECT_NAME:$VERSION"

    if [[ -n "$REGISTRY" ]]; then
        log_info "To push to registry, run:"
        echo "  docker push $REGISTRY/$PROJECT_NAME:$VERSION"
        echo "  docker push $REGISTRY/$PROJECT_NAME:latest"
    fi
}

# Execute main function
main "$@"
```

### 5.2 CI/CD統合スクリプト

```yaml
# .github/workflows/build-container.yml
name: Build and Push Container

on:
  push:
    branches: [main, develop]
    tags: ['v*']
  pull_request:
    branches: [main]

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
    - name: Checkout code
      uses: actions/checkout@v4

    - name: Set up Docker Buildx
      uses: docker/setup-buildx-action@v3

    - name: Login to Container Registry
      uses: docker/login-action@v3
      with:
        registry: ${{ secrets.REGISTRY_URL }}
        username: ${{ secrets.REGISTRY_USERNAME }}
        password: ${{ secrets.REGISTRY_PASSWORD }}

    - name: Extract metadata
      id: meta
      uses: docker/metadata-action@v5
      with:
        images: ${{ secrets.REGISTRY_URL }}/adminer-bigquery
        tags: |
          type=ref,event=branch
          type=ref,event=pr
          type=semver,pattern={{version}}
          type=semver,pattern={{major}}.{{minor}}

    - name: Build and push container
      uses: docker/build-push-action@v5
      with:
        context: .
        platforms: linux/amd64,linux/arm64
        push: ${{ github.event_name != 'pull_request' }}
        tags: ${{ steps.meta.outputs.tags }}
        labels: ${{ steps.meta.outputs.labels }}
        cache-from: type=gha
        cache-to: type=gha,mode=max

    - name: Run security scan
      uses: aquasecurity/trivy-action@master
      with:
        image-ref: ${{ steps.meta.outputs.tags }}
        format: 'sarif'
        output: 'trivy-results.sarif'

    - name: Upload security scan results
      uses: github/codeql-action/upload-sarif@v2
      with:
        sarif_file: 'trivy-results.sarif'
```

## 6. 運用時の考慮事項

### 6.1 セキュリティ設定

```bash
# container/security/apache-security.conf
# Security headers configuration

Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'"

# Hide server information
ServerTokens Prod
ServerSignature Off

# Disable unnecessary modules
LoadModule headers_module modules/mod_headers.so
```

### 6.2 ログ管理

```bash
# container/logging/rsyslog.conf
# Custom logging configuration

# BigQuery specific logs
:programname, isequal, "adminer-bigquery" /var/log/bigquery/access.log
& stop

# Security logs
:msg, contains, "authentication" /var/log/bigquery/security.log
& stop
```

### 6.3 モニタリング設定

```yaml
# monitoring/prometheus.yml
version: '3.8'

services:
  prometheus:
    image: prom/prometheus:latest
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus

  grafana:
    image: grafana/grafana:latest
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin
    volumes:
      - grafana_data:/var/lib/grafana
      - ./grafana-dashboards:/var/lib/grafana/dashboards

volumes:
  prometheus_data:
  grafana_data:
```

## 7. デプロイメント戦略

### 7.1 Blue-Green デプロイメント

```bash
#!/bin/bash
# deploy-blue-green.sh

BLUE_CONTAINER="adminer-bigquery-blue"
GREEN_CONTAINER="adminer-bigquery-green"
LOAD_BALANCER_CONFIG="/etc/nginx/conf.d/adminer.conf"

# Deploy to green environment
deploy_green() {
    docker run -d --name "$GREEN_CONTAINER" \
        -p 8081:80 \
        adminer-bigquery:latest

    # Health check
    wait_for_health "$GREEN_CONTAINER" 8081
}

# Switch traffic
switch_traffic() {
    # Update load balancer configuration
    sed -i 's/localhost:8080/localhost:8081/' "$LOAD_BALANCER_CONFIG"
    nginx -s reload

    # Stop blue container
    docker stop "$BLUE_CONTAINER"
    docker rm "$BLUE_CONTAINER"
}
```

### 7.2 Kubernetes デプロイメント

```yaml
# k8s/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: adminer-bigquery
  namespace: tools
spec:
  replicas: 2
  selector:
    matchLabels:
      app: adminer-bigquery
  template:
    metadata:
      labels:
        app: adminer-bigquery
    spec:
      containers:
      - name: adminer
        image: adminer-bigquery:latest
        ports:
        - containerPort: 80
        env:
        - name: GOOGLE_CLOUD_PROJECT
          valueFrom:
            configMapKeyRef:
              name: bigquery-config
              key: project-id
        volumeMounts:
        - name: credentials
          mountPath: /etc/credentials
          readOnly: true
        resources:
          requests:
            cpu: 100m
            memory: 128Mi
          limits:
            cpu: 500m
            memory: 512Mi
      volumes:
      - name: credentials
        secret:
          secretName: bigquery-credentials
---
apiVersion: v1
kind: Service
metadata:
  name: adminer-bigquery-service
spec:
  selector:
    app: adminer-bigquery
  ports:
  - port: 80
    targetPort: 80
  type: ClusterIP
```

## 8. トラブルシューティング

### 8.1 ビルド時の問題

```bash
# 問題: Composer install が失敗
# 解決: メモリ制限の調整
docker build --memory=2g --build-arg COMPOSER_MEMORY_LIMIT=2G .

# 問題: 依存関係の競合
# 解決: クリーンビルド
docker build --no-cache --pull .
```

### 8.2 実行時の問題

```bash
# 問題: 権限エラー
# 解決: ファイル権限の確認
docker exec -it container_name ls -la /var/www/html

# 問題: メモリ不足
# 解決: リソース制限の調整
docker run --memory=1g --cpus=1.0 adminer-bigquery:latest
```

---

このガイドに従って、安全で効率的なAdminer BigQueryドライバーコンテナを構築してください。