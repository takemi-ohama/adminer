#!/bin/bash
set -e

# Build the test container
cd "$(dirname "$0")"
docker compose build adminer-bigquery-test
