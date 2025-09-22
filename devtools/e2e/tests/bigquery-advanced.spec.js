import { test, expect } from '@playwright/test';

const GOOGLE_CLOUD_PROJECT = process.env.GOOGLE_CLOUD_PROJECT || 'nyle-carmo-analysis';
const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';

test.describe('BigQuery Driver Advanced Functionality', () => {

  test.beforeEach(async ({ page }) => {
    // 各テスト開始時にログイン
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.selectOption('select[name="auth[driver]"]', 'bigquery');
    await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);
    await page.click('input[type="submit"][value="Login"]');
  });

  test('should display multiple datasets', async ({ page }) => {
    // 複数のデータセットが表示されることを確認
    const datasets = page.locator('table a[href*="db="]');
    const count = await datasets.count();

    // 最低1つのデータセット（prod_carmo_db）が存在することを確認
    expect(count).toBeGreaterThanOrEqual(1);

    // prod_carmo_dbが含まれていることを確認
    await expect(page.locator('a:has-text("prod_carmo_db")')).toBeVisible();
  });

  test('should display table information correctly', async ({ page }) => {
    // prod_carmo_dbデータセットに移動
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`);

    // テーブル一覧で複数のテーブルが表示されることを確認
    const tables = page.locator('table a[href*="table="]');
    const tableCount = await tables.count();

    expect(tableCount).toBeGreaterThanOrEqual(1);

    // member_infoテーブルの行が適切に表示されることを確認
    const memberInfoRow = page.locator('tr:has(a:has-text("member_info"))');
    await expect(memberInfoRow).toBeVisible();

    // テーブル行に必要な情報（名前、エンジン、行数など）が含まれることを確認
    await expect(memberInfoRow.locator('td')).toHaveCount.greaterThanOrEqual(3);
  });

  test('should handle table schema display', async ({ page }) => {
    // member_infoテーブルスキーマ表示
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`);

    // スキーマ情報テーブルが表示されることを確認
    const schemaTable = page.locator('table.nowrap');
    await expect(schemaTable).toBeVisible();

    // フィールド行が複数存在することを確認
    const fieldRows = schemaTable.locator('tbody tr');
    const fieldCount = await fieldRows.count();
    expect(fieldCount).toBeGreaterThan(0);

    // 各フィールド行に必要な情報（名前、型、null許可など）があることを確認
    if (fieldCount > 0) {
      const firstRow = fieldRows.nth(0);
      await expect(firstRow.locator('td')).toHaveCount.greaterThanOrEqual(3);
    }
  });

  test('should provide navigation links', async ({ page }) => {
    // テーブル構造画面での各種リンク確認
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`);

    // パンくずナビゲーション確認
    await expect(page.locator('#breadcrumb')).toBeVisible();
    await expect(page.locator(`#breadcrumb a:has-text("${GOOGLE_CLOUD_PROJECT}")`)).toBeVisible();
    await expect(page.locator('#breadcrumb a:has-text("prod_carmo_db")')).toBeVisible();

    // 機能リンクの確認
    const linksSection = page.locator('.links');
    await expect(linksSection.locator('a:has-text("Select data")')).toBeVisible();
    await expect(linksSection.locator('a:has-text("Show structure")')).toBeVisible();
  });

  test('should handle error cases gracefully', async ({ page }) => {
    // 存在しないテーブルへのアクセステスト
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=nonexistent_table`);

    // Fatal Errorではなく、適切なエラーハンドリングが行われることを確認
    //（空のテーブルまたはエラーメッセージが表示される）
    await expect(page.locator('text=Fatal error')).toHaveCount(0);

    // 存在しないデータセットへのアクセステスト
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=nonexistent_dataset`);

    // Fatal Errorではなく、適切なエラーハンドリングが行われることを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
  });

  test('should maintain session consistency', async ({ page }) => {
    // 複数ページ間でのセッション保持確認
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`);

    // テーブル詳細に移動
    await page.click('a:has-text("member_info")');

    // セッションが保持され、ログイン状態が維持されることを確認
    await expect(page.locator('text=Login')).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`.*table=member_info`));

    // データ選択画面に移動
    await page.click('a:has-text("Select data")');

    // セッションが引き続き保持されることを確認
    await expect(page.locator('text=Login')).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`.*select=member_info`));
  });

  test('should display correct page elements structure', async ({ page }) => {
    // データセット一覧画面の基本構造確認
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);

    // 基本的なAdminerページ要素の存在確認
    await expect(page.locator('#breadcrumb')).toBeVisible();
    await expect(page.locator('h2')).toBeVisible();

    // テーブル一覧画面の構造確認
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`);

    await expect(page.locator('#breadcrumb')).toBeVisible();
    await expect(page.locator('h2:has-text("prod_carmo_db")')).toBeVisible();
    await expect(page.locator('table')).toBeVisible();

    // テーブル詳細画面の構造確認
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`);

    await expect(page.locator('#breadcrumb')).toBeVisible();
    await expect(page.locator('h2:has-text("member_info")')).toBeVisible();
    await expect(page.locator('.links')).toBeVisible();
    await expect(page.locator('table.nowrap')).toBeVisible();
  });

});