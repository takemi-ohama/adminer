/**
 * SQL Command結果表示機能デバッグテスト
 * i03.md #38-39で指摘されている「SQLを実行しても常に結果が0件」問題を調査
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('SQL Command 結果表示機能デバッグ', () => {

  test('SQL Command実行と結果表示の詳細確認', async ({ page }) => {
    console.log('🔍 SQL Command結果表示デバッグテスト開始');
    console.log(`接続URL: ${BASE_URL}`);

    // === Step 1: ログインと接続 ===
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    const loginButton = page.locator('input[type="submit"][value="Login"]');
    if (await loginButton.isVisible()) {
      await loginButton.click();
      await page.waitForLoadState('networkidle');
      console.log('✅ ログイン完了');
    }

    await expect(page).toHaveTitle(/Adminer/);

    // === Step 2: データセット選択 ===
    const datasetLinks = await page.locator('a[href*="db="]').all();
    let selectedDataset = null;

    for (const link of datasetLinks) {
      const href = await link.getAttribute('href');
      const text = await link.textContent();
      if (href && href.includes('test_dataset_fixed_api')) {
        selectedDataset = link;
        console.log(`🎯 テスト用データセット選択: ${text}`);
        break;
      }
    }

    if (!selectedDataset && datasetLinks.length > 0) {
      selectedDataset = datasetLinks[0];
      const text = await selectedDataset.textContent();
      console.log(`🎯 最初のデータセット選択: ${text}`);
    }

    await selectedDataset.click();
    await page.waitForLoadState('networkidle');

    // === Step 3: SQL commandページへ移動 ===
    console.log('📝 SQL commandページへ移動');
    const sqlLink = page.locator('a:has-text("SQL command")');
    await expect(sqlLink).toBeVisible();
    await sqlLink.click();
    await page.waitForLoadState('networkidle');

    console.log(`📊 SQL commandページタイトル: ${await page.title()}`);

    // === Step 4: 簡単なクエリを実行 ===
    console.log('📝 クエリ実行テスト');

    // SQL入力エリアを探す
    const sqlTextarea = page.locator('textarea[name="query"]');
    await expect(sqlTextarea).toBeVisible();

    // 簡単なクエリを入力
    const testQuery = 'SELECT 1 as test_column, "Hello World" as message';
    await sqlTextarea.fill(testQuery);
    console.log(`📝 入力クエリ: ${testQuery}`);

    // Execute ボタンをクリック
    const executeButton = page.locator('input[value="Execute"]');
    await expect(executeButton).toBeVisible();
    await executeButton.click();
    await page.waitForLoadState('networkidle');

    // === Step 5: 結果の詳細分析 ===
    console.log('🔍 結果の詳細分析');

    // ページタイトル確認
    const resultTitle = await page.title();
    console.log(`📊 結果ページタイトル: ${resultTitle}`);

    // エラーメッセージの確認
    const errorElements = await page.locator('.error').all();
    if (errorElements.length > 0) {
      for (const error of errorElements) {
        const errorText = await error.textContent();
        console.log(`❌ エラーメッセージ: ${errorText}`);
      }
    } else {
      console.log('✅ エラーメッセージなし');
    }

    // 成功メッセージの確認
    const successElements = await page.locator('.message').all();
    if (successElements.length > 0) {
      for (const message of successElements) {
        const messageText = await message.textContent();
        console.log(`💬 メッセージ: ${messageText}`);
      }
    }

    // テーブル結果の確認
    const resultTable = page.locator('table');
    const hasTable = await resultTable.isVisible();
    console.log(`📋 結果テーブル存在: ${hasTable}`);

    if (hasTable) {
      const rows = await resultTable.locator('tr').all();
      console.log(`📊 テーブル行数: ${rows.length}`);

      if (rows.length > 0) {
        const firstRow = rows[0];
        const firstRowText = await firstRow.textContent();
        console.log(`📋 最初の行: ${firstRowText}`);
      }
    }

    // Job情報の確認
    const jobElements = await page.locator('text=/Job.*completed/').all();
    if (jobElements.length > 0) {
      for (const job of jobElements) {
        const jobText = await job.textContent();
        console.log(`🔧 Job情報: ${jobText}`);
      }
    }

    // BigQuery特有のメッセージ確認
    const bigqueryMessages = await page.locator('text=/BigQuery/').all();
    if (bigqueryMessages.length > 0) {
      for (const msg of bigqueryMessages) {
        const msgText = await msg.textContent();
        console.log(`🔵 BigQueryメッセージ: ${msgText}`);
      }
    }

    // ページ全体のスクリーンショット（デバッグ用）
    await page.screenshot({
      path: '/app/container/e2e/test-results/sql-command-debug-result.png',
      fullPage: true
    });
    console.log('📸 結果ページのスクリーンショット保存完了');

    // HTMLコンテンツの一部をログ出力
    const bodyContent = await page.locator('body').textContent();
    console.log(`📄 ページ内容（最初の500文字）: ${bodyContent.substring(0, 500)}...`);

    console.log('🎯 SQL Command結果表示デバッグテスト完了');
  });

});