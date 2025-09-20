#!/bin/bash
# 参照系E2Eテスト実行スクリプト
# 既存データでの表示・ナビゲーション機能のテスト

set -e

echo "🔍 参照系E2Eテスト開始: $(date)"
echo "ベースURL: $BASE_URL"

# Adminer Web環境の接続確認
echo "📡 接続確認中..."
curl -s --fail "$BASE_URL" > /dev/null || {
    echo "❌ Adminer Web環境に接続できません: $BASE_URL"
    exit 1
}
echo "✅ 接続確認完了"

# 参照系テスト実行
echo "📋 参照系テスト実行..."
npx playwright test tests/reference-system-test.spec.js \
    --reporter=line \
    --output=test-results/reference \
    --project=chromium

echo "✅ 参照系E2Eテスト完了: $(date)"