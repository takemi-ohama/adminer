了解。
Adminer BigQuery Driver プラグインの **E2Eテスト手順書（Claude Code 直実行向け）** を用意しました。
「実ブラウザ＋本物の BigQuery（テスト用プロジェクト）」で、ログイン〜メタデータ閲覧〜SELECT実行〜ページング〜dryRun（EXPLAIN相当）〜DMLブロックまでを自動検証します。Playwright での自動化を前提に、**ワンコマンドで立ち上がる**構成を提示します。

---

# 0. 目的とスコープ

**目的**

* Adminer + BigQuery Driver プラグインを Docker で起動し、**UI操作を自動化**して以下を検証する：

  1. ログイン（`driver=bigquery`）
  2. データセット一覧表示
  3. テーブル一覧表示
  4. カラム定義の表示
  5. SELECT 実行（`LIMIT/OFFSET` ページング）
  6. `dryRun`（EXPLAIN相当）のスキャンバイト表示
  7. DML（INSERT/UPDATE/DELETE/DDL）の**ブロック**（MVP方針が守られているか）

**非目的（今回はE2E外）**

* LOAD/EXPORT ジョブのUI
* 長時間ジョブ・大量データでの性能計測

---

# 1. 前提・前準備

* テスト用 **GCPプロジェクト**（課金有効）
* **サービスアカウント**（最低 `roles/bigquery.user` + `roles/bigquery.dataViewer`）
* **JSON鍵**をローカルに保存（例：`./secrets/key.json`）
* テスト用 **BigQuery資材**（この手順で自動作成）

  * データセット：`e2e_adminer_bq`
  * テーブル：`people`（行数：小規模）

> 認証は環境変数 `GOOGLE_APPLICATION_CREDENTIALS=/run/secrets/bq_key.json` として Docker コンテナに注入します。

---

# 2. リポジトリ構成（提案）

```
adminer-bq-driver/
├─ plugins/
│  └─ drivers/
│     └─ bigquery.php           # あなたのプラグイン
├─ adminer/                      # adminer 本体（git submodule or ダウンロード展開）
│  └─ index.php                  # adminer エントリ
├─ docker/
│  ├─ Dockerfile                 # Adminer + plugin を含む軽量イメージ
│  └─ entrypoint.sh              # 依存の存在確認など（任意）
├─ e2e/
│  ├─ playwright.config.ts
│  ├─ tests/
│  │  ├─ login.spec.ts
│  │  ├─ browse.spec.ts
│  │  ├─ select.spec.ts
│  │  ├─ explain.spec.ts
│  │  └─ dml_block.spec.ts
│  └─ package.json
├─ seed/
│  ├─ schema.sql                 # bq mk/table ddl 相当のメモ（ドキュメント用途）
│  └─ data.csv                   # 小さいサンプルデータ
├─ scripts/
│  ├─ bq_seed.sh                 # データセット/テーブル作成 & データ投入
│  └─ bq_cleanup.sh              # 後始末
├─ docker-compose.yml
├─ .env.example
└─ README.md
```

---

# 3. 環境変数（`.env`）

```
# GCP
GCP_PROJECT_ID=your-project-id
BQ_DATASET=e2e_adminer_bq
BQ_LOCATION=US

# Adminer (コンテナ側で使う)
ADMINER_PORT=8080

# 認証キーファイル（docker secret として渡す）
BQ_KEY_PATH=./secrets/key.json
```

`.env` をプロジェクト直下に作成し、実値を設定。

---

# 4. Docker 構成

## 4.1 `docker/Dockerfile`

```dockerfile
FROM php:8.3-apache

# system deps
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*

# adminer 配置（ビルドコンテキスト adminer/ を /var/www/html/adminer にコピー）
COPY adminer/ /var/www/html/adminer/

# プラグイン配置
COPY plugins/ /var/www/html/plugins/

# Adminer のプラグイン読み込み用ブートストラップ（必要なら）
# 例: /var/www/html/adminer/plugins-enabled.php を index.php から require する形にしておく
# （bigquery ドライバのautoloadをここで行う）
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/adminer
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && \
    a2enmod rewrite

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
```

## 4.2 `docker/entrypoint.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

# BigQuery 認証ファイルの存在確認（Docker secret 経由）
if [[ ! -f "/run/secrets/bq_key.json" ]]; then
  echo "Missing /run/secrets/bq_key.json"
  exit 1
fi

# Adminer 側で driver=bigquery を選べるよう、plugins-enabled を用意（例）
# 必要に応じて、adminer/index.php から include する
cat >/var/www/html/adminer/plugins-enabled.php <<'PHP'
<?php
// BigQuery Driver を有効化（例）
require_once __DIR__ . '/../plugins/drivers/bigquery.php';

