#!/bin/bash
# 全テスト実行スクリプト
# 参照系 → 更新系の順で全テストを実行

set -e

echo "🚀 全E2Eテスト実行開始: $(date)"
echo "ベースURL: $BASE_URL"

# Adminer Web環境の接続確認
echo "📡 接続確認中..."
curl -s --fail "$BASE_URL" > /dev/null || {
    echo "❌ Adminer Web環境に接続できません: $BASE_URL"
    exit 1
}
echo "✅ 接続確認完了"

# 1. 参照系テスト
echo "🔍 ======== 参照系テスト実行 ========"
bash /app/scripts/reference-test.sh

echo ""
echo "🔧 ======== 更新系テスト実行 ========"
bash /app/scripts/crud-test.sh

echo ""
echo "✅ 全E2Eテスト完了: $(date)"