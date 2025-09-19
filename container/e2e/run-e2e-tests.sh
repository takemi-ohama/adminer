#!/bin/bash

# BigQuery Adminer E2E Test Runner
# このスクリプトはPlaywrightベースのE2Eテストを実行します

set -e

echo "🚀 BigQuery Adminer E2E Tests Starting..."

# 必要なディレクトリ作成
mkdir -p ./test-results
mkdir -p ./playwright-report

# Adminerコンテナが起動していることを確認
echo "📋 Checking if Adminer container is running..."
if ! docker ps | grep -q adminer-bigquery-test; then
    echo "⚠️  Adminer container is not running. Starting from web directory..."
    (cd ../web && docker compose up -d adminer-bigquery-test)
    echo "⏳ Waiting for Adminer to be ready..."
    sleep 10
fi

# コンテナが応答可能かテスト
echo "🔍 Testing Adminer connectivity..."
if ! docker exec adminer-bigquery-test curl -f -s http://localhost/ > /dev/null; then
    echo "❌ Adminer is not responding. Please check the container."
    exit 1
fi

echo "✅ Adminer is ready!"

# Playwrightコンテナでテスト実行
echo "🎭 Running Playwright E2E tests..."

# E2Eテスト実行
docker compose run --rm playwright-e2e npm test

# テスト結果の確認
TEST_EXIT_CODE=$?

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "✅ All tests passed!"
    echo "📊 Test report available in: ./playwright-report/"
    echo "📁 Test results available in: ./test-results/"
else
    echo "❌ Some tests failed!"
    echo "📊 Check test report in: ./playwright-report/"
    echo "📁 Check test results in: ./test-results/"
fi

# テストレポート表示のオプション
echo ""
echo "📖 To view the HTML report, run:"
echo "   docker compose --profile e2e run --rm playwright-e2e npm run test:report"

exit $TEST_EXIT_CODE