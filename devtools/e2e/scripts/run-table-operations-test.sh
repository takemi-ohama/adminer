#!/bin/bash

# テーブル操作ボタン包括テスト実行スクリプト
# Analyze、Optimize、Check、Repair、Truncate、Dropボタンのテスト

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$E2E_DIR/test-results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_FILE="$LOG_DIR/table_operations_test_$TIMESTAMP.log"

echo "🔧 テーブル操作ボタン包括テスト実行開始: $(date)"
echo "ログファイル: $LOG_FILE"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

# ログファイルと画面両方に出力する関数
log_and_echo() {
    echo "$1" | tee -a "$LOG_FILE"
}

# Web環境確認
log_and_echo "📡 Web環境確認中..."
if ! curl -s -I http://localhost:8080 > /dev/null 2>&1; then
    if ! docker inspect adminer-bigquery-test > /dev/null 2>&1; then
        log_and_echo "❌ Web環境が起動していません。先に以下を実行してください:"
        log_and_echo "   cd ../web && docker compose up -d"
        exit 1
    fi
fi
log_and_echo "✅ Web環境確認完了"

# E2Eコンテナビルド
log_and_echo "🏗️  E2Eコンテナビルド中..."
cd "$E2E_DIR"
docker compose build playwright-e2e 2>&1 | tee -a "$LOG_FILE"

# テーブル操作ボタンテスト実行
log_and_echo "🚀 テーブル操作ボタン包括テスト実行中..."
docker compose run --rm playwright-e2e sh -c "cd /app/container/e2e && npx playwright test tests/table-operations-test.spec.js --project=chromium --reporter=line" 2>&1 | tee -a "$LOG_FILE"
TEST_EXIT_CODE=$?

# サーバーログも追記
log_and_echo ""
log_and_echo "📋 サーバーログ（最新20行）:"
docker logs adminer-bigquery-test 2>&1 | tail -20 | tee -a "$LOG_FILE"

# テスト結果の詳細確認
log_and_echo ""
log_and_echo "📊 テスト詳細レポート:"
log_and_echo "   - テストファイル: table-operations-test.spec.js"
log_and_echo "   - 対象機能: Analyze, Optimize, Check, Repair, Truncate, Drop"
log_and_echo "   - 実装状況:"
log_and_echo "     * Truncate: 実装済み (BigQuery TRUNCATE TABLE 対応)"
log_and_echo "     * Drop: 実装済み (BigQuery DROP TABLE 対応)"
log_and_echo "     * Analyze: 未対応メッセージ表示"
log_and_echo "     * Optimize: 未対応メッセージ表示"
log_and_echo "     * Check: 未対応メッセージ表示"
log_and_echo "     * Repair: 未対応メッセージ表示"

if [ $TEST_EXIT_CODE -eq 0 ]; then
    log_and_echo "✅ テーブル操作ボタン包括テスト成功: $(date)"
    log_and_echo "🎯 全ボタンが適切に実装・対応されています"
else
    log_and_echo "❌ テーブル操作ボタン包括テスト失敗: $(date)"
    log_and_echo "🔍 詳細は上記ログを確認してください"
fi

log_and_echo ""
log_and_echo "📋 レポート生成完了"
log_and_echo "📁 ログ保存場所: $LOG_FILE"
log_and_echo "🎯 テーブル操作ボタン包括テスト完了: $(date)"

exit $TEST_EXIT_CODE