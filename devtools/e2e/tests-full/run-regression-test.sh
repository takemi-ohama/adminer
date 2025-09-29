#!/bin/bash

# BigQuery Adminer E2E リグレッションテスト実行スクリプト
# 機能改修後の回帰バグ検出用

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="$TEST_DIR/test-results"
REPORT_FILE="$LOG_DIR/regression-test-$TIMESTAMP.txt"

mkdir -p "$LOG_DIR"

echo "🔄 BigQuery Adminer リグレッションテスト実行" | tee "$REPORT_FILE"
echo "===========================================" | tee -a "$REPORT_FILE"
echo "実行時刻: $(date)" | tee -a "$REPORT_FILE"
echo "" | tee -a "$REPORT_FILE"

# 引数解析
BASELINE_REPORT=""
COMPARE_MODE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --baseline)
            BASELINE_REPORT="$2"
            shift 2
            ;;
        --compare)
            COMPARE_MODE=true
            shift
            ;;
        *)
            echo "❌ 未知のオプション: $1"
            echo "使用方法: $0 [--baseline <前回レポートファイル>] [--compare]"
            exit 1
            ;;
    esac
done

# リグレッションテスト関数
run_regression_test() {
    local test_file="$1"
    local test_name="$2"
    local category="$3"

    echo "" | tee -a "$REPORT_FILE"
    echo "🔄 [$category] $test_name" | tee -a "$REPORT_FILE"
    echo "--------------------" | tee -a "$REPORT_FILE"

    # 詳細ログ付きでテスト実行
    local test_log="$LOG_DIR/regression-${category// /_}-$TIMESTAMP.log"

    if npx playwright test "$test_file" --reporter=line --output="$LOG_DIR/playwright-output" 2>&1 | tee "$test_log"; then
        echo "✅ PASS - $test_name" | tee -a "$REPORT_FILE"

        # 実行時間測定
        local execution_time=$(grep -o "passed.*([0-9]*\.[0-9]*s)" "$test_log" | tail -1 | grep -o "[0-9]*\.[0-9]*s" || echo "N/A")
        echo "   実行時間: $execution_time" | tee -a "$REPORT_FILE"

        return 0
    else
        echo "❌ FAIL - $test_name" | tee -a "$REPORT_FILE"

        # エラー詳細の抽出
        local error_summary=$(grep -A 3 -B 3 "Error\|Failed\|Exception" "$test_log" | head -10 || echo "詳細なエラー情報なし")
        echo "   エラー概要: $error_summary" | tee -a "$REPORT_FILE"

        return 1
    fi
}

# 環境ベースライン確認
echo "📋 環境ベースライン情報" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"

# Docker環境情報
docker_status=$(docker ps --format "table {{.Names}}\t{{.Status}}" | grep adminer || echo "Adminerコンテナなし")
echo "Docker環境: $docker_status" | tee -a "$REPORT_FILE"

# BigQuery接続確認
if curl -sSf http://localhost:8080 > /dev/null 2>&1; then
    echo "Adminer接続: ✅ 正常" | tee -a "$REPORT_FILE"
else
    echo "Adminer接続: ❌ 失敗" | tee -a "$REPORT_FILE"
fi

# Node.js/Playwright環境
node_version=$(node --version 2>/dev/null || echo "未検出")
playwright_version=$(npx playwright --version 2>/dev/null | head -1 || echo "未検出")
echo "Node.js: $node_version" | tee -a "$REPORT_FILE"
echo "Playwright: $playwright_version" | tee -a "$REPORT_FILE"

# リグレッションテスト実行
failed_tests=0
total_tests=0

echo "" | tee -a "$REPORT_FILE"
echo "🧪 リグレッションテスト実行開始" | tee -a "$REPORT_FILE"
echo "========================================" | tee -a "$REPORT_FILE"

