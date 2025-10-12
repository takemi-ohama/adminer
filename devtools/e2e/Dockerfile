# E2Eテスト実行用Dockerfile - スクリプト指定実行型
FROM mcr.microsoft.com/playwright:v1.48.1-focal

# 必要なパッケージをインストール
RUN apt-get update && apt-get install -y \
    curl \
    jq \
    git \
    && rm -rf /var/lib/apt/lists/*

# Node.js環境のセットアップ - 作業ディレクトリ統一
WORKDIR /app/devtools/e2e

# E2E設定ファイル、テストファイル、スクリプトファイルをコピー
COPY devtools/e2e/package.json ./package.json

# npmパッケージのインストール
RUN npm install

# Playwrightブラウザをインストール
RUN npx playwright install chromium firefox webkit

# 環境変数設定
ENV NODE_ENV=test
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
COPY devtools/e2e/playwright.config.js ./playwright.config.js
COPY devtools/e2e/tests/ ./tests/
COPY devtools/e2e/tests-full/ ./tests-full/
COPY devtools/e2e/scripts/ ./scripts/
COPY devtools/e2e/export-fix-test.js ./export-fix-test.js

# エントリーポイントスクリプトをコピー
COPY devtools/e2e/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# エントリーポイント設定
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
