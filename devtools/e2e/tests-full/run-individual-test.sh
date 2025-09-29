#!/bin/bash

# BigQuery Adminer E2E 個別テスト実行スクリプト
# 指定されたテストファイルを個別に実行し、詳細レポートを生成

set -e

# 使用方法チェック
if [ $# -eq 0 ]; then
    echo "使用方法: $0 <test_file_number> [オプション]"
    echo ""
    echo "利用可能なテストファイル："
    echo "  1  01-authentication-login.spec.js          (認証・ログイン)"
    echo "  2  02-database-dataset-operations.spec.js   (データセット操作)"
    echo "  3  03-table-schema-operations.spec.js       (テーブル・スキーマ操作)"
    echo "  4  04-sql-query-execution.spec.js           (SQLクエリ実行)"
    echo "  5  05-data-modification.spec.js             (データ変更操作)"
    echo "  6  06-ui-navigation-menu.spec.js            (UI・ナビゲーション・メニュー)"
    echo "  7  07-import-export.spec.js                 (インポート・エクスポート)"
    echo ""
    echo "オプション:"
    echo "  --debug      デバッグモードで実行"
    echo "  --headed     ヘッドありモードで実行"
    echo "  --screenshot スクリーンショット保存"
    echo ""
    echo "例："
    echo "  $0 1                # 認証テストを実行"
    echo "  $0 4 --debug        # SQLクエリテストをデバッグモードで実行"
    echo "  $0 6 --headed       # UIテストをブラウザ表示で実行"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$SCRIPT_DIR"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_DIR="$TEST_DIR/test-results"
mkdir -p "$LOG_DIR"

# テストファイルマッピング
declare -A test_files=(
    [1]="01-authentication-login.spec.js"
    [2]="02-database-dataset-operations.spec.js"
    [3]="03-table-schema-operations.spec.js"
    [4]="04-sql-query-execution.spec.js"
    [5]="05-data-modification.spec.js"
    [6]="06-ui-navigation-menu.spec.js"
    [7]="07-import-export.spec.js"
)

declare -A test_names=(
    [1]="認証・ログイン"
    [2]="データセット操作"
    [3]="テーブル・スキーマ操作"
    [4]="SQLクエリ実行"
    [5]="データ変更操作"
    [6]="UI・ナビゲーション・メニュー"
    [7]="インポート・エクスポート"
)

# 引数解析
test_number="$1"
shift

# テストファイル取得
test_file="${test_files[$test_number]}"
test_name="${test_names[$test_number]}"

if [ -z "$test_file" ]; then
    echo "❌ 無効なテストファイル番号: $test_number"
    exit 1
fi

if [ ! -f "$test_file" ]; then
    echo "❌ テストファイルが見つかりません: $test_file"
    exit 1
fi

# Playwrightオプション構築
playwright_opts=""
report_suffix=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --debug)
            playwright_opts="$playwright_opts --debug"
            report_suffix="${report_suffix}_debug"
            shift
            ;;
        --headed)
            playwright_opts="$playwright_opts --headed"
            report_suffix="${report_suffix}_headed"
            shift
            ;;
        --screenshot)
            playwright_opts="$playwright_opts --reporter=html"
            report_suffix="${report_suffix}_screenshot"
            shift
            ;;
        *)
            echo "❌ 未知のオプション: $1"
            exit 1
            ;;
    esac
done

REPORT_FILE="$LOG_DIR/individual-test-${test_number}${report_suffix}-$TIMESTAMP.txt"

echo "🧪 BigQuery Adminer E2E 個別テスト実行" | tee "$REPORT_FILE"
echo "========================================" | tee -a "$REPORT_FILE"
echo "テスト番号: $test_number" | tee -a "$REPORT_FILE"
echo "テスト名: $test_name" | tee -a "$REPORT_FILE"
echo "テストファイル: $test_file" | tee -a "$REPORT_FILE"
echo "実行時刻: $(date)" | tee -a "$REPORT_FILE"
echo "オプション: $playwright_opts" | tee -a "$REPORT_FILE"
echo "----------------------------------------" | tee -a "$REPORT_FILE"

# 事前チェック
echo "🔍 事前環境チェック..." | tee -a "$REPORT_FILE"

# Docker コンテナチェック
if ! docker ps | grep -q "adminer-bigquery-test"; then
    echo "⚠️ Adminer Web コンテナが実行されていません。" | tee -a "$REPORT_FILE"
    echo "以下のコマンドで起動してください：" | tee -a "$REPORT_FILE"
    echo "cd ../../container/web && docker compose up -d" | tee -a "$REPORT_FILE"
fi

# HTTP接続チェック
if curl -sSf http://localhost:8080 > /dev/null 2>&1; then
    echo "✅ Adminer Web サーバー接続確認" | tee -a "$REPORT_FILE"
else
    echo "❌ Adminer Web サーバーに接続できません" | tee -a "$REPORT_FILE"
fi

echo "" | tee -a "$REPORT_FILE"

# テスト実行
echo "🚀 [$test_name] テスト実行開始..." | tee -a "$REPORT_FILE"

if npx playwright test "$test_file" $playwright_opts --reporter=line 2>&1 | tee -a "$REPORT_FILE"; then
    echo "" | tee -a "$REPORT_FILE"
    echo "✅ [$test_name] テスト実行完了" | tee -a "$REPORT_FILE"
    echo "📄 詳細レポート: $REPORT_FILE" | tee -a "$REPORT_FILE"
    exit 0
else
    echo "" | tee -a "$REPORT_FILE"
    echo "❌ [$test_name] テスト実行失敗" | tee -a "$REPORT_FILE"
    echo "📄 エラーレポート: $REPORT_FILE" | tee -a "$REPORT_FILE"
    echo "" | tee -a "$REPORT_FILE"
    echo "🔧 トラブルシューティング:" | tee -a "$REPORT_FILE"
    echo "1. Web環境が正常に起動しているか確認" | tee -a "$REPORT_FILE"
    echo "2. BigQuery認証設定が正しく設定されているか確認" | tee -a "$REPORT_FILE"
    echo "3. ブラウザ表示モードで実行: $0 $test_number --headed" | tee -a "$REPORT_FILE"
    echo "4. デバッグモードで実行: $0 $test_number --debug" | tee -a "$REPORT_FILE"
    exit 1
fi