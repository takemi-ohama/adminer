#!/bin/bash
# 更新系E2Eテスト実行スクリプト（ホスト側）
# ログ保存とエラーハンドリング機能付き

set -e

# scriptsディレクトリから実行するためにe2eディレクトリに移動
cd "$(dirname "$0")/.."

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="./test-results"
LOG_FILE="$LOG_DIR/crud_test_$TIMESTAMP.log"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

echo "🔧 更新系E2Eテスト実行開始: $(date)" | tee "$LOG_FILE"
echo "ログファイル: $LOG_FILE" | tee -a "$LOG_FILE"

# Webコンテナが起動していることを確認
echo "📡 Web環境確認中..." | tee -a "$LOG_FILE"
docker compose -f ../web/compose.yml ps adminer-bigquery-test | grep "Up" > /dev/null || {
    echo "❌ Web環境が起動していません" | tee -a "$LOG_FILE"
    echo "Web環境を起動してください: cd ../web && docker compose up -d" | tee -a "$LOG_FILE"
    exit 1
}
echo "✅ Web環境確認完了" | tee -a "$LOG_FILE"

# E2Eコンテナをビルド&実行
echo "🏗️  E2Eコンテナビルド中..." | tee -a "$LOG_FILE"
docker compose build playwright-e2e 2>&1 | tee -a "$LOG_FILE"

echo "🚀 更新系テスト実行中..." | tee -a "$LOG_FILE"
docker compose run --rm playwright-e2e npx playwright test \
    --config=/app/container/e2e/playwright.config.js \
    tests/bigquery-crud-test.spec.js \
    --reporter=line \
    --output=test-results/crud \
    --project=chromium 2>&1 | tee -a "$LOG_FILE"

EXIT_CODE=${PIPESTATUS[0]}

if [ $EXIT_CODE -eq 0 ]; then
    echo "✅ 更新系E2Eテスト成功: $(date)" | tee -a "$LOG_FILE"
else
    echo "❌ 更新系E2Eテスト失敗: $(date)" | tee -a "$LOG_FILE"
    echo "詳細はログを確認してください: $LOG_FILE"
    exit $EXIT_CODE
fi

echo "📊 レポート生成中..." | tee -a "$LOG_FILE"
if [ -d "./playwright-report" ]; then
    echo "📈 Playwrightレポート: ./playwright-report/index.html" | tee -a "$LOG_FILE"
fi

echo "🎯 更新系テスト完了: $(date)" | tee -a "$LOG_FILE"