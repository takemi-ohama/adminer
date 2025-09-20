import { test, expect } from '@playwright/test';

const GOOGLE_CLOUD_PROJECT = process.env.GOOGLE_CLOUD_PROJECT || 'nyle-carmo-analysis';
const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';

test.describe('BigQuery Driver Basic Functionality', () => {

  test('should load login page without errors', async ({ page }) => {
    // ログインページアクセス
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);

    // ページタイトル確認
    await expect(page).toHaveTitle(/Login.*Adminer/);

    // ログインフォーム要素確認
    await expect(page.locator('input[type="submit"][value="Login"]')).toBeVisible();
    await expect(page.locator('select[name="auth[driver]"]')).toBeVisible();

    // エラーメッセージがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
    await expect(page.locator('text=Warning')).toHaveCount(0);
  });

  test('should successfully login with BigQuery driver', async ({ page }) => {
    // ログインページアクセス
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);

    // BigQueryドライバー選択
    await page.selectOption('select[name="auth[driver]"]', 'bigquery');

    // サーバー（プロジェクトID）入力
    await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);

    // ログイン実行
    await page.click('input[type="submit"][value="Login"]');

    // ログイン成功確認（データベース一覧画面）
    await expect(page).toHaveTitle(new RegExp(`${GOOGLE_CLOUD_PROJECT}.*Adminer`));

    // データセット（データベース）一覧が表示されることを確認
    await expect(page.locator('h2:has-text("Databases")')).toBeVisible();

    // Fatal Errorがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
    await expect(page.locator('text=Uncaught Error')).toHaveCount(0);
  });

  test('should display datasets and allow selection', async ({ page }) => {
    // ログイン実行
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.selectOption('select[name="auth[driver]"]', 'bigquery');
    await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);
    await page.click('input[type="submit"][value="Login"]');

    // prod_carmo_db データセットが存在することを確認
    const datasetLink = page.locator('a:has-text("prod_carmo_db")');
    await expect(datasetLink).toBeVisible();

    // データセットをクリック
    await datasetLink.click();

    // テーブル一覧画面に遷移することを確認
    await expect(page).toHaveURL(new RegExp(`.*db=prod_carmo_db`));
    await expect(page.locator('h2:has-text("prod_carmo_db")')).toBeVisible();
  });

  test('should display tables in prod_carmo_db dataset', async ({ page }) => {
    // データセット選択まで進む
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`);

    // テーブル一覧が表示されることを確認
    await expect(page.locator('table')).toBeVisible();

    // member_info テーブルが存在することを確認
    const memberInfoLink = page.locator('a:has-text("member_info")');
    await expect(memberInfoLink).toBeVisible();

    // エラーがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
    await expect(page.locator('text=TypeError')).toHaveCount(0);
  });

  test('should display member_info table structure', async ({ page }) => {
    // member_infoテーブル構造画面へ
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`);

    // テーブル構造ページの表示確認
    await expect(page).toHaveTitle(/Table.*member_info/);
    await expect(page.locator('h2:has-text("member_info")')).toBeVisible();

    // テーブル構造（フィールド一覧）が表示されることを確認
    await expect(page.locator('table.nowrap')).toBeVisible();

    // "Select data" リンクが存在することを確認
    const selectDataLink = page.locator('a:has-text("Select data")');
    await expect(selectDataLink).toBeVisible();

    // エラーがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
    await expect(page.locator('text=TypeError')).toHaveCount(0);
    await expect(page.locator('text=Call to undefined')).toHaveCount(0);
  });

  test('should access member_info data selection page', async ({ page }) => {
    // データ選択画面へ
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&select=member_info`);

    // データ選択ページの表示確認
    await expect(page).toHaveTitle(/Select.*member_info/);
    await expect(page.locator('h2:has-text("member_info")')).toBeVisible();

    // クエリ実行フォームが存在することを確認
    await expect(page.locator('textarea, input[name="query"]')).toBeVisible();

    // エラーがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);
    await expect(page.locator('text=TypeError')).toHaveCount(0);
    await expect(page.locator('text=Call to undefined')).toHaveCount(0);
  });

  test('should handle navigation between sections', async ({ page }) => {
    // ログイン
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.selectOption('select[name="auth[driver]"]', 'bigquery');
    await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);
    await page.click('input[type="submit"][value="Login"]');

    // データセット → テーブル → 構造 → データ選択の流れをテスト
    await page.click('a:has-text("prod_carmo_db")');
    await page.click('a:has-text("member_info")');

    // 構造表示からデータ選択への遷移
    await page.click('a:has-text("Select data")');

    // 各段階でエラーがないことを確認
    await expect(page.locator('text=Fatal error')).toHaveCount(0);

    // 最終的にデータ選択画面に到達
    await expect(page).toHaveURL(new RegExp(`.*select=member_info`));
  });

});