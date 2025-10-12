# E2Eテスト実行用Dockerfile - スクリプト指定実行型
FROM mcr.microsoft.com/playwright:v1.48.0-focal

# 必要なパッケージをインストール
RUN apt-get update && apt-get install -y \
    curl \
    jq \
    git \
    && rm -rf /var/lib/apt/lists/*

# Node.js環境のセットアップ
WORKDIR /app

# E2E設定ファイル、テストファイル、スクリプトファイルをコピー
COPY devtools/e2e/package.json /usr/local/src/devtools/e2e/package.json

# npmパッケージのインストール
RUN cd /usr/local/src/devtools/e2e && npm install

# Playwrightブラウザをインストール
RUN cd /usr/local/src/devtools/e2e && npx playwright install chromium firefox webkit

# 環境変数設定
ENV NODE_ENV=test
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
COPY devtools/e2e/playwright.config.js /usr/local/src/devtools/e2e/playwright.config.js
COPY devtools/e2e/tests/ /usr/local/src/devtools/e2e/tests/
COPY devtools/e2e/tests-full/ /usr/local/src/devtools/e2e/tests-full/
COPY devtools/e2e/scripts/ /usr/local/src/devtools/e2e/scripts/
COPY devtools/e2e/export-fix-test.js /usr/local/src/devtools/e2e/export-fix-test.js

# エントリーポイントスクリプトをコピー
COPY devtools/e2e/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# エントリーポイント設定
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
