# Adminer BigQuery Plugin

**Adminer BigQuery Plugin** is a driver plugin that enables Adminer to connect and interact with Google BigQuery.
It provides essential database operations including browsing datasets, tables, views, executing SQL queries, and paginating results.

## BigQuery Plugin Overview

This plugin allows you to connect to BigQuery through Adminer's web interface and provides the following features:

- **Dataset & Table Listing**: Browse datasets and tables within BigQuery projects through Adminer's UI
- **Schema Display**: View table column structure, data types, and descriptions
- **SQL Query Execution**: Execute Standard SQL for data extraction and aggregation
- **Result Pagination**: Scroll and paginate through large datasets
- **Query Plan Review**: BigQuery DRY RUN for execution cost estimation

## Installation

### Using Docker Image (Recommended)

```bash
# Pull and run from GitHub Container Registry
docker run -p 8080:80 \
  -e GOOGLE_CLOUD_PROJECT=your-project-id \
  -e GOOGLE_APPLICATION_CREDENTIALS=/app/service-account.json \
  -v /path/to/your/service-account.json:/app/service-account.json:ro \
  ghcr.io/takemi-ohama/adminer-bigquery:latest
```

### Building Locally

```bash
# Clone the repository
git clone https://github.com/takemi-ohama/adminer-bigquery.git
cd adminer-bigquery

# Build Docker image
docker build -f devtools/web/Dockerfile -t adminer-bigquery .

# Run container
docker run -p 8080:80 \
  -e GOOGLE_CLOUD_PROJECT=your-project-id \
  -e GOOGLE_APPLICATION_CREDENTIALS=/app/service-account.json \
  -v /path/to/your/service-account.json:/app/service-account.json:ro \
  adminer-bigquery
```

## Setup

### 1. BigQuery Authentication

To use the BigQuery plugin, you need a service account key:

```bash
# Create service account key in Google Cloud Console
# Grant BigQuery Data Viewer, BigQuery Job User permissions
# Download JSON key file
```

### 2. Environment Variables

```bash
export GOOGLE_CLOUD_PROJECT="your-project-id"
export GOOGLE_APPLICATION_CREDENTIALS="/path/to/service-account.json"
```

### 3. Adminer Connection Settings

- **Server**: `your-project-id` (BigQuery project ID)
- **Username**: (leave blank)
- **Password**: (leave blank)
- **Database**: `dataset-name` (BigQuery dataset name)

## Usage

1. Access `http://localhost:8080` in your browser
2. Select "BigQuery" on the login screen
3. Enter BigQuery project ID in the server field
4. Click "Login"
5. Browse datasets and tables from the listing
6. Execute queries from "SQL command"

### Query Examples

```sql
-- List tables
SELECT * FROM `project.dataset.INFORMATION_SCHEMA.TABLES`;

-- Fetch data
SELECT * FROM `project.dataset.table_name` LIMIT 100;

-- Aggregation query
SELECT COUNT(*) as total FROM `project.dataset.table_name`;
```

## Docker Images (ghcr.io)

### Pull Command

```bash
docker pull ghcr.io/takemi-ohama/adminer-bigquery:latest
```

### Available Tags

- `latest`: Latest stable version (master branch)
- `master-<SHA>`: Specific commit builds
- `<branch>`: Branch-specific builds

### Usage Example

```bash
# Run in background
docker run -d --name adminer-bigquery \
  -p 8080:80 \
  -e GOOGLE_CLOUD_PROJECT=my-bigquery-project \
  -e GOOGLE_APPLICATION_CREDENTIALS=/app/key.json \
  -v ~/service-account-key.json:/app/key.json:ro \
  ghcr.io/takemi-ohama/adminer-bigquery:latest

# Check logs
docker logs adminer-bigquery
```

## Repository Structure

```
adminer-bigquery/
├── plugins/                     # BigQuery plugin
│   ├── drivers/bigquery.php     # BigQuery driver implementation
│   └── login-bigquery.php       # BigQuery authentication plugin
├── devtools/                    # Development and build tools
│   ├── web/                     # Docker image configuration
│   │   ├── Dockerfile           # Production Dockerfile
│   │   ├── index.php            # Adminer configuration
│   │   ├── .htaccess            # Apache configuration
│   │   └── php.ini              # PHP configuration
│   ├── e2e/                     # E2E testing environment
│   └── docs/                    # Development documentation
├── .github/workflows/           # GitHub Actions
│   └── publish-docker.yml       # Automatic Docker image publishing
└── CLAUDE.md                    # Development instructions (AI)
```

## Development Guide

### Development Environment Setup

```bash
# Install dependencies
composer install

# Start development server
cd devtools/web
docker compose up --build -d

# Run E2E tests
cd ../e2e
./scripts/run-all-tests.sh
```

### Plugin Development

BigQuery driver extension and modification:

1. `plugins/drivers/bigquery.php` - Main driver logic
2. `plugins/login-bigquery.php` - Authentication and connection handling
3. `devtools/web/index.php` - Adminer integration configuration

### Running Tests

```bash
# Reference tests (safe)
./scripts/run-reference-tests.sh

# CRUD tests (creates new datasets)
./scripts/run-crud-tests.sh

# Comprehensive tests
./scripts/run-all-tests.sh
```

### Debugging

```bash
# Check container logs
docker logs adminer-bigquery-web

# PHP error logs
docker exec adminer-bigquery-web tail -f /var/log/apache2/error.log

# BigQuery query history
# Google Cloud Console > BigQuery > Query history
```

---

## About Adminer Core

**Adminer** is a full-featured database management tool written in PHP. It consists of a single file ready to deploy to the target server.
**Adminer Editor** offers data manipulation for end-users.

[Official Website](https://www.adminer.org/)

## Core Features
- **Supports:** MySQL, MariaDB, PostgreSQL, CockroachDB, SQLite, MS SQL, Oracle
- **Plugins for:** Elasticsearch, SimpleDB, MongoDB, Firebird, ClickHouse, IMAP
- **Requirements:** PHP 5.3+ (compiled file), PHP 7.4+ (source codes)

## Screenshot
![Table structure](https://www.adminer.org/static/screenshots/table.png)

## Core Installation
If downloaded from Git then run: `git submodule update --init`

- `adminer/index.php` - Run development version of Adminer
- `editor/index.php` - Run development version of Adminer Editor
- `editor/example.php` - Example customization
- `compile.php` - Create a single file version
- `lang.php` - Update translations
- `tests/*.html` - Katalon Recorder test suites

## Core Plugins
There are several plugins distributed with Adminer, as well as many user-contributed plugins listed on the [Adminer Plugins page](https://www.adminer.org/plugins/).
