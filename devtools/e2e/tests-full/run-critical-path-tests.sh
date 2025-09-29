#!/bin/bash

# BigQuery Adminer E2E クリティカルパステスト実行スクリプト
# 最重要機能のテストのみを高速実行

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="$TEST_DIR/test-results"
REPORT_FILE="$LOG_DIR/critical-path-test-$TIMESTAMP.txt"

mkdir -p "$LOG_DIR"

echo "⚡ BigQuery Adminer クリティカルパステスト実行" | tee "$REPORT_FILE"
echo "=============================================" | tee -a "$REPORT_FILE"
echo "実行時刻: $(date)" | tee -a "$REPORT_FILE"
echo "" | tee -a "$REPORT_FILE"

# クリティカルパステスト関数
run_critical_test() {
    local test_file="$1"
    local test_name="$2"
    local test_filter="$3"

    echo "🔍 [$test_name] 実行: $test_filter" | tee -a "$REPORT_FILE"

    if [ -n "$test_filter" ]; then
        if npx playwright test "$test_file" --grep "$test_filter" --reporter=line 2>&1 | tee -a "$REPORT_FILE"; then
            echo "✅ [$test_name] 完了" | tee -a "$REPORT_FILE"
            return 0
        else
            echo "❌ [$test_name] 失敗" | tee -a "$REPORT_FILE"
            return 1
        fi
    else
        if npx playwright test "$test_file" --reporter=line 2>&1 | tee -a "$REPORT_FILE"; then
            echo "✅ [$test_name] 完了" | tee -a "$REPORT_FILE"
            return 0
        else
            echo "❌ [$test_name] 失敗" | tee -a "$REPORT_FILE"
            return 1
        fi
    fi
}

# クリティカルパステスト定義
failed_tests=0

echo "1. 🔐 認証・接続テスト" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
if ! run_critical_test "01-authentication-login.spec.js" "BigQuery認証" "BigQuery認証とプロジェクト接続テスト"; then
    ((failed_tests++))
fi

if ! run_critical_test "01-authentication-login.spec.js" "ドライバー選択" "ドライバー選択確認テスト"; then
    ((failed_tests++))
fi

echo "" | tee -a "$REPORT_FILE"
echo "2. 📊 基本データ操作テスト" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
if ! run_critical_test "02-database-dataset-operations.spec.js" "データセット一覧" "データセット一覧表示テスト"; then
    ((failed_tests++))
fi

if ! run_critical_test "03-table-schema-operations.spec.js" "テーブル一覧" "テーブル一覧表示テスト"; then
    ((failed_tests++))
fi

if ! run_critical_test "03-table-schema-operations.spec.js" "テーブル詳細" "テーブル詳細・スキーマ表示テスト"; then
    ((failed_tests++))
fi

echo "" | tee -a "$REPORT_FILE"
echo "3. 🔍 SQLクエリ実行テスト" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
if ! run_critical_test "04-sql-query-execution.spec.js" "SQLエディタ表示" "SQL コマンド画面表示テスト"; then
    ((failed_tests++))
fi

if ! run_critical_test "04-sql-query-execution.spec.js" "基本SELECT" "基本SELECTクエリ実行テスト"; then
    ((failed_tests++))
fi

echo "" | tee -a "$REPORT_FILE"
echo "4. 🧭 UI・ナビゲーションテスト" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
if ! run_critical_test "06-ui-navigation-menu.spec.js" "メインメニュー" "メインナビゲーションメニューテスト"; then
    ((failed_tests++))
fi

if ! run_critical_test "06-ui-navigation-menu.spec.js" "BigQuery UI" "BigQuery固有UI要素テスト"; then
    ((failed_tests++))
fi

# 結果サマリー
total_critical_tests=9
echo "" | tee -a "$REPORT_FILE"
echo "=============================================" | tee -a "$REPORT_FILE"
echo "⚡ クリティカルパステスト結果" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
echo "実行テスト数: $total_critical_tests" | tee -a "$REPORT_FILE"
echo "成功: $((total_critical_tests - failed_tests))" | tee -a "$REPORT_FILE"
echo "失敗: $failed_tests" | tee -a "$REPORT_FILE"
echo "完了時刻: $(date)" | tee -a "$REPORT_FILE"

if [ $failed_tests -eq 0 ]; then
    echo "" | tee -a "$REPORT_FILE"
    echo "🎉 すべてのクリティカルパステストが成功しました！" | tee -a "$REPORT_FILE"
    echo "BigQuery Adminerプラグインの基本機能は正常に動作しています。" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "📋 次のステップ:" | tee -a "$REPORT_FILE"
    echo "1. 詳細テスト実行: ./run-all-tests.sh" | tee -a "$REPORT_FILE"
    echo "2. 個別機能テスト: ./run-individual-test.sh [1-7]" | tee -a "$REPORT_FILE"
    exit 0
else
    echo "" | tee -a "$REPORT_FILE"
    echo "⚠️ $failed_tests 個のクリティカル機能で問題が発見されました。" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "🔧 推奨対応:" | tee -a "$REPORT_FILE"
    echo "1. Webサーバーの状況確認: docker ps | grep adminer-bigquery" | tee -a "$REPORT_FILE"
    echo "2. 認証設定確認: 環境変数 GOOGLE_CLOUD_PROJECT, GOOGLE_APPLICATION_CREDENTIALS" | tee -a "$REPORT_FILE"
    echo "3. 個別デバッグ実行で詳細確認" | tee -a "$REPORT_FILE"
    exit 1
fi