#!/bin/bash
# 全E2Eテスト実行スクリプト（ホスト側）
# 参照系 → 更新系の順で実行、ログ保存とエラーハンドリング機能付き

set -e

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="./test-results"
LOG_FILE="$LOG_DIR/all_tests_$TIMESTAMP.log"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

echo "🚀 全E2Eテスト実行開始: $(date)" | tee "$LOG_FILE"
echo "ログファイル: $LOG_FILE" | tee -a "$LOG_FILE"

# scriptsディレクトリから実行するためにe2eディレクトリに移動
cd "$(dirname "$0")/.."

# Webコンテナが起動していることを確認
echo "📡 Web環境確認中..." | tee -a "$LOG_FILE"
docker compose -f ../web/compose.yml ps adminer-bigquery-test | grep "Up" > /dev/null || {
    echo "❌ Web環境が起動していません" | tee -a "$LOG_FILE"
    echo "Web環境を起動してください: cd ../web && docker compose up -d" | tee -a "$LOG_FILE"
    exit 1
}
echo "✅ Web環境確認完了" | tee -a "$LOG_FILE"

# E2Eコンテナをビルド
echo "🏗️  E2Eコンテナビルド中..." | tee -a "$LOG_FILE"
docker compose build playwright-e2e 2>&1 | tee -a "$LOG_FILE"

# 1. 参照系テスト実行
echo "🔍 ======== 参照系テスト実行 ========" | tee -a "$LOG_FILE"
docker compose run --rm playwright-e2e npx playwright test \
    --config=/app/container/e2e/playwright.config.js \
    tests/reference-system-test.spec.js \
    --reporter=line \
    --output=test-results/reference \
    --project=chromium 2>&1 | tee -a "$LOG_FILE"

echo "" | tee -a "$LOG_FILE"
echo "🔧 ======== 更新系テスト実行 ========" | tee -a "$LOG_FILE"
docker compose run --rm playwright-e2e npx playwright test \
    --config=/app/container/e2e/playwright.config.js \
    tests/bigquery-crud-test.spec.js \
    --reporter=line \
    --output=test-results/crud \
    --project=chromium 2>&1 | tee -a "$LOG_FILE"

EXIT_CODE=${PIPESTATUS[0]}

if [ $EXIT_CODE -eq 0 ]; then
    echo "✅ 全E2Eテスト成功: $(date)" | tee -a "$LOG_FILE"
else
    echo "❌ 全E2Eテスト失敗: $(date)" | tee -a "$LOG_FILE"
    echo "詳細はログを確認してください: $LOG_FILE"
fi

echo "📊 レポート生成中..." | tee -a "$LOG_FILE"
if [ -d "./playwright-report" ]; then
    echo "📈 Playwrightレポート: ./playwright-report/index.html" | tee -a "$LOG_FILE"
fi

echo "🎯 全テスト完了: $(date)" | tee -a "$LOG_FILE"

exit $EXIT_CODE