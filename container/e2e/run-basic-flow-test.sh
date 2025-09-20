#!/bin/bash
# 基本機能フローテスト実行スクリプト（ホスト側・DooD対応） - i03.md #5対応
# 標準入出力方式、ログ保存機能付き

set -e

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="./test-results"
LOG_FILE="$LOG_DIR/basic_flow_test_$TIMESTAMP.log"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

echo "🚀 基本機能フローテスト実行開始: $(date)" | tee "$LOG_FILE"
echo "ログファイル: $LOG_FILE" | tee -a "$LOG_FILE"

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

# 基本フローテストスクリプトを作成
echo "📝 基本フローテストスクリプト作成中..." | tee -a "$LOG_FILE"

# 共有volumeにテストスクリプトを作成
echo "📝 共有volumeにテストスクリプト作成中..." | tee -a "$LOG_FILE"

# 共有volume経由でスクリプトを作成
cat > /tmp/basic_flow_test_script.sh << 'EOF'
#!/bin/bash
set -e

echo "📡 接続確認中..."
curl -s --fail "$BASE_URL" > /dev/null || {
    echo "❌ Adminer Web環境に接続できません: $BASE_URL"
    exit 1
}
echo "✅ 接続確認完了"

echo "📋 基本機能フローテスト実行..."
npx playwright test tests/basic-flow-test.spec.js \
    --reporter=line \
    --project=chromium

echo "✅ 基本機能フローテスト完了"
EOF

# 共有volume経由でスクリプトを実行
echo "🚀 基本機能フローテスト実行中..." | tee -a "$LOG_FILE"

# 共有volumeにtestsディレクトリを作成してテストファイルをコピー
docker run --rm -v dev_workspace:/workspace -v $(pwd):/e2e alpine:latest \
  sh -c 'mkdir -p /workspace/tests && cp /e2e/tests/basic-flow-test.spec.js /workspace/tests/' 2>&1 | tee -a "$LOG_FILE"

# 共有volume経由でスクリプトを実行
docker compose run --rm playwright-e2e bash -c "
set -e
echo '📡 接続確認中...'
curl -s --fail \$BASE_URL > /dev/null || {
    echo '❌ Adminer Web環境に接続できません: \$BASE_URL'
    exit 1
}
echo '✅ 接続確認完了'
echo '📋 基本機能フローテスト実行...'
npx playwright test tests/basic-flow-test.spec.js --reporter=line --project=chromium
echo '✅ 基本機能フローテスト完了'
" 2>&1 | tee -a "$LOG_FILE"

EXIT_CODE=${PIPESTATUS[1]}

if [ $EXIT_CODE -eq 0 ]; then
    echo "✅ 基本機能フローテスト成功: $(date)" | tee -a "$LOG_FILE"
else
    echo "❌ 基本機能フローテスト失敗: $(date)" | tee -a "$LOG_FILE"
    echo "詳細はログを確認してください: $LOG_FILE"
    exit $EXIT_CODE
fi

echo "🎯 基本機能フローテスト完了: $(date)" | tee -a "$LOG_FILE"