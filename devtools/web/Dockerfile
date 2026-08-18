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
# 当リポジトリは上流のプラグインとして動くため、バージョンは固定せず常に最新に追従する。
# 上流の構成変更でビルドが壊れた場合はその都度追従する。
RUN git clone --depth 1 https://github.com/vrana/adminer.git /tmp/adminer-src

# Adminer本体のsubmoduleをinstall
RUN cd /tmp/adminer-src && git submodule update --init --recursive

# Adminer本体をすべてコピー（全てのincludeファイルとディレクトリ）
# 上流6.0.1でexternals/は廃止され、jushはadminer/static/jushへ移動したため
# adminer/のコピーだけで足りる。JsShrink/PhpShrinkはcompile.php専用で実行時には不要。
RUN mkdir -p /app/adminer && \
    cp -r /tmp/adminer-src/adminer/* /app/adminer/

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
