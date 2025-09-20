#!/bin/bash
set -e

echo "🚀 「テーブルを作成」エラー検出テスト実行開始"

# Web環境が起動していることを確認
echo "📡 Web環境確認中..."
if ! docker ps | grep -q "adminer-bigquery-test"; then
  echo "❌ Webコンテナが起動していません。先にWebコンテナを起動してください:"
  echo "   cd container/web && docker compose up -d"
  exit 1
fi
echo "✅ Web環境確認完了"

# E2Eコンテナでテスト実行
echo "🚀 「テーブルを作成」エラー検出テスト実行中..."

# scriptsディレクトリから実行するためにe2eディレクトリに移動
cd "$(dirname "$0")/.."

# create-table-error-test.jsを実行
docker compose run --rm playwright-e2e node /app/container/e2e/tests/create-table-error-test.js

echo "✅ 「テーブルを作成」エラー検出テスト完了: $(date)"
echo "🎯 「テーブルを作成」エラー検出テスト完了: $(date)"