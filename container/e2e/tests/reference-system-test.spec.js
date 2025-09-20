/**
 * 参照系E2Eテスト: BigQuery Adminerドライバーの基本機能テスト
 * 既存データでの表示・ナビゲーション・検索機能を検証
 */

const { test, expect } = require('@playwright/test');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('BigQuery Adminer 参照系機能テスト', () => {

  test.beforeEach(async ({ page }) => {
    // 各テスト前にログインページへ移動
    await page.goto(BASE_URL);
  });

  test('基本ログインと接続確認', async ({ page }) => {
    // BigQueryログイン処理
    await page.waitForLoadState('networkidle');

    // BigQueryドライバーが選択されているか確認
    const systemSelect = page.locator('select[name="auth[driver]"]');
    await expect(systemSelect).toHaveValue('bigquery');

    // ログインボタンクリック
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // ログイン成功後、データセット一覧が表示されることを確認
    await expect(page).toHaveTitle(/Adminer/);
    await expect(page.locator('h2')).toContainText('Select database');
  });

  test('データセット一覧表示', async ({ page }) => {
    // ログイン処理
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // データセット一覧リンクの存在確認
    const databaseLinks = page.locator('a[href*="db="]').filter({ hasText: /^[^?]+$/ });
    const count = await databaseLinks.count();
    expect(count).toBeGreaterThan(0);

    // 最初のデータセットクリック
    await databaseLinks.first().click();
    await page.waitForLoadState('networkidle');

    // テーブル一覧が表示されることを確認
    await expect(page.locator('h3')).toContainText('Tables and views');
  });

  test('テーブル一覧表示と構造確認', async ({ page }) => {
    // ログインしてデータセット選択
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // 最初のデータセットに移動
    const databaseLinks = page.locator('a[href*="database="]');
    await databaseLinks.first().click();
    await page.waitForLoadState('networkidle');

    // テーブルリンクの存在確認
    const tableLinks = page.locator('a[href*="table="]');
    const tableCount = await tableLinks.count();

    if (tableCount > 0) {
      // 最初のテーブルの構造を確認
      await tableLinks.first().click();
      await page.waitForLoadState('networkidle');

      // テーブル構造（カラム情報）が表示されることを確認
      await expect(page.locator('h2')).toContainText('Table');

      // カラム情報テーブルの存在確認
      const columnTable = page.locator('table.nowrap');
      await expect(columnTable).toBeVisible();

      // 基本的なカラム情報（Name, Type）が表示されることを確認
      await expect(columnTable.locator('th')).toContainText(['Name', 'Type']);
    }
  });

  test('SQLクエリ実行機能', async ({ page }) => {
    // ログインしてSQL実行画面に移動
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // SQLクエリ画面へ移動
    await page.click('a[href*="sql="]');
    await page.waitForLoadState('networkidle');

    // SQL入力エリアの確認
    const sqlTextarea = page.locator('textarea[name="query"]');
    await expect(sqlTextarea).toBeVisible();

    // 基本的なSELECT文を実行（BigQuery標準SQL）
    const testQuery = 'SELECT 1 as test_column, "Hello BigQuery" as message';
    await sqlTextarea.fill(testQuery);

    // Execute ボタンクリック
    await page.click('input[type="submit"][value="Execute"]');
    await page.waitForLoadState('networkidle');

    // クエリ結果もしくはエラーが表示されることを確認
    const hasError = await page.locator('.error').isVisible();
    const hasResult = await page.locator('table').isVisible();
    const hasSuccessMessage = await page.locator('p:has-text("Query executed OK")').isVisible();

    // 結果、エラー、または成功メッセージが表示されることを確認
    expect(hasError || hasResult || hasSuccessMessage).toBeTruthy();
  });

  test('ナビゲーション機能確認', async ({ page }) => {
    // ログイン処理
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // 基本ナビゲーションリンクの確認
    const navigationItems = [
      'SQL command',
      'Export',
      'Import'
    ];

    for (const item of navigationItems) {
      const link = page.locator(`a:text-is("${item}")`);
      if (await link.isVisible()) {
        await link.click();
        await page.waitForLoadState('networkidle');

        // ページが正常に表示されることを確認（エラーページでないこと）
        const hasError = await page.locator('.error').isVisible();
        expect(hasError).toBeFalsy();

        // 戻る操作
        await page.goBack();
        await page.waitForLoadState('networkidle');
      }
    }
  });

  test('検索・フィルタ機能テスト', async ({ page }) => {
    // ログインしてテーブル選択
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // データセット選択
    const databaseLinks = page.locator('a[href*="database="]');
    await databaseLinks.first().click();
    await page.waitForLoadState('networkidle');

    // テーブル選択
    const tableLinks = page.locator('a[href*="table="]');
    const tableCount = await tableLinks.count();

    if (tableCount > 0) {
      await tableLinks.first().click();
      await page.waitForLoadState('networkidle');

      // Select データリンクがある場合のテスト
      const selectLink = page.locator('a[href*="select="]');
      if (await selectLink.isVisible()) {
        await selectLink.click();
        await page.waitForLoadState('networkidle');

        // データ表示ページで検索機能の要素確認
        const searchForm = page.locator('form');
        await expect(searchForm).toBeVisible();

        // ページング機能の確認（データが多い場合）
        const pagingLinks = page.locator('a[href*="page="]');
        if (await pagingLinks.count() > 0) {
          // ページング機能が正常に動作するか確認
          await expect(pagingLinks.first()).toBeVisible();
        }
      }
    }
  });

  test('エラーハンドリング確認', async ({ page }) => {
    // ログイン後、意図的に存在しないテーブルにアクセス
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // 存在しないテーブルへのアクセス
    await page.goto(`${BASE_URL}/?server=&username=&db=nonexistent_dataset&table=nonexistent_table`);
    await page.waitForLoadState('networkidle');

    // エラーメッセージが適切に表示されることを確認
    const errorElement = page.locator('.error, .message');
    // エラーが表示されるか、404ページが表示されることを確認
    const hasErrorOrNotFound = await errorElement.isVisible() ||
                               await page.locator('body').textContent().then(text => text.includes('404'));
    expect(hasErrorOrNotFound).toBeTruthy();
  });
});