# カテゴリ別テスト実行
test_categories=(
    "01-authentication-login.spec.js:認証・ログイン機能:Core Authentication"
    "02-database-dataset-operations.spec.js:データセット操作:Data Management"
    "03-table-schema-operations.spec.js:テーブル・スキーマ操作:Schema Operations"
    "04-sql-query-execution.spec.js:SQLクエリ実行:Query Engine"
    "05-data-modification.spec.js:データ変更操作:Data Modification"
    "06-ui-navigation-menu.spec.js:UI・ナビゲーション:User Interface"
    "07-import-export.spec.js:インポート・エクスポート:Data Transfer"
)

for test_category in "${test_categories[@]}"; do
    IFS=':' read -r test_file test_name category <<< "$test_category"

    ((total_tests++))

    if ! run_regression_test "$test_file" "$test_name" "$category"; then
        ((failed_tests++))
    fi
done

# 結果分析と比較
echo "" | tee -a "$REPORT_FILE"
echo "========================================" | tee -a "$REPORT_FILE"
echo "📊 リグレッションテスト結果分析" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"
echo "総テスト数: $total_tests" | tee -a "$REPORT_FILE"
echo "成功テスト: $((total_tests - failed_tests))" | tee -a "$REPORT_FILE"
echo "失敗テスト: $failed_tests" | tee -a "$REPORT_FILE"
echo "成功率: $(( (total_tests - failed_tests) * 100 / total_tests ))%" | tee -a "$REPORT_FILE"
echo "完了時刻: $(date)" | tee -a "$REPORT_FILE"

# ベースライン比較
if [ "$COMPARE_MODE" = true ] && [ -n "$BASELINE_REPORT" ] && [ -f "$BASELINE_REPORT" ]; then
    echo "" | tee -a "$REPORT_FILE"
    echo "🔍 ベースライン比較" | tee -a "$REPORT_FILE"
    echo "----------------------------------------" | tee -a "$REPORT_FILE"

    baseline_failures=$(grep "失敗テスト:" "$BASELINE_REPORT" | grep -o "[0-9]*" || echo "0")

    echo "前回失敗数: $baseline_failures" | tee -a "$REPORT_FILE"
    echo "今回失敗数: $failed_tests" | tee -a "$REPORT_FILE"

    if [ $failed_tests -gt $baseline_failures ]; then
        echo "🚨 回帰バグ検出: 失敗テスト数が増加しました" | tee -a "$REPORT_FILE"
    elif [ $failed_tests -lt $baseline_failures ]; then
        echo "✅ 改善検出: 失敗テスト数が減少しました" | tee -a "$REPORT_FILE"
    else
        echo "➡️ 変化なし: 失敗テスト数は前回と同じです" | tee -a "$REPORT_FILE"
    fi
fi

# 最終判定
if [ $failed_tests -eq 0 ]; then
    echo "" | tee -a "$REPORT_FILE"
    echo "🎉 リグレッションテスト成功！" | tee -a "$REPORT_FILE"
    echo "すべての既存機能が正常に動作しています。" | tee -a "$REPORT_FILE"

    if [ "$COMPARE_MODE" = true ]; then
        echo "📋 このレポートを次回のベースラインとして保存してください：" | tee -a "$REPORT_FILE"
        echo "cp $REPORT_FILE $LOG_DIR/baseline-regression-report.txt" | tee -a "$REPORT_FILE"
    fi

    exit 0
else
    echo "" | tee -a "$REPORT_FILE"
    echo "⚠️ リグレッションテスト失敗" | tee -a "$REPORT_FILE"
    echo "$failed_tests 個のテストで問題が発見されました。" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "🔧 推奨アクション:" | tee -a "$REPORT_FILE"
    echo "1. 失敗したテストを個別に実行: ./run-individual-test.sh [番号] --debug" | tee -a "$REPORT_FILE"
    echo "2. 最近の変更を確認: git log --oneline -10" | tee -a "$REPORT_FILE"
    echo "3. 変更前の状態でテスト実行してベースラインを確認" | tee -a "$REPORT_FILE"

    exit 1
fi