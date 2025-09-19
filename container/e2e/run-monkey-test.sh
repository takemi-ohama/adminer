#!/bin/bash

# BigQuery Adminer Monkey Test Runner
# ランダムな操作でアプリケーションの安定性をテストします

set -e

echo "🐒 BigQuery Adminer Monkey Test Starting..."

# 必要なディレクトリ作成
mkdir -p ./test-results/monkey
mkdir -p ./playwright-report/monkey

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

echo "✅ Adminer is ready for monkey testing!"

# モンキーテスト実行
echo "🐒 Running Monkey Test..."

# モンキーテストのみを実行
docker compose run --rm playwright-e2e npx playwright test tests/bigquery-monkey.spec.js --reporter=list

# テスト結果の確認
TEST_EXIT_CODE=$?

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "✅ Monkey test passed! Application is stable under random interactions."
    echo "📊 Detailed report available in: ./playwright-report/"
    echo "📁 Test results available in: ./test-results/"
else
    echo "⚠️  Monkey test detected issues!"
    echo "📊 Check detailed report in: ./playwright-report/"
    echo "📁 Check test results in: ./test-results/"
fi

# 統計情報表示
echo ""
echo "📈 Monkey Test Summary:"
echo "   🎯 Test Type: Random interaction stability test"
echo "   🐒 Actions: 20+ random interactions per test"
echo "   🔍 Coverage: Links, buttons, inputs, navigation"
echo "   ❌ Error Detection: Fatal errors, console errors, page errors"

# レポート表示のオプション
echo ""
echo "📖 To view the detailed HTML report:"
echo "   docker compose --profile e2e run --rm playwright-e2e npm run test:report"

echo ""
echo "🔄 To run continuous monkey testing:"
echo "   while true; do ./run-monkey-test.sh; sleep 60; done"

exit $TEST_EXIT_CODE