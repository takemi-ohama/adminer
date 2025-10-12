#!/bin/bash
set -e

echo "🚀 E2E テスト実行開始: $(date)" >&2
echo "📦 E2E環境セットアップ中..." >&2

# E2E環境のセットアップ（統一された作業ディレクトリ使用）
echo "📦 E2E環境は既にセットアップ済みです（Docker統一ディレクトリ使用）" >&2
echo "📁 作業ディレクトリ: /app/devtools/e2e" >&2

echo "環境: $NODE_ENV" >&2
echo "ベースURL: $BASE_URL" >&2

# テスト結果保存ディレクトリを作成
mkdir -p ./test-results
echo "📁 テスト結果保存先: /app/devtools/e2e/test-results" >&2

# コンテナ内ファイルを指定してスクリプトまたはテストを実行
if [ -f "$1" ]; then
  echo "📋 ファイル実行: $1" >&2
  # .spec.jsファイルの場合はPlaywrightテストとして実行
  if [[ "$1" == *.spec.js ]]; then
    echo "📋 Playwrightテスト実行: $1" >&2
    # テストファイルの相対パスを計算して実行
    TEST_FILE=$(basename "$1")
    npx playwright test "tests-full/$TEST_FILE" --project=chromium --reporter=line
  else
    echo "📋 スクリプトファイル実行: $1" >&2
    # 通常のスクリプトファイルを実行
    bash "$1"
  fi
elif [ "$1" != "" ]; then
  echo "📋 直接コマンド実行: $*" >&2
  # コマンド実行結果を標準出力に出力
  exec "$@"
else
  echo "📋 全テスト実行（デフォルト）" >&2
  # 全テストを実行
  npx playwright test --reporter=line
fi

echo "✅ E2E テスト実行完了: $(date)" >&2