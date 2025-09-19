#!/bin/bash
set -e

# Stop and remove the test environment
cd "$(dirname "$0")"
docker compose down -v