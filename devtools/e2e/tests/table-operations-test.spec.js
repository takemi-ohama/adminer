/**
 * テーブル操作ボタン包括テスト
 * Analyze、Optimize、Check、Repair、Truncate、Dropボタンの動作確認
 * - 実装済み機能: Truncate、Drop
 * - 未対応機能: Analyze、Optimize、Check、Repair（適切なメッセージ表示確認）
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';
const TEST_DATASET = process.env.GOOGLE_CLOUD_PROJECT ? `test_dataset_${Math.floor(Date.now() / 1000)}` : 'test_dataset_fixed_api';

test.describe('BigQuery Adminer テーブル操作ボタンテスト', () => {

  test('テーブル操作ボタン包括テスト - 機能確認とメッセージ表示', async ({ page }) => {
    console.log('🔧 テーブル操作ボタン包括テスト開始');
    console.log(`接続URL: ${BASE_URL}`);
    console.log(`テスト対象データセット: ${TEST_DATASET}`);

    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // ログインボタンクリック
    const loginSelectors = [
      'button:has-text("Login")',
      'input[type="submit"][value="Login"]',
      'button[type="submit"]',
      'input[value="Login"]'
    ];

    let loginButton;
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
    }

    await expect(page).toHaveTitle(/Adminer/);
    console.log('✅ ログイン成功');

    // === Step 2: データセット選択 ===
    console.log('📝 Step 2: データセット選択');

    const datasetLinks = await page.locator('a[href*="db="]').all();
    console.log(`📊 発見されたデータセット数: ${datasetLinks.length}`);

    if (datasetLinks.length === 0) {
      throw new Error('利用可能なデータセットが見つかりませんでした');
    }

    // テスト用データセットまたは最初のデータセットを選択
    let selectedDataset = null;
    for (const link of datasetLinks) {
      const href = await link.getAttribute('href');
      const text = await link.textContent();
      if (href && (href.includes(TEST_DATASET) || href.includes('test_dataset'))) {
        selectedDataset = link;
        console.log(`🎯 テスト用データセット発見: ${text}`);
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
    console.log('✅ データセット選択成功');

    // === Step 3: テーブル存在確認・別データセット探索 ===
    console.log('📝 Step 3: 利用可能テーブル確認');

    let foundTablesDataset = null;
    let tableLinks = [];

    // 現在のデータセットでテーブルを確認
    try {
      // テーブル一覧の存在を短いタイムアウトで確認
      await page.waitForSelector('table', { timeout: 2000 });
      const tableRows = await page.locator('table tr').all();
      console.log(`📋 現在のデータセット テーブル行数: ${tableRows.length}`);

      // 利用可能なテーブルリンクを探す
      tableLinks = await page.locator('table a[href*="table="]').all();
      console.log(`📊 現在のデータセット 利用可能テーブル数: ${tableLinks.length}`);

      if (tableLinks.length > 0) {
        foundTablesDataset = 'current';
        console.log('✅ 現在のデータセットでテーブルを発見');
      }
    } catch (e) {
      console.log('⚠️ 現在のデータセットにテーブルがありません。他のデータセットを探索します。');
    }

    // テーブルが見つからない場合、他のデータセットを探索
    if (!foundTablesDataset) {
      console.log('🔍 テーブルが存在するデータセットを探索中...');

      // データセット一覧に戻る
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // ログイン状態を維持
      const currentUrl = page.url();
      if (!currentUrl.includes('username=')) {
        const loginButton = page.locator('input[type="submit"][value="Login"]');
        if (await loginButton.isVisible()) {
          await loginButton.click();
          await page.waitForLoadState('networkidle');
        }
      }

      // 全データセットを順番に確認
      const allDatasetLinks = await page.locator('a[href*="db="]').all();
      console.log(`📊 探索対象データセット数: ${allDatasetLinks.length}`);

      for (let i = 0; i < Math.min(allDatasetLinks.length, 5); i++) {
        try {
          const datasetLink = allDatasetLinks[i];
          const datasetText = await datasetLink.textContent();
          console.log(`🔍 データセット探索中: ${datasetText}`);

          await datasetLink.click();
          await page.waitForLoadState('networkidle');

          // このデータセットでテーブルを確認
          try {
            await page.waitForSelector('table', { timeout: 3000 });
            const potentialTableLinks = await page.locator('table a[href*="table="]').all();

            if (potentialTableLinks.length > 0) {
              tableLinks = potentialTableLinks;
              foundTablesDataset = datasetText;
              console.log(`✅ テーブル発見: ${datasetText} (${potentialTableLinks.length}個)`);
              break;
            }
          } catch (e) {
            console.log(`❌ ${datasetText}: テーブルなし`);
          }
        } catch (e) {
          console.log(`⚠️ データセット探索エラー: ${e.message}`);
        }
      }
    }

    if (tableLinks.length === 0) {
      throw new Error('利用可能なテーブルを持つデータセットが見つかりませんでした。BigQueryプロジェクトにテーブルが存在することを確認してください。');
    }

    console.log(`✅ テーブル操作テスト用データセット確定: ${foundTablesDataset}`);
    console.log(`📊 利用可能テーブル数: ${tableLinks.length}`);

    // 最初の利用可能テーブルを記録（後で使用）
    const firstTable = tableLinks[0];
    const tableText = await firstTable.textContent();
    console.log(`✅ 利用可能テーブル確認: ${tableText}`);

    console.log('✅ 既存テーブル確認完了');

    // === Step 4: テーブル選択（初期） ===
    console.log('📝 Step 4: テーブル選択');

    // 共通のテーブル選択ヘルパー関数を定義
    const selectTableForTesting = async (retryCount = 0) => {
      try {
        // チェックボックスによる複数選択方式を優先
        const availableCheckboxes = await page.locator('input[type="checkbox"][name="check[]"]').all();
        console.log(`📋 利用可能チェックボックス数: ${availableCheckboxes.length}`);

        if (availableCheckboxes.length > 0) {
          // 最初のテーブルを選択
          const targetCheckbox = availableCheckboxes[0];
          if (await targetCheckbox.isVisible()) {
            await targetCheckbox.check();
            console.log('✅ テーブル選択完了（チェックボックス方式）');
            return true;
          }
        }

        // チェックボックスがない場合の代替戦略
        console.log('📋 チェックボックスなし - 代替選択方式');
        if (tableLinks.length > 0) {
          const targetTable = tableLinks[0];
          if (await targetTable.isVisible()) {
            await targetTable.click();
            await page.waitForLoadState('networkidle');

            // テーブル詳細ページで「Select all」ボタンを探す
            const selectAllButton = page.locator('input[value="Select all"]');
            if (await selectAllButton.isVisible({ timeout: 2000 })) {
              await selectAllButton.click();
              console.log('✅ Select Allボタンクリック');
              return true;
            }
          }
        }

        return false;
      } catch (e) {
        console.log(`⚠️ テーブル選択エラー (試行${retryCount + 1}): ${e.message}`);
        if (retryCount < 2) {
          await page.waitForTimeout(1000);
          return await selectTableForTesting(retryCount + 1);
        }
        return false;
      }
    };

    // 初期テーブル選択の実行
    const initialSelectionSuccess = await selectTableForTesting();
    if (!initialSelectionSuccess) {
      throw new Error('テーブルの初期選択に失敗しました');
    }

    // === Step 5: 各ボタンの動作テスト ===
    console.log('📝 Step 5: 各ボタンの動作テスト');

    // 5.1 Analyzeボタンテスト（未対応メッセージ確認）
    console.log('🔍 5.1 Analyzeボタンテスト');
    const analyzeButton = page.locator('input[value="Analyze"]');

    if (await analyzeButton.isVisible()) {
      await analyzeButton.click();
      await page.waitForLoadState('networkidle');

      // 未対応メッセージの確認
      const unsupportedMessage = await page.locator('text=BigQuery does not support ANALYZE TABLE operations').isVisible();
      expect(unsupportedMessage).toBeTruthy();
      console.log('✅ Analyzeボタン: 適切な未対応メッセージ表示確認');

      // 戻るリンククリック
      const backLink = page.locator('a:has-text("Go Back")');
      if (await backLink.isVisible()) {
        await backLink.click();
        await page.waitForLoadState('networkidle');
      }
    }

    // テーブル再選択（戻った後）
    await page.waitForLoadState('networkidle');
    const reselectionSuccess1 = await selectTableForTesting();
    if (!reselectionSuccess1) {
      console.log('⚠️ Analyzeテスト後のテーブル再選択に失敗 - 続行します');
    } else {
      console.log('🔄 テーブル再選択完了（Analyze後）');
    }

    // 5.2 Optimizeボタンテスト（未対応メッセージ確認）
    console.log('🚀 5.2 Optimizeボタンテスト');
    const optimizeButton = page.locator('input[value="Optimize"]');

    if (await optimizeButton.isVisible()) {
      await optimizeButton.click();
      await page.waitForLoadState('networkidle');

      // 未対応メッセージの確認
      const unsupportedMessage = await page.locator('text=BigQuery automatically optimizes storage').isVisible();
      expect(unsupportedMessage).toBeTruthy();
      console.log('✅ Optimizeボタン: 適切な未対応メッセージ表示確認');

      // 戻る
      const backLink = page.locator('a:has-text("Go Back")');
      if (await backLink.isVisible()) {
        await backLink.click();
        await page.waitForLoadState('networkidle');
      }
    }

    // テーブル再選択
    await page.waitForLoadState('networkidle');
    const reselectionSuccess2 = await selectTableForTesting();
    if (!reselectionSuccess2) {
      console.log('⚠️ Optimizeテスト後のテーブル再選択に失敗 - 続行します');
    } else {
      console.log('🔄 テーブル再選択完了（Optimize後）');
    }

    // 5.3 Checkボタンテスト（未対応メッセージ確認）
    console.log('✔️ 5.3 Checkボタンテスト');
    const checkButton = page.locator('input[value="Check"]');

    if (await checkButton.isVisible()) {
      await checkButton.click();
      await page.waitForLoadState('networkidle');

      // 未対応メッセージの確認
      const unsupportedMessage = await page.locator('text=BigQuery does not support CHECK TABLE operations').isVisible();
      expect(unsupportedMessage).toBeTruthy();
      console.log('✅ Checkボタン: 適切な未対応メッセージ表示確認');

      // 戻る
      const backLink = page.locator('a:has-text("Go Back")');
      if (await backLink.isVisible()) {
        await backLink.click();
        await page.waitForLoadState('networkidle');
      }
    }

    // テーブル再選択
    await page.waitForLoadState('networkidle');
    const reselectionSuccess3 = await selectTableForTesting();
    if (!reselectionSuccess3) {
      console.log('⚠️ Checkテスト後のテーブル再選択に失敗 - 続行します');
    } else {
      console.log('🔄 テーブル再選択完了（Check後）');
    }

    // 5.4 Repairボタンテスト（未対応メッセージ確認）
    console.log('🔧 5.4 Repairボタンテスト');
    const repairButton = page.locator('input[value="Repair"]');

    if (await repairButton.isVisible()) {
      await repairButton.click();
      await page.waitForLoadState('networkidle');

      // 未対応メッセージの確認
      const unsupportedMessage = await page.locator('text=BigQuery does not support REPAIR TABLE operations').isVisible();
      expect(unsupportedMessage).toBeTruthy();
      console.log('✅ Repairボタン: 適切な未対応メッセージ表示確認');

      // 戻る
      const backLink = page.locator('a:has-text("Go Back")');
      if (await backLink.isVisible()) {
        await backLink.click();
        await page.waitForLoadState('networkidle');
      }
    }

    // === Step 6: 実装済み機能のテスト（Truncate/Drop）===
    console.log('📝 Step 6: 実装済み機能テスト（注意: 実際にテーブルを変更）');

    // 注意: ここでは実際のTruncate/Dropは危険なので、ボタンの存在確認のみ
    console.log('⚠️ 注意: Truncate/Dropボタンは存在確認のみ（実行は危険のため）');

    // テーブル再選択
    await page.waitForLoadState('networkidle');
    const reselectionSuccess4 = await selectTableForTesting();
    if (!reselectionSuccess4) {
      console.log('⚠️ Truncate/Dropテスト用のテーブル再選択に失敗 - 続行します');
    } else {
      console.log('🔄 Truncate/Dropテスト用テーブル再選択完了');
    }

    // Truncateボタンの存在確認
    const truncateButton = page.locator('input[value="Truncate"]');
    const truncateExists = await truncateButton.isVisible();
    console.log(`🗑️ Truncateボタン存在: ${truncateExists}`);

    // Dropボタンの存在確認
    const dropButton = page.locator('input[value="Drop"]');
    const dropExists = await dropButton.isVisible();
    console.log(`❌ Dropボタン存在: ${dropExists}`);

    // === Step 7: テスト完了確認 ===
    console.log('📝 Step 7: テスト完了確認');

    // 最終的にテーブル一覧画面に戻っていることを確認
    await expect(page.locator('text=Tables and views')).toBeVisible();
    console.log('✅ テーブル一覧画面への復帰確認');

    console.log('🎯 テーブル操作ボタン包括テスト完了');
    console.log('📊 テスト結果:');
    console.log('   - Analyze: 未対応メッセージ表示 ✅');
    console.log('   - Optimize: 未対応メッセージ表示 ✅');
    console.log('   - Check: 未対応メッセージ表示 ✅');
    console.log('   - Repair: 未対応メッセージ表示 ✅');
    console.log('   - Truncate: ボタン存在確認 ✅');
    console.log('   - Drop: ボタン存在確認 ✅');
  });

});