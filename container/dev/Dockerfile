FROM ubuntu:noble

ENV DEBIAN_FRONTEND=noninteractive

# システムの更新とDocker Composeのインストールに必要なパッケージのインストール
RUN --mount=type=cache,target=/var/lib/apt,sharing=locked \
    --mount=type=cache,target=/var/cache/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    locales git wget unzip vim sudo nano \
    curl apt-transport-https ca-certificates gnupg lsb-release \
    # Playwright/Chrome依存関係
    libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0 \
    libxcomposite1 libxdamage1 libxrandr2 libgbm1 \
    libxss1 libasound2t64 \
    && \
    # Dockerリポジトリの設定
    install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg && \
    chmod a+r /etc/apt/keyrings/docker.gpg && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" > /etc/apt/sources.list.d/docker.list && \
    # Node.js v20 LTS リポジトリの設定
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - && \
    apt-get update && \
    apt-get install -y docker-ce docker-ce-cli containerd.io  \
    docker-buildx-plugin docker-compose-plugin nodejs  && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

RUN  locale-gen en_US.UTF-8 && \
    update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8

COPY --from=ghcr.io/astral-sh/uv:latest /uv /uvx /bin/

# aws cli
RUN curl "https://awscli.amazonaws.com/awscli-exe-linux-$(uname -m).zip" -o "awscliv2.zip" && \
    unzip -q awscliv2.zip && \
    ./aws/install && \
    rm -rf aws awscliv2.zip

# github cli
RUN curl -sS https://webi.sh/gh | sh

# PHP 8.x と関連拡張のインストール (BigQuery driver plugin開発用)
RUN --mount=type=cache,target=/var/lib/apt,sharing=locked \
    --mount=type=cache,target=/var/cache/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    # ビルド必須ツール
    make build-essential \
    # PHP 8.x とWeb開発に必要な拡張
    php8.3 php8.3-cli php8.3-common php8.3-curl php8.3-mbstring \
    php8.3-xml php8.3-zip php8.3-intl php8.3-bcmath \
    # APCu拡張のインストール
    php8.3-dev php-pear \
    # Composer (PHP依存関係管理)
    composer \
    # Web開発用
    apache2 libapache2-mod-php8.3 \
    && \
    # APCu拡張のインストール
    pecl install apcu && \
    echo "extension=apcu.so" >> /etc/php/8.3/cli/php.ini && \
    echo "extension=apcu.so" >> /etc/php/8.3/apache2/php.ini && \
    # PHP設定の最適化
    echo "date.timezone = UTC" >> /etc/php/8.3/cli/php.ini && \
    echo "memory_limit = 512M" >> /etc/php/8.3/cli/php.ini && \
    echo "max_execution_time = 300" >> /etc/php/8.3/cli/php.ini && \
    # APCu設定
    echo "apc.enabled=1" >> /etc/php/8.3/cli/php.ini && \
    echo "apc.shm_size=64M" >> /etc/php/8.3/cli/php.ini && \
    echo "apc.ttl=7200" >> /etc/php/8.3/cli/php.ini && \
    echo "apc.enable_cli=1" >> /etc/php/8.3/cli/php.ini && \
    # Apache用APCu設定
    echo "apc.enabled=1" >> /etc/php/8.3/apache2/php.ini && \
    echo "apc.shm_size=64M" >> /etc/php/8.3/apache2/php.ini && \
    echo "apc.ttl=7200" >> /etc/php/8.3/apache2/php.ini && \
    # Apache設定 (開発用)
    a2enmod php8.3 && \
    a2enmod rewrite && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

RUN npm install -g  \
    @anthropic-ai/claude-code \
    aws-cdk aws-cdk-lib typescript @playwright/test && \
    npx playwright install chrome && \
    npm cache clean --force

# gcloud CLI
RUN curl https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-linux-$(uname -m).tar.gz | tar -xzf - -C /opt && \
    /opt/google-cloud-sdk/install.sh --quiet --path-update=true && \
    ln -s /opt/google-cloud-sdk/bin/gcloud /usr/local/bin/gcloud && \
    ln -s /opt/google-cloud-sdk/bin/gsutil /usr/local/bin/gsutil && \
    ln -s /opt/google-cloud-sdk/bin/bq /usr/local/bin/bq

# terraform
RUN wget -O- https://apt.releases.hashicorp.com/gpg | gpg --dearmor | tee /usr/share/keyrings/hashicorp-archive-keyring.gpg > /dev/null && \
    echo "deb [signed-by=/usr/share/keyrings/hashicorp-archive-keyring.gpg] https://apt.releases.hashicorp.com $(lsb_release -cs) main" | tee /etc/apt/sources.list.d/hashicorp.list && \
    apt update && apt install -y terraform

ARG USERNAME="ubuntu"

RUN usermod -aG users $USERNAME && \
    echo '%users ALL=(ALL:ALL) NOPASSWD: ALL' > /etc/sudoers.d/users && \
    echo "$USERNAME ALL=(ALL:ALL) NOPASSWD: ALL" > /etc/sudoers.d/$USERNAME && \
    chmod 0440 /etc/sudoers.d/$USERNAME

USER ${USERNAME}
WORKDIR /home/${USERNAME}/work
VOLUME  /home/${USERNAME}/work

# BigQuery開発用の環境設定
ENV PHP_CLI_SERVER_WORKERS=4
ENV GOOGLE_APPLICATION_CREDENTIALS=""

# PHPエイリアス設定 (開発効率向上)
RUN echo 'alias php-serve="php -S localhost:8080"' >> ~/.bashrc && \
    echo 'alias composer-install="composer install --no-dev --optimize-autoloader"' >> ~/.bashrc && \
    echo 'alias adminer-serve="cd /home/${USERNAME}/work/adminer && php -S localhost:8080"' >> ~/.bashrc

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN git config --global credential.helper store

ENTRYPOINT [ "entrypoint.sh" ]

