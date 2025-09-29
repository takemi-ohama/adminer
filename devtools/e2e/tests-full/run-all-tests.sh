#!/bin/bash

# BigQuery Adminer E2E 包括テスト実行スクリプト
# すべてのテストカテゴリを順次実行

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="$TEST_DIR/test-results"
REPORT_FILE="$LOG_DIR/comprehensive-test-report-$TIMESTAMP.txt"

# ログディレクトリ作成
mkdir -p "$LOG_DIR"

echo "🧪 BigQuery Adminer E2E 包括テスト開始" | tee "$REPORT_FILE"
echo "実行時刻: $(date)" | tee -a "$REPORT_FILE"
echo "========================================" | tee -a "$REPORT_FILE"

# テスト実行関数
run_test() {
    local test_file="$1"
    local test_name="$2"

    echo "" | tee -a "$REPORT_FILE"
    echo "🔍 [$test_name] 実行開始: $test_file" | tee -a "$REPORT_FILE"
    echo "----------------------------------------" | tee -a "$REPORT_FILE"

    if npx playwright test "$test_file" --reporter=line 2>&1 | tee -a "$REPORT_FILE"; then
        echo "✅ [$test_name] 実行完了" | tee -a "$REPORT_FILE"
        return 0
    else
        echo "❌ [$test_name] 実行失敗" | tee -a "$REPORT_FILE"
        return 1
    fi
}

# テストファイル定義（実行順序重要）
declare -A test_files=(
    ["01-authentication-login.spec.js"]="認証・ログイン"
    ["02-database-dataset-operations.spec.js"]="データセット操作"
    ["03-table-schema-operations.spec.js"]="テーブル・スキーマ操作"
    ["04-sql-query-execution.spec.js"]="SQLクエリ実行"
    ["05-data-modification.spec.js"]="データ変更操作"
    ["06-ui-navigation-menu.spec.js"]="UI・ナビゲーション・メニュー"
    ["07-import-export.spec.js"]="インポート・エクスポート"
)

# テスト実行
failed_tests=0
total_tests=${#test_files[@]}

for test_file in "01-authentication-login.spec.js" \
                 "02-database-dataset-operations.spec.js" \
                 "03-table-schema-operations.spec.js" \
                 "04-sql-query-execution.spec.js" \
                 "05-data-modification.spec.js" \
                 "06-ui-navigation-menu.spec.js" \
                 "07-import-export.spec.js"; do

    test_name=${test_files[$test_file]}

    if ! run_test "$test_file" "$test_name"; then
        ((failed_tests++))
    fi
done

# 結果サマリー
echo "" | tee -a "$REPORT_FILE"
echo "========================================" | tee -a "$REPORT_FILE"
echo "📊 テスト実行結果サマリー" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
echo "総テスト数: $total_tests" | tee -a "$REPORT_FILE"
echo "成功: $((total_tests - failed_tests))" | tee -a "$REPORT_FILE"
echo "失敗: $failed_tests" | tee -a "$REPORT_FILE"
echo "実行完了時刻: $(date)" | tee -a "$REPORT_FILE"

if [ $failed_tests -eq 0 ]; then
    echo "🎉 すべてのテストが正常に完了しました！" | tee -a "$REPORT_FILE"
    exit 0
else
    echo "⚠️ $failed_tests 個のテストで問題が発見されました。" | tee -a "$REPORT_FILE"
    echo "詳細は上記のログまたはPlaywrightレポートを確認してください。" | tee -a "$REPORT_FILE"
    exit 1
fi