// ログインフォームの拡張や既定値を設定する場合はここで
PHP

# 認証環境変数をWEBプロセスに伝える
export GOOGLE_APPLICATION_CREDENTIALS=/run/secrets/bq_key.json
exec "$@"
```

## 4.3 `docker-compose.yml`

```yaml
version: "3.9"
services:
  adminer-bq:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "${ADMINER_PORT:-8080}:80"
    environment:
      - GCP_PROJECT_ID=${GCP_PROJECT_ID}
      - BQ_DATASET=${BQ_DATASET}
      - BQ_LOCATION=${BQ_LOCATION}
      - GOOGLE_APPLICATION_CREDENTIALS=/run/secrets/bq_key.json
    secrets:
      - bq_key
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/"]
      interval: 5s
      timeout: 3s
      retries: 20

secrets:
  bq_key:
    file: ${BQ_KEY_PATH}
```

---

# 5. BigQuery へのシード投入

## 5.1 `seed/data.csv`

```
id,name,age,city,ts
1,Alice,31,Tokyo,2020-01-01T09:00:00Z
2,Bob,25,Osaka,2021-06-10T12:34:56Z
3,Carol,40,Nagoya,2022-03-15T00:00:00Z
4,Dan,29,Tokyo,2023-08-20T08:15:00Z
5,Eva,35,Fukuoka,2024-12-31T23:59:59Z
```

## 5.2 `scripts/bq_seed.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${GCP_PROJECT_ID:?}"
: "${BQ_DATASET:?}"
: "${BQ_LOCATION:?}"

gcloud config set project "$GCP_PROJECT_ID" >/dev/null

# dataset 作成（存在してもOK）
bq --location="${BQ_LOCATION}" --project_id="${GCP_PROJECT_ID}" \
  mk --dataset --default_table_expiration 0 --description "E2E Adminer BQ tests" \
  "${GCP_PROJECT_ID}:${BQ_DATASET}" || true

# テーブル作成
bq --location="${BQ_LOCATION}" --project_id="${GCP_PROJECT_ID}" \
  mk --table --description "people table for e2e" \
  "${BQ_DATASET}.people" \
  "id:INT64,name:STRING,age:INT64,city:STRING,ts:TIMESTAMP"

# データロード（CSV）
bq --location="${BQ_LOCATION}" --project_id="${GCP_PROJECT_ID}" \
  load --source_format=CSV --skip_leading_rows=1 \
  "${BQ_DATASET}.people" \
  "seed/data.csv"

# ビュー作成（ページング検証用）
bq query --use_legacy_sql=false --location="${BQ_LOCATION}" --project_id="${GCP_PROJECT_ID}" <<'SQL'
CREATE OR REPLACE VIEW `${BQ_DATASET}.people_tokyo` AS
SELECT * FROM `${BQ_DATASET}.people` WHERE city = "Tokyo" ORDER BY id;
SQL

echo "Seed completed."
```

> 事前に `gcloud auth application-default login` などでローカル認証を通しておくか、サービスアカウントで `gcloud auth activate-service-account --key-file secrets/key.json` を実行。

## 5.3 片付け `scripts/bq_cleanup.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

: "${GCP_PROJECT_ID:?}"
: "${BQ_DATASET:?}"
: "${BQ_LOCATION:?}"

gcloud config set project "$GCP_PROJECT_ID" >/dev/null
bq --location="${BQ_LOCATION}" --project_id="${GCP_PROJECT_ID}" \
  rm -r -f -d "${GCP_PROJECT_ID}:${BQ_DATASET}" || true

echo "Cleanup completed."
```

---

# 6. Adminer 起動

```bash
# 1) シード
export $(grep -v '^#' .env | xargs)
bash scripts/bq_seed.sh

# 2) 起動
docker compose up -d --build

# 3) ヘルス確認
curl -f http://localhost:${ADMINER_PORT}/ > /dev/null && echo "Adminer ready"
```

ブラウザで `http://localhost:8080/`（ポートは .env に合わせる）を開くと Adminer UI。

**ログイン想定（プラグインの仕様に合わせて微調整）**

* System/Driver: `bigquery`
* Server: `${GCP_PROJECT_ID}`（必要なら `project:location`）
* Username/Password: 空（サービスアカウントで認証）

---

# 7. Playwright による E2E 自動テスト

## 7.1 `e2e/package.json`

```json
{
  "name": "adminer-bq-e2e",
  "private": true,
  "devDependencies": {
    "@playwright/test": "^1.46.0"
  },
  "scripts": {
    "test": "playwright test --reporter=list"
  }
}
```

