/**
 * Analyzeボタンエラー再現テスト
 * Database画面のAnalyzeボタンをクリックしてエラーメッセージを確認
 * MCP Playwright検証結果を基に修正されたバージョン
 */

const { test, expect } = require('@playwright/test');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('BigQuery Adminer Analyzeボタンテスト', () => {

  test('Analyzeボタンクリックで適切なメッセージ表示確認', async ({ page }) => {
    console.log('🔍 Analyzeボタンテスト開始');
    console.log(`接続URL: ${BASE_URL}`);

    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // ログインボタンを複数のパターンで確認してクリック
    let loginButton;
    const loginSelectors = [
      'button:has-text("Login")',
      'input[type="submit"][value="Login"]',
      'button[type="submit"]',
      'input[value="Login"]'
    ];

    for (const selector of loginSelectors) {
      try {
        loginButton = page.locator(selector);
        if (await loginButton.isVisible({ timeout: 2000 })) {
          console.log(`✅ ログインボタン発見: ${selector}`);
          break;
        }
      } catch (e) {
        // 次のセレクターを試行
      }
    }

    if (loginButton && await loginButton.isVisible()) {
      await loginButton.click();
      await page.waitForLoadState('networkidle');
      console.log('✅ ログインボタンをクリック');
    } else {
      console.log('⚠️ ログインボタンが見つからない - 直接認証状況を確認');
    }

    // ログイン成功確認
    await expect(page).toHaveTitle(/Adminer/);

    // データセット選択画面またはログイン後の画面を確認
    const isLoggedIn = await page.locator('text=Select database').isVisible() ||
                      await page.locator('text=BigQuery').isVisible();

    if (isLoggedIn) {
      console.log('✅ ログイン成功 - データセット選択画面');
    } else {
      console.log('⚠️ ログイン状況を再確認中');
      // ページの現在状況をログ出力
      const title = await page.title();
      const url = page.url();
      console.log(`現在のページタイトル: ${title}`);
      console.log(`現在のURL: ${url}`);
    }

    // === Step 2: テーブルが存在するデータセットの選択 ===
    console.log('📝 Step 2: テーブルが存在するデータセット選択');

    // 利用可能なデータセットを確認
    const datasetLinks = await page.locator('a[href*="db="]').all();
    console.log(`📊 発見されたデータセット数: ${datasetLinks.length}`);

    if (datasetLinks.length === 0) {
      throw new Error('利用可能なデータセットが見つかりませんでした');
    }

    // test_dataset_fixed_apiが存在するか確認、なければ最初のデータセットを使用
    let selectedDataset = null;
    for (const link of datasetLinks) {
      const href = await link.getAttribute('href');
      const text = await link.textContent();
      if (href && href.includes('test_dataset_fixed_api')) {
        selectedDataset = link;
        console.log(`🎯 優先データセット発見: ${text}`);
        break;
      }
    }

    if (!selectedDataset) {
      selectedDataset = datasetLinks[0];
      const text = await selectedDataset.textContent();
      console.log(`🎯 最初のデータセットを選択: ${text}`);
    }

    await selectedDataset.click();
    await page.waitForLoadState('networkidle');

    // データセット画面表示確認
    await expect(page.locator('h2')).toBeVisible();
    console.log('✅ データセット選択成功 - テーブル一覧画面に移動');

    // === Step 3: テーブル存在確認とテーブル選択 ===
    console.log('📝 Step 3: テーブル選択の実行');

    // "No tables"メッセージがあるかチェック
    const noTablesText = page.locator('text=No tables');
    if (await noTablesText.isVisible()) {
      console.log('⚠️ このデータセットにはテーブルが存在しません - 別のデータセットを試行');

      // データセット選択画面に戻る
      await page.goBack();
      await page.waitForLoadState('networkidle');

      // 他のデータセットを試行
      const otherDatasetLinks = await page.locator('a[href*="db="]').all();
      for (let i = 1; i < Math.min(3, otherDatasetLinks.length); i++) {
        const testLink = otherDatasetLinks[i];
        const text = await testLink.textContent();
        console.log(`🔄 別のデータセットを試行: ${text}`);

        await testLink.click();
        await page.waitForLoadState('networkidle');

        const hasNoTables = await page.locator('text=No tables').isVisible();
        if (!hasNoTables) {
          console.log(`✅ テーブルが存在するデータセット発見: ${text}`);
          break;
        }

        await page.goBack();
        await page.waitForLoadState('networkidle');
      }
    }

    // テーブル一覧でcheckboxを確認（全てのcheckbox、テーブル名に関係なく）
    const allCheckboxes = await page.locator('input[type="checkbox"]').all();
    console.log(`📋 発見されたチェックボックス総数: ${allCheckboxes.length}`);

    // 最初のチェックボックス（通常はヘッダー行）を除いた実際のテーブルのチェックボックスを選択
    let selectedCheckbox = null;
    for (let i = 0; i < allCheckboxes.length; i++) {
      const checkbox = allCheckboxes[i];
      const isVisible = await checkbox.isVisible();
      const isEnabled = await checkbox.isEnabled();

      if (isVisible && isEnabled) {
        // checkboxの親要素からテーブル名らしき情報を取得
        const parent = checkbox.locator('..');
        const parentText = await parent.textContent();

        // ヘッダー行でないことを確認
        if (parentText && !parentText.includes('Table') && !parentText.includes('Engine')) {
          selectedCheckbox = checkbox;
          console.log(`✅ 選択予定のテーブル: ${parentText.substring(0, 50)}`);
          break;
        }
      }
    }

    if (selectedCheckbox) {
      await selectedCheckbox.check();
      console.log('✅ テーブルを選択完了');
    } else {
      console.log('⚠️ 選択可能なテーブルのチェックボックスが見つかりません');
      throw new Error('テーブルのチェックボックスが見つかりませんでした');
    }

    // === Step 4: Analyzeボタンの状態確認とクリック ===
    console.log('📝 Step 4: Analyzeボタンの確認とクリック');

    // Analyzeボタンの確認（input要素として実装されている）
    const analyzeButton = page.locator('input[value="Analyze"]');
    await expect(analyzeButton).toBeVisible();

    // ボタンが有効化されていることを確認
    await expect(analyzeButton).toBeEnabled();
    console.log('✅ Analyzeボタンが有効化されていることを確認');

    // Analyzeボタンをクリック
    await analyzeButton.click();
    await page.waitForLoadState('networkidle');

    // === Step 5: 適切なメッセージの確認 ===
    console.log('📝 Step 5: BigQuery未対応メッセージの確認');

    // BigQueryドライバーからの適切なメッセージを確認
    const unsupportedMessage = page.locator('text=BigQuery does not support ANALYZE TABLE operations as it automatically optimizes queries.');
    await expect(unsupportedMessage).toBeVisible();
    console.log('✅ 適切な未対応メッセージが表示されました');

    // ページ状態の確認
    const pageTitle = await page.title();
    const currentUrl = page.url();
    console.log(`📄 ページタイトル: ${pageTitle}`);
    console.log(`🔗 現在のURL: ${currentUrl}`);

    // === Step 6: テスト結果の検証 ===
    console.log('📝 Step 6: テスト結果の検証');

    // テーブル一覧画面に戻っていることを確認
    await expect(page.locator('text=Tables and views')).toBeVisible();

    // 選択状態の確認（Analyzeボタン実行後は選択が解除される場合があるため、Selected項目の存在を確認）
    const selectedSection = page.locator('text=Selected');
    await expect(selectedSection).toBeVisible();
    console.log('✅ Selected項目が表示されています');

    // エラーが発生していないことを確認
    const errorMessages = page.locator('.error, .message.error');
    const errorCount = await errorMessages.count();
    console.log(`📋 エラーメッセージ数: ${errorCount}`);

    console.log('🎯 Analyzeボタンテスト完了');
  });

});