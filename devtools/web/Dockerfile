FROM php:8.3-apache

# Install necessary packages and extensions
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    git \
    make \
    && docker-php-ext-install pdo \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy the adminer project structure
COPY adminer/ ./adminer/
COPY plugins/ ./plugins/
COPY externals/ ./externals/
COPY vendor/ ./vendor/
COPY composer.json ./
COPY composer.lock ./
COPY Makefile ./

# Copy test-specific files to the web root
COPY container/web/index.php ./
COPY container/web/.htaccess ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Setup Apache configuration
RUN a2enmod rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# PHP設定の最適化
RUN echo "date.timezone = UTC" >> /usr/local/etc/php/php.ini-production && \
    echo "memory_limit = 512M" >> /usr/local/etc/php/php.ini-production && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/php.ini-production && \
    echo "max_input_vars = 50000" >> /usr/local/etc/php/php.ini-production && \
    echo "max_input_nesting_level = 128" >> /usr/local/etc/php/php.ini-production && \
    echo "apc.enabled=1" >> /usr/local/etc/php/php.ini-production && \
    echo "apc.shm_size=64M" >> /usr/local/etc/php/php.ini-production && \
    echo "apc.ttl=7200" >> /usr/local/etc/php/php.ini-production && \
    echo "apc.enable_cli=1" >> /usr/local/etc/php/php.ini-production && \
    cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# BigQuery設定用の環境変数
ENV HOME=/var/www
ENV COMPOSER_HOME=/tmp/composer

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Expose port 80
EXPOSE 80
