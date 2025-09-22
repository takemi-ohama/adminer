#!/bin/bash

# CRUD E2Eテスト実行スクリプト

echo "🔧 更新系E2Eテスト実行開始: $(date)"

# ログファイル名（タイムスタンプ付き）
LOG_FILE="./test-results/crud_test_$(date +%Y%m%d_%H%M%S).log"
echo "ログファイル: $LOG_FILE"

# Web環境確認
echo "📡 Web環境確認中..."
if ! curl -s http://adminer-bigquery-test >/dev/null 2>&1; then
  echo "❌ Web環境が起動していません。container/web/compose.yml を起動してください。"
  echo "cd container/web && docker compose up -d"
  exit 1
fi
echo "✅ Web環境確認完了"

# E2Eコンテナビルド
echo "🏗️  E2Eコンテナビルド中..."
docker compose build

# 更新系テスト実行
echo "🚀 更新系テスト実行中..."
docker compose run --rm playwright-e2e bash -c "
cd /app/container/e2e &&
npm install &&
echo '環境: test' &&
echo 'ベースURL: http://adminer-bigquery-test' &&
echo '📁 テスト結果保存先: /app/container/e2e/test-results' &&
echo '📋 直接コマンド実行: npx playwright test --config=/app/container/e2e/playwright.config.js tests/bigquery-crud-test.spec.js --reporter=line --output=test-results/crud --project=chromium' &&
npx playwright test --config=/app/container/e2e/playwright.config.js tests/bigquery-crud-test.spec.js --reporter=line --output=test-results/crud --project=chromium
" 2>&1 | tee "$LOG_FILE"

if [ ${PIPESTATUS[0]} -eq 0 ]; then
    echo "✅ 更新系E2Eテスト成功: $(date)"
    echo "📊 レポート生成中..."
    echo "🎯 更新系テスト完了: $(date)"
else
    echo "❌ 更新系E2Eテストでエラーが発生しました: $(date)"
    echo "🔍 ログファイルを確認してください: $LOG_FILE"
    exit 1
fi