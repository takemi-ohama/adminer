#!/bin/bash

# BigQuery Adminer E2E スモークテスト実行スクリプト
# 最低限の動作確認を高速実行（CI/CD用）

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="$TEST_DIR/test-results"
REPORT_FILE="$LOG_DIR/smoke-test-$TIMESTAMP.txt"

mkdir -p "$LOG_DIR"

echo "💨 BigQuery Adminer スモークテスト実行" | tee "$REPORT_FILE"
echo "====================================" | tee -a "$REPORT_FILE"
echo "実行時刻: $(date)" | tee -a "$REPORT_FILE"
echo "" | tee -a "$REPORT_FILE"

# スモークテスト関数
run_smoke_test() {
    local test_file="$1"
    local test_name="$2"
    local test_filter="$3"

    echo "💨 [$test_name]..." | tee -a "$REPORT_FILE"

    # タイムアウト設定（各テスト30秒以内）
    if timeout 30s npx playwright test "$test_file" --grep "$test_filter" --reporter=line --max-failures=1 > /dev/null 2>&1; then
        echo "✅ OK" | tee -a "$REPORT_FILE"
        return 0
    else
        echo "❌ FAIL" | tee -a "$REPORT_FILE"
        return 1
    fi
}

# 最低限のスモークテスト実行
failed_tests=0

echo "🔍 環境チェック..." | tee -a "$REPORT_FILE"

# Docker環境チェック
if docker ps | grep -q "adminer-bigquery-test"; then
    echo "✅ Webコンテナ動作中" | tee -a "$REPORT_FILE"
else
    echo "❌ Webコンテナ未起動" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "🚨 Webコンテナを起動してください：" | tee -a "$REPORT_FILE"
    echo "cd ../../container/web && docker compose up -d" | tee -a "$REPORT_FILE"
    exit 1
fi

# HTTP接続チェック
if curl -sSf http://localhost:8080 > /dev/null 2>&1; then
    echo "✅ HTTP接続確認" | tee -a "$REPORT_FILE"
else
    echo "❌ HTTP接続失敗" | tee -a "$REPORT_FILE"
    ((failed_tests++))
fi

echo "" | tee -a "$REPORT_FILE"
echo "🧪 スモークテスト実行中..." | tee -a "$REPORT_FILE"

# 1. 基本認証テスト
if ! run_smoke_test "01-authentication-login.spec.js" "認証" "ドライバー選択確認テスト"; then
    ((failed_tests++))
fi

# 2. データセット接続テスト
if ! run_smoke_test "02-database-dataset-operations.spec.js" "データセット接続" "データセット一覧表示テスト"; then
    ((failed_tests++))
fi

# 3. SQLエディタ表示テスト
if ! run_smoke_test "04-sql-query-execution.spec.js" "SQLエディタ" "SQL コマンド画面表示テスト"; then
    ((failed_tests++))
fi

# 4. 基本UI表示テスト
if ! run_smoke_test "06-ui-navigation-menu.spec.js" "UI表示" "メインナビゲーションメニューテスト"; then
    ((failed_tests++))
fi

# 結果判定
total_smoke_tests=4
echo "" | tee -a "$REPORT_FILE"
echo "====================================" | tee -a "$REPORT_FILE"
echo "💨 スモークテスト結果" | tee -a "$REPORT_FILE"
echo "------------------------------------" | tee -a "$REPORT_FILE"
echo "実行テスト数: $total_smoke_tests" | tee -a "$REPORT_FILE"
echo "成功: $((total_smoke_tests - failed_tests))" | tee -a "$REPORT_FILE"
echo "失敗: $failed_tests" | tee -a "$REPORT_FILE"
echo "完了時刻: $(date)" | tee -a "$REPORT_FILE"

if [ $failed_tests -eq 0 ]; then
    echo "" | tee -a "$REPORT_FILE"
    echo "🎉 スモークテスト成功！" | tee -a "$REPORT_FILE"
    echo "BigQuery Adminerプラグインの基本動作を確認しました。" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "📋 次のステップ:" | tee -a "$REPORT_FILE"
    echo "• クリティカルパステスト: ./run-critical-path-tests.sh" | tee -a "$REPORT_FILE"
    echo "• 包括テスト: ./run-all-tests.sh" | tee -a "$REPORT_FILE"
    exit 0
else
    echo "" | tee -a "$REPORT_FILE"
    echo "💥 スモークテスト失敗" | tee -a "$REPORT_FILE"
    echo "基本機能に問題があります。以下を確認してください：" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "🔧 トラブルシューティング:" | tee -a "$REPORT_FILE"
    echo "1. Webサーバー再起動: cd ../../container/web && docker compose restart" | tee -a "$REPORT_FILE"
    echo "2. ログ確認: docker compose logs" | tee -a "$REPORT_FILE"
    echo "3. 環境変数確認: docker compose exec web env | grep GOOGLE" | tee -a "$REPORT_FILE"
    echo "4. 詳細テスト: ./run-individual-test.sh 1 --headed" | tee -a "$REPORT_FILE"
    exit 1
fi