## 7.2 `e2e/playwright.config.ts`

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    headless: true
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } }
  ]
});
```

> 実行前に `BASE_URL` を `.env` のポートに合わせて指定してもOK。

## 7.3 共通ヘルパ `e2e/tests/_helpers.ts`

```ts
import { Page, expect } from '@playwright/test';

export async function loginBigQuery(page: Page, projectId: string) {
  await page.goto('/');
  // Adminerのログイン画面のセレクタはテーマにより変わるので、name属性やlabelテキストで安定化
  await page.getByLabel(/System|Driver/i).selectOption('bigquery'); // select要素想定
  await page.getByLabel(/Server/i).fill(projectId);
  // username/password は空のまま
  await page.getByRole('button', { name: /login|log in|サインイン/i }).click();

  // 成功すると「データベース(=dataset)一覧」画面に遷移する想定
  await expect(page.locator('body')).toContainText('Database'); // or "データベース"
}
```

> Adminer のマークアップ差異があるため、初回実行でセレクタの調整が必要になる場合があります（Claude Code に「実際のDOMを見て修正して」と指示してください）。

## 7.4 `e2e/tests/login.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loginBigQuery } from './_helpers';

const PROJECT = process.env.GCP_PROJECT_ID!;
test('can login with bigquery driver', async ({ page }) => {
  await loginBigQuery(page, PROJECT);
  await expect(page.locator('body')).toContainText(PROJECT);
});
```

## 7.5 `e2e/tests/browse.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loginBigQuery } from './_helpers';

const PROJECT = process.env.GCP_PROJECT_ID!;
const DATASET = process.env.BQ_DATASET!;

test('list datasets and tables', async ({ page }) => {
  await loginBigQuery(page, PROJECT);

  // データセット一覧に e2e_adminer_bq が出る
  await expect(page.locator('body')).toContainText(DATASET);

  // データセットをクリック → テーブル一覧に遷移
  await page.getByRole('link', { name: new RegExp(`\\b${DATASET}\\b`) }).click();

  // テーブル people / ビュー people_tokyo が見える
  await expect(page.locator('body')).toContainText('people');
  await expect(page.locator('body')).toContainText('people_tokyo');

  // カラム定義ページへ
  await page.getByRole('link', { name: /\bpeople\b/ }).click();
  await expect(page.locator('body')).toContainText('id');
  await expect(page.locator('body')).toContainText('name');
  await expect(page.locator('body')).toContainText('age');
  await expect(page.locator('body')).toContainText('ts');
});
```

## 7.6 `e2e/tests/select.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loginBigQuery } from './_helpers';

const PROJECT = process.env.GCP_PROJECT_ID!;
const DATASET = process.env.BQ_DATASET!;

test('run SELECT with LIMIT/OFFSET pagination', async ({ page }) => {
  await loginBigQuery(page, PROJECT);

  // クエリ実行ページへ（Adminer の UI に合わせて遷移）
  await page.getByRole('link', { name: new RegExp(`\\b${DATASET}\\b`) }).click();
  await page.getByRole('link', { name: /SQL|クエリ|Query/i }).click();

  const sql = `SELECT id, name FROM \`${DATASET}.people\` ORDER BY id LIMIT 2 OFFSET 0`;
  await page.getByRole('textbox', { name: /SQL|query/i }).fill(sql);
  await page.getByRole('button', { name: /Execute|Run|実行/i }).click();

  await expect(page.locator('body')).toContainText('Alice');
  await expect(page.locator('body')).toContainText('Bob');

  // 次ページ（OFFSET 2）
  await page.getByRole('textbox', { name: /SQL|query/i })
    .fill(`SELECT id, name FROM \`${DATASET}.people\` ORDER BY id LIMIT 2 OFFSET 2`);
  await page.getByRole('button', { name: /Execute|Run|実行/i }).click();

  await expect(page.locator('body')).toContainText('Carol');
  await expect(page.locator('body')).toContainText('Dan');
});
```

## 7.7 `e2e/tests/explain.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loginBigQuery } from './_helpers';

const PROJECT = process.env.GCP_PROJECT_ID!;
const DATASET = process.env.BQ_DATASET!;

test('dryRun shows estimated bytes', async ({ page }) => {
  await loginBigQuery(page, PROJECT);
  await page.getByRole('link', { name: new RegExp(`\\b${DATASET}\\b`) }).click();
  await page.getByRole('link', { name: /SQL|Query/i }).click();

  const sql = `SELECT * FROM \`${DATASET}.people\` WHERE city="Tokyo"`;
  await page.getByRole('textbox', { name: /SQL|query/i }).fill(sql);

  // Adminer の EXPLAIN ボタン相当（プラグインで追加した dryRun UI）を押す想定
  await page.getByRole('button', { name: /EXPLAIN|Dry Run/i }).click();

  // スキャンバイト（推定）が画面に出る想定
  await expect(page.locator('body')).toContainText(/bytes|スキャン|byte/i);
});
```

## 7.8 `e2e/tests/dml_block.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loginBigQuery } from './_helpers';

