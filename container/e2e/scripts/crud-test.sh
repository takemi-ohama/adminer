#!/bin/bash
# 更新系E2Eテスト実行スクリプト
# CREATE, INSERT, UPDATE, DELETE 機能のテスト

set -e

echo "🔧 更新系E2Eテスト開始: $(date)"
echo "ベースURL: $BASE_URL"

# Adminer Web環境の接続確認
echo "📡 接続確認中..."
curl -s --fail "$BASE_URL" > /dev/null || {
    echo "❌ Adminer Web環境に接続できません: $BASE_URL"
    exit 1
}
echo "✅ 接続確認完了"

# 更新系テスト実行
echo "📋 更新系テスト実行..."
npx playwright test tests/bigquery-crud-test.spec.js \
    --reporter=line \
    --output=test-results/crud \
    --project=chromium

echo "✅ 更新系E2Eテスト完了: $(date)"