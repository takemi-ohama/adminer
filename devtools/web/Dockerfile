# Build stage - Adminer本体の取得と依存関係インストール
FROM public.ecr.aws/docker/library/php:8.3-apache AS build

# ビルド用ツールのインストール
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && docker-php-ext-install pdo \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 作業ディレクトリ設定
WORKDIR /app

# Adminer本体をclone（軽量化のため shallow clone）
# 当リポジトリは上流のプラグインとして動くため、既定では固定せず上流の既定ブランチの
# 最新に追従する。上流の構成変更でビルドが壊れた場合はその都度追従する。
# 再現性が必要なビルド（過去コミットの再デプロイ、上流破損時の暫定回避）では
# --build-arg ADMINER_REF=<タグ/ブランチ> でリビジョンを固定できる。
ARG ADMINER_REF=
RUN git clone --depth 1 ${ADMINER_REF:+--branch "$ADMINER_REF"} https://github.com/vrana/adminer.git /tmp/adminer-src

# Adminer本体のsubmoduleをinstall
RUN cd /tmp/adminer-src && git submodule update --init --recursive

# Adminer本体をすべてコピー（全てのincludeファイルとディレクトリ）
# 上流6.0.1でexternals/は廃止され、jushはadminer/static/jushへ移動した。
# ただし ADMINER_REF で6.0.1未満（v6.0.0以前）を指定した場合は旧構成のため、
# externals/が存在する場合のみ併せてコピーする（JsShrink/PhpShrinkはcompile.php専用で
# 実行時には不要だが、旧構成との差分を作らないため一括でコピーする）。
# production側の COPY を常に成立させるため、旧構成でない場合も空ディレクトリを作る。
# ドットファイル取りこぼしを避けるため glob ではなく `.` 指定でコピーする。
# 上流の構成が再び変わった場合にビルド時点で失敗させるため、必須ファイルの存在を検証する。
RUN mkdir -p /app/adminer /app/externals && \
    cp -a /tmp/adminer-src/adminer/. /app/adminer/ && \
    if [ -d /tmp/adminer-src/externals ]; then \
        cp -a /tmp/adminer-src/externals/. /app/externals/; \
    fi && \
    if [ ! -f /app/adminer/index.php ]; then \
        echo "Error: /app/adminer/index.php not found (upstream layout changed?)" >&2; \
        exit 1; \
    fi && \
    if [ ! -d /app/adminer/static/jush ] && [ ! -d /app/externals/jush ]; then \
        echo "Error: jush not found in adminer/static/jush (>=6.0.1) nor externals/jush (<6.0.1);" >&2; \
        echo "       upstream layout changed, or submodules were not initialized." >&2; \
        exit 1; \
    fi

# プロジェクト固有ファイルをコピー
COPY plugins/ /app/plugins/
COPY composer.json /app/
COPY composer.lock /app/

# 依存関係インストール
RUN composer install --no-dev --optimize-autoloader

# Production stage - 本番用最小イメージ
FROM public.ecr.aws/docker/library/php:8.3-apache AS production

# 本番用最小限パッケージのインストール
RUN apt-get update \
    && docker-php-ext-install pdo \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# 作業ディレクトリ設定
WORKDIR /var/www/html

# ビルドステージから必要ファイルをコピー
COPY --from=build /app/adminer ./adminer
# 上流6.0.1以降は空ディレクトリになる（旧構成のADMINER_REF指定時のみ実体を持つ）
COPY --from=build /app/externals ./externals
COPY --from=build /app/plugins ./plugins
COPY --from=build /app/vendor ./vendor

# 設定ファイルをコピー
COPY devtools/web/index.php ./
COPY devtools/web/.htaccess ./
COPY devtools/web/php.ini /usr/local/etc/php/php.ini
COPY devtools/web/apache-custom.conf /etc/apache2/conf-available/

# Apache設定
RUN a2enmod rewrite && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf && \
    a2enconf apache-custom

# BigQuery設定用環境変数
ENV HOME=/var/www
ENV COMPOSER_HOME=/tmp/composer

# Adminer staticファイルのシンボリックリンク作成（404エラー解決）
RUN ln -sf /var/www/html/adminer/static /var/www/html/static

# 権限設定
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
