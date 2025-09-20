#!/bin/bash
# 基本機能フローテスト実行スクリプト - i03.md #5対応
# BigQueryログイン → データベース選択 → テーブル選択 → データ表示の基本フローテスト

set -e

echo "🚀 基本機能フローテスト開始: $(date)"
echo "ベースURL: $BASE_URL"

# Adminer Web環境の接続確認
echo "📡 接続確認中..."
curl -s --fail "$BASE_URL" > /dev/null || {
    echo "❌ Adminer Web環境に接続できません: $BASE_URL"
    exit 1
}
echo "✅ 接続確認完了"

# 基本フローテスト実行
echo "📋 基本機能フローテスト実行..."
npx playwright test tests/basic-flow-test.spec.js \
    --reporter=line \
    --output=test-results/basic-flow \
    --project=chromium

echo "✅ 基本機能フローテスト完了: $(date)"