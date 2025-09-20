# E2Eテスト実行用Dockerfile - スクリプト指定実行型
FROM mcr.microsoft.com/playwright:v1.48.1-focal

# 必要なパッケージをインストール
RUN apt-get update && apt-get install -y \
    curl \
    jq \
    git \
    && rm -rf /var/lib/apt/lists/*

# Node.js環境のセットアップ
WORKDIR /app

# package.jsonをコピーして依存関係をインストール
COPY container/e2e/package.json ./
COPY container/e2e/playwright.config.js ./

# ローカル依存関係をインストール
RUN npm install

# Playwrightブラウザをインストール
RUN npx playwright install chromium firefox webkit

# テストファイル用ディレクトリ作成
RUN mkdir -p /app/tests
RUN mkdir -p /app/scripts

# 基本フローテストファイルをコピー
COPY container/e2e/tests/basic-flow-test.spec.js /app/tests/

# 環境変数設定
ENV NODE_ENV=test
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

# DooD対応エントリーポイントスクリプト作成（標準入出力のみ使用）
RUN echo '#!/bin/bash\n\
set -e\n\
\n\
echo "🚀 E2E テスト実行開始: $(date)" >&2\n\
echo "環境: $NODE_ENV" >&2\n\
echo "ベースURL: $BASE_URL" >&2\n\
\n\
# 標準入力からスクリプトを読み込んで実行\n\
if [ "$1" = "--stdin" ]; then\n\
  echo "📋 標準入力からスクリプト実行" >&2\n\
  # 標準入力を一時ファイルに保存\n\
  cat > /tmp/test_script.sh\n\
  chmod +x /tmp/test_script.sh\n\
  echo "📂 スクリプト保存完了、実行開始" >&2\n\
  # スクリプト実行結果を標準出力に出力\n\
  bash /tmp/test_script.sh\n\
elif [ "$1" != "" ]; then\n\
  echo "📋 直接コマンド実行: $*" >&2\n\
  # コマンド実行結果を標準出力に出力\n\
  exec "$@"\n\
else\n\
  echo "📋 全テスト実行（デフォルト）" >&2\n\
  # テスト結果を標準出力に出力\n\
  npx playwright test --reporter=line\n\
fi\n\
\n\
echo "✅ E2E テスト実行完了: $(date)" >&2' > /app/entrypoint.sh

# 実行権限付与
RUN chmod +x /app/entrypoint.sh

# エントリーポイント設定
ENTRYPOINT ["/app/entrypoint.sh"]