const PROJECT = process.env.GCP_PROJECT_ID!;
const DATASET = process.env.BQ_DATASET!;

test('DML is blocked in read-only mode', async ({ page }) => {
  await loginBigQuery(page, PROJECT);
  await page.getByRole('link', { name: new RegExp(`\\b${DATASET}\\b`) }).click();
  await page.getByRole('link', { name: /SQL|Query/i }).click();

  // INSERT を投げる
  const sql = `INSERT \`${DATASET}.people\` (id, name) VALUES (999, "X")`;
  await page.getByRole('textbox', { name: /SQL|query/i }).fill(sql);
  await page.getByRole('button', { name: /Execute|Run|実行/i }).click();

  // プラグイン側で拒否され、明示的なエラーメッセージ or 警告表示が出ること
  await expect(page.locator('body')).toContainText(/not allowed|read-only|許可されていません|拒否/i);
});
```

---

# 8. 実行手順（まとめ）

```bash
# 0) 依存インストール（初回のみ）
cd e2e
npm i
npx playwright install --with-deps
cd ..

# 1) BQシード
export $(grep -v '^#' .env | xargs)
bash scripts/bq_seed.sh

# 2) Adminer（プラグイン同梱）起動
docker compose up -d --build
# ヘルス待ち（composeのhealthcheckで自然に待つ）

# 3) E2Eテスト
cd e2e
BASE_URL="http://localhost:${ADMINER_PORT:-8080}" \
GCP_PROJECT_ID="$GCP_PROJECT_ID" \
BQ_DATASET="$BQ_DATASET" \
npm test
cd ..

# 4) 後始末（必要に応じて）
# docker compose down -v
# bash scripts/bq_cleanup.sh
```

---

# 9. 期待結果（アサーションの要点）

* **login.spec**：ログイン後、データベース（=dataset）一覧画面へ遷移し、プロジェクトIDが画面に表示
* **browse.spec**：`e2e_adminer_bq` のクリックで `people` / `people_tokyo` が見え、`people` のカラム `id/name/age/ts` が表示
* **select.spec**：`LIMIT 2 OFFSET 0` で `Alice/Bob`、`OFFSET 2` で `Carol/Dan`
* **explain.spec**：`dryRun`ボタンでスキャンバイト推定値が表示（文言は実装に合わせ正規表現でゆるく検証）
* **dml\_block.spec**：INSERT 実行時に「read-only or not allowed」系の明示的メッセージ

---

# 10. トラブルシュート

* **ログイン画面のセレクタが合わない**
  → `page.locator('label:text("System") ~ select')` のように CSS/ARIA を調整。初回は `page.pause()` で実DOMを目視し、Claude Code に修正させる。
* **権限/認証エラー**
  → サービスアカウントのロールを確認（最低 `bigquery.user` + `dataViewer`）。`bq ls --project_id` が通るか事前にチェック。
* **ポート衝突**
  → `.env` の `ADMINER_PORT` を変更。
* **dryRun UI が出ない**
  → プラグイン側の `explain()` / `support('sql')` 実装を再確認。UIボタン名にテストを合わせる。

---

# 11. CI 連携（任意）

* GitHub Actions での例（抜粋）

  * `google-github-actions/auth@v2` で Workload Identity もしくは key.json を利用
  * `gcloud` / `bq` をセットアップ→`scripts/bq_seed.sh` 実行
  * `docker compose up -d --build`
  * `e2e` で `npm ci && npx playwright install --with-deps && npm test`
  * 完了後 `bq_cleanup.sh` を実行（PR環境などはデータセットに一時的なサフィックスを付与）

---

# 12. 次の改善ポイント

* **選択UIに合わせた安定セレクタ**（data-testid 属性の埋め込み）
* ページングUI（次へ/前へボタン）がある場合、**UIベース**のページ移動テストも追加
* **ジョブエラーの可視化**テスト（不正SQL・無権限テーブルへのアクセス）
* **大きめデータ**でのテスト（スキャンバイト閾値の検証）
* 将来の **DML許可モード** に対する CREATE/INSERT/DELETE 試験

---

## 付録：テストデータ仕様（ドキュメント用途 `seed/schema.sql`）

```sql
-- dataset: e2e_adminer_bq
-- table: people
-- schema:
--   id INT64
--   name STRING
--   age INT64
--   city STRING
--   ts TIMESTAMP
-- view: people_tokyo
--   SELECT * FROM `${BQ_DATASET}.people` WHERE city="Tokyo" ORDER BY id;
```

---
