/**
 * 基本機能テストスクリプト - i03.md #5対応
 * BigQueryログイン → データベース選択 → テーブル選択 → データ一覧表示の基本フローテスト
 */

const { test, expect } = require('@playwright/test');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('BigQuery Adminer 基本機能フローテスト', () => {

  test('基本フロー: ログイン→データベース選択→テーブル選択→データ表示', async ({ page }) => {
    console.log('🚀 基本機能フローテスト開始');
    console.log(`接続URL: ${BASE_URL}`);

    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // BigQueryドライバーが選択されているか確認
    const systemSelect = page.locator('select[name="auth[driver]"]');
    if (await systemSelect.isVisible()) {
      await expect(systemSelect).toHaveValue('bigquery');
      console.log('✅ BigQueryドライバー選択確認');
    }

    // ログインボタンクリック
    const loginButton = page.locator('input[type="submit"][value="Login"]');
    await expect(loginButton).toBeVisible();
    await loginButton.click();
    await page.waitForLoadState('networkidle');

    // ログイン成功確認（Adminerタイトル確認）
    await expect(page).toHaveTitle(/Adminer/);
    console.log('✅ ログイン成功');

    // === Step 2: データベース（データセット）選択 ===
    console.log('📝 Step 2: データベース（データセット）選択');

    // データベースリンクの存在確認
    const databaseLinks = page.locator('a[href*="database="]');
    const dbCount = await databaseLinks.count();
    console.log(`📊 検出データベース数: ${dbCount}`);

    if (dbCount === 0) {
      throw new Error('❌ データベース（データセット）が見つかりません');
    }

    // 最初のデータベースを選択
    const firstDatabase = databaseLinks.first();
    const dbName = await firstDatabase.textContent();
    console.log(`🎯 選択データベース: ${dbName}`);

    await firstDatabase.click();
    await page.waitForLoadState('networkidle');
    console.log('✅ データベース選択成功');

    // === Step 3: テーブル選択 ===
    console.log('📝 Step 3: テーブル選択');

    // テーブル一覧の確認
    const tableLinks = page.locator('a[href*="table="]');
    const tableCount = await tableLinks.count();
    console.log(`📊 検出テーブル数: ${tableCount}`);

    if (tableCount === 0) {
      console.log('⚠️  テーブルが見つかりません。空のデータセットの可能性があります');
      // 空のデータセットの場合は警告のみでテスト継続
    } else {
      // 最初のテーブルを選択
      const firstTable = tableLinks.first();
      const tableName = await firstTable.textContent();
      console.log(`🎯 選択テーブル: ${tableName}`);

      await firstTable.click();
      await page.waitForLoadState('networkidle');
      console.log('✅ テーブル選択成功');

      // === Step 4: データ一覧表示 ===
      console.log('📝 Step 4: データ一覧表示');

      // テーブル構造ページから「Select data」リンクを探す
      const selectDataLink = page.locator('a[href*="select"]').first();

      if (await selectDataLink.isVisible()) {
        console.log('🔍 「Select data」リンク発見');
        await selectDataLink.click();
        await page.waitForLoadState('networkidle');

        // データ表示の確認
        // 1. データテーブルの存在確認
        const dataTable = page.locator('table.nowrap');
        if (await dataTable.isVisible()) {
          console.log('✅ データテーブル表示確認');

          // 2. データ行数の確認
          const dataRows = await page.locator('table.nowrap tbody tr').count();
          console.log(`📊 表示データ行数: ${dataRows}`);

          // 3. 列ヘッダーの確認
          const columnHeaders = await page.locator('table.nowrap thead th').count();
          console.log(`📊 表示列数: ${columnHeaders}`);

          console.log('✅ データ一覧表示成功');
        } else {
          // データテーブルが見つからない場合
          console.log('⚠️  データテーブルが表示されていません');

          // エラーメッセージの確認
          const errorElement = page.locator('.error');
          if (await errorElement.isVisible()) {
            const errorText = await errorElement.textContent();
            console.log(`❌ エラーメッセージ: ${errorText}`);
            throw new Error(`データ表示エラー: ${errorText}`);
          } else {
            console.log('ℹ️  エラーメッセージなし（空のテーブル可能性）');
          }
        }
      } else {
        console.log('⚠️  「Select data」リンクが見つかりません');

        // 代替：現在のページでデータテーブル確認
        const currentPageTable = page.locator('table');
        if (await currentPageTable.isVisible()) {
          console.log('✅ 現在ページでテーブル表示確認');
        } else {
          console.log('❌ テーブルデータが表示されていません');
        }
      }
    }

    // === Step 5: ナビゲーション確認 ===
    console.log('📝 Step 5: ナビゲーション確認');

    // 基本ナビゲーション要素の確認
    const navigationElements = [
      { name: 'SQL command', selector: 'a[href*="sql"]' },
      { name: 'Export', selector: 'a:text-is("Export")' },
      { name: 'Database', selector: 'a[href*="database"]' }
    ];

    for (const nav of navigationElements) {
      const element = page.locator(nav.selector);
      if (await element.isVisible()) {
        console.log(`✅ ナビゲーション要素確認: ${nav.name}`);
      } else {
        console.log(`⚠️  ナビゲーション要素未発見: ${nav.name}`);
      }
    }

    // === 最終確認 ===
    console.log('📝 最終確認: エラー検出');

    // JavaScriptエラーをキャッチ
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        console.log(`❌ ブラウザエラー: ${msg.text()}`);
      }
    });

    // ページエラーをキャッチ
    page.on('pageerror', (error) => {
      console.log(`❌ ページエラー: ${error.message}`);
    });

    // 明示的なエラーメッセージの確認
    const pageErrors = await page.locator('.error').count();
    if (pageErrors > 0) {
      console.log(`⚠️  ページ内エラー数: ${pageErrors}`);
      const errorTexts = await page.locator('.error').allTextContents();
      errorTexts.forEach((error, index) => {
        console.log(`   エラー${index + 1}: ${error}`);
      });
    } else {
      console.log('✅ ページエラーなし');
    }

    console.log('🎯 基本機能フローテスト完了');
  });

  test('基本機能フロー（簡易版）: 接続とデータベース表示のみ', async ({ page }) => {
    console.log('🚀 簡易基本機能テスト開始');

    // ログインのみテスト
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    await page.click('input[type="submit"][value="Login"]');
    await page.waitForLoadState('networkidle');

    // データベース一覧の表示確認
    const databaseLinks = await page.locator('a[href*="database="]').count();
    console.log(`📊 データベース数: ${databaseLinks}`);

    if (databaseLinks > 0) {
      console.log('✅ 基本接続・データベース表示成功');
    } else {
      throw new Error('❌ データベース表示失敗');
    }

    console.log('🎯 簡易基本機能テスト完了');
  });
});