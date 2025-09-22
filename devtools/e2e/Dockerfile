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

# Playwrightブラウザをインストール
RUN npx playwright install chromium firefox webkit

# 環境変数設定
ENV NODE_ENV=test
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

# E2E設定ファイル、テストファイル、スクリプトファイルをコピー
COPY container/e2e/package.json /usr/local/src/container/e2e/package.json
COPY container/e2e/playwright.config.js /usr/local/src/container/e2e/playwright.config.js
COPY container/e2e/tests/ /usr/local/src/container/e2e/tests/
COPY container/e2e/scripts/ /usr/local/src/container/e2e/scripts/

# エントリーポイントスクリプトをコピー
COPY container/e2e/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# エントリーポイント設定
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
