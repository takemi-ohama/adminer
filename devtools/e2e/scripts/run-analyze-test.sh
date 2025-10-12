#!/bin/bash

# Analyzeボタンエラー再現テスト実行スクリプト

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$E2E_DIR/test-results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_FILE="$LOG_DIR/analyze_button_test_$TIMESTAMP.log"

echo "🔍 Analyzeボタンテスト実行開始: $(date)"
echo "ログファイル: $LOG_FILE"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

# ログファイルと画面両方に出力する関数
log_and_echo() {
    echo "$1" | tee -a "$LOG_FILE"
}

# Web環境確認
log_and_echo "📡 Web環境確認中..."
if ! curl -s -I http://localhost:8080 > /dev/null 2>&1; then
    if ! docker inspect adminer-bigquery-test > /dev/null 2>&1; then
        log_and_echo "❌ Web環境が起動していません。先に以下を実行してください:"
        log_and_echo "   cd ../web && docker compose up -d"
        exit 1
    fi
fi
log_and_echo "✅ Web環境確認完了"

# E2Eコンテナビルド
log_and_echo "🏗️  E2Eコンテナビルド中..."
cd "$E2E_DIR"
docker compose build playwright-e2e 2>&1 | tee -a "$LOG_FILE"

# Analyzeボタンテスト実行
log_and_echo "🚀 Analyzeボタンテスト実行中..."
docker compose run --rm playwright-e2e sh -c "cd /app/devtools/e2e && npx playwright test tests/analyze-button-test.spec.js --project=chromium --reporter=line" 2>&1 | tee -a "$LOG_FILE"
TEST_EXIT_CODE=$?

# サーバーログも追記
log_and_echo ""
log_and_echo "📋 サーバーログ（最新20行）:"
docker logs adminer-bigquery-test 2>&1 | tail -20 | tee -a "$LOG_FILE"

if [ $TEST_EXIT_CODE -eq 0 ]; then
    log_and_echo "✅ Analyzeボタンテスト成功: $(date)"
else
    log_and_echo "❌ Analyzeボタンテスト失敗: $(date)"
fi

log_and_echo "📊 レポート生成中..."
log_and_echo "🎯 Analyzeボタンテスト完了: $(date)"

exit $TEST_EXIT_CODE