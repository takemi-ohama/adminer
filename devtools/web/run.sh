#!/bin/bash
set -e

# 認証ファイルを取り込む
cp /etc/google_credentials.json /home/ubuntu/work/adminer/container/tests/google_credentials.json

# Start the test environment
cd "$(dirname "$0")"
docker compose up -d adminer-bigquery-test

echo "Adminer BigQuery test container is running at: http://adminer-bigquery-test:80"
echo "You can also access it locally at: http://localhost:8080"
