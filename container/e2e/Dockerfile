# E2Eテスト実行用Dockerfile
FROM mcr.microsoft.com/playwright:v1.48.1-focal

# 必要なパッケージをインストール
RUN apt-get update && apt-get install -y \
    curl \
    jq \
    git \
    && rm -rf /var/lib/apt/lists/*

# Node.js環境のセットアップ
WORKDIR /app

# Playwright設定
RUN npm install -g @playwright/test
RUN npx playwright install chromium firefox webkit

# テストファイル用ディレクトリ作成
RUN mkdir -p /app/tests

# 環境変数設定
ENV NODE_ENV=test
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

# デフォルトコマンド
CMD ["npx", "playwright", "test"]