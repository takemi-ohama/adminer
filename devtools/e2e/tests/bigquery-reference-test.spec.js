const { test, expect } = require('@playwright/test');

/**
 * BigQuery Adminer Plugin - 参照系 E2E テストシナリオ
 *
 * このテストでは既存のデータを使用してすべてのメニュー、ボタン、リンクの動作確認を実行します。
 * データの作成・編集・削除は行わず、既存のBigQueryデータセット・テーブルを参照のみ行います。
 */

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';
const GOOGLE_CLOUD_PROJECT = process.env.GOOGLE_CLOUD_PROJECT || 'adminer-test-472623';

// タイムアウト設定 (BigQuery処理が遅いため)
test.setTimeout(60000);

test.describe('BigQuery Adminer Plugin - 参照系テスト', () => {

  test.beforeEach(async ({ page }) => {
    // エラーログ監視
    page.on('console', msg => {
      if (msg.type() === 'error') {
        console.log(`ブラウザコンソールエラー: ${msg.text()}`);
      }
    });

    // ネットワークエラー監視
    page.on('response', response => {
      if (!response.ok() && response.status() >= 400) {
        console.log(`HTTP エラー: ${response.status()} ${response.url()}`);
      }
    });
  });

  test('1. 初期ログインとプロジェクト接続テスト', async ({ page }) => {
    console.log('=== 初期ログインテスト開始 ===');

    // BigQueryドライバーでアクセス
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);

    // ログインフォームの確認
    await expect(page.locator('h1')).toContainText(['Adminer', 'Login']);

    // プロジェクトIDが入力済みか確認
    const projectInput = page.locator('input[name="auth[server]"]');
    await expect(projectInput).toHaveValue(GOOGLE_CLOUD_PROJECT);

    // ドライバーがBigQueryに設定されているか確認
    const driverSelect = page.locator('select[name="auth[driver]"]');
    await expect(driverSelect).toHaveValue('bigquery');

    // ログインボタンクリック
    const loginButton = page.locator('input[type="submit"][value="Login"]');
    await expect(loginButton).toBeVisible();
    await loginButton.click();

    // ログイン後のページ読み込み待機
    await page.waitForTimeout(5000);

    // ログイン成功の確認 (データベース一覧画面に遷移)
    const pageTitle = page.locator('h1, h2, .h1, .h2');
    await expect(pageTitle).toContainText(['Database', 'データベース', GOOGLE_CLOUD_PROJECT], { timeout: 10000 });

    console.log('✅ 初期ログイン成功');
  });

  test('2. データベース(データセット)一覧表示テスト', async ({ page }) => {
    console.log('=== データベース一覧表示テスト開始 ===');

    // ログイン処理
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    // データベース一覧の存在確認
    const databaseList = page.locator('#dbs, .db-list, [id*="database"], [class*="database"]');

    // データセット名のリンクが表示されているか確認
    const datasetLinks = page.locator('a[href*="db="]');
    const datasetCount = await datasetLinks.count();

    console.log(`データセット数: ${datasetCount}`);
    expect(datasetCount).toBeGreaterThan(0);

    // 最初のデータセットリンクをクリック
    if (datasetCount > 0) {
      const firstDataset = datasetLinks.first();
      const datasetName = await firstDataset.textContent();
      console.log(`最初のデータセット: ${datasetName}`);

      await firstDataset.click();
      await page.waitForTimeout(3000);

      // テーブル一覧画面に遷移したか確認
      const tableSection = page.locator('#tables, .tables, [id*="table"], h3');
      await expect(tableSection).toBeVisible({ timeout: 10000 });

      console.log('✅ データセット選択成功');
    }
  });

  test('3. テーブル一覧表示テスト', async ({ page }) => {
    console.log('=== テーブル一覧表示テスト開始 ===');

    // ログインとデータセット選択
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    // 最初のデータセットを選択
    const datasetLinks = page.locator('a[href*="db="]');
    if (await datasetLinks.count() > 0) {
      await datasetLinks.first().click();
      await page.waitForTimeout(3000);
    }

    // テーブル一覧の確認
    const tableLinks = page.locator('a[href*="table="]');
    const tableCount = await tableLinks.count();

    console.log(`テーブル数: ${tableCount}`);

    if (tableCount > 0) {
      // テーブル情報の表示確認
      const tableRows = page.locator('table tr, .table-row');
      await expect(tableRows.first()).toBeVisible();

      // テーブル名、型、行数などの情報が表示されているか確認
      const tableInfo = page.locator('td, th');
      await expect(tableInfo.first()).toBeVisible();

      console.log('✅ テーブル一覧表示成功');
    } else {
      console.log('⚠️ テーブルが存在しないデータセット');
    }
  });

  test('4. テーブル構造表示テスト', async ({ page }) => {
    console.log('=== テーブル構造表示テスト開始 ===');

    // ログイン、データセット、テーブル選択
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    const datasetLinks = page.locator('a[href*="db="]');
    if (await datasetLinks.count() > 0) {
      await datasetLinks.first().click();
      await page.waitForTimeout(3000);
    }

    const tableLinks = page.locator('a[href*="table="]');
    if (await tableLinks.count() > 0) {
      const firstTable = tableLinks.first();
      const tableName = await firstTable.textContent();
      console.log(`選択されたテーブル: ${tableName}`);

      await firstTable.click();
      await page.waitForTimeout(5000);

      // テーブル構造の表示確認
      const structureTable = page.locator('table');
      await expect(structureTable.first()).toBeVisible();

      // カラム情報の確認
      const columnHeaders = page.locator('th');
      const columnNames = [];
      const headerCount = await columnHeaders.count();

      for (let i = 0; i < Math.min(headerCount, 10); i++) {
        const headerText = await columnHeaders.nth(i).textContent();
        if (headerText) columnNames.push(headerText.trim());
      }

      console.log(`カラムヘッダー: ${columnNames.join(', ')}`);

      // 型情報が表示されているか確認
      const typeColumns = page.locator('td');
      await expect(typeColumns.first()).toBeVisible();

      console.log('✅ テーブル構造表示成功');
    }
  });

  test('5. データ表示テスト (Select data)', async ({ page }) => {
    console.log('=== データ表示テスト開始 ===');

    // テーブル選択まで実行
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    const datasetLinks = page.locator('a[href*="db="]');
    if (await datasetLinks.count() > 0) {
      await datasetLinks.first().click();
      await page.waitForTimeout(3000);
    }

    const tableLinks = page.locator('a[href*="table="]');
    if (await tableLinks.count() > 0) {
      await tableLinks.first().click();
      await page.waitForTimeout(5000);

      // "Select data" / "データ選択" リンクを探してクリック
      const selectDataLink = page.locator('a[href*="select"], a').filter({
        hasText: /Select|データ|select|Select data/i
      });

      if (await selectDataLink.count() > 0) {
        console.log('Select dataリンクをクリック');
        await selectDataLink.first().click();
        await page.waitForTimeout(10000);

        // データテーブルの表示確認
        const dataTable = page.locator('table');
        await expect(dataTable.first()).toBeVisible({ timeout: 15000 });

        // 実際のデータ行が表示されているか確認
        const dataRows = page.locator('table tr');
        const rowCount = await dataRows.count();
        console.log(`データ行数: ${rowCount}`);

        if (rowCount > 1) { // ヘッダー行以外にデータ行があるか
          console.log('✅ データ表示成功');
        } else {
          console.log('⚠️ データが空のテーブル');
        }
      } else {
        console.log('❌ Select dataリンクが見つからない');
      }
    }
  });

  test('6. SQL実行テスト', async ({ page }) => {
    console.log('=== SQL実行テスト開始 ===');

    // ログイン後、SQL実行画面にアクセス
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    // SQL実行リンクを探す
    const sqlLinks = page.locator('a').filter({ hasText: /SQL|sql|クエリ|Query/ });

    if (await sqlLinks.count() > 0) {
      await sqlLinks.first().click();
      await page.waitForTimeout(3000);

      // SQL入力フィールドの確認
      const sqlTextarea = page.locator('textarea[name="query"], #query, textarea');
      await expect(sqlTextarea.first()).toBeVisible();

      // 簡単なクエリを入力
      const simpleQuery = `SELECT 1 as test_column, 'Hello BigQuery' as message`;
      await sqlTextarea.first().fill(simpleQuery);

      // 実行ボタンクリック
      const executeButton = page.locator('input[type="submit"], button').filter({
        hasText: /Execute|実行|Run/
      });

      if (await executeButton.count() > 0) {
        await executeButton.first().click();
        await page.waitForTimeout(10000);

        // 結果の表示確認
        const resultTable = page.locator('table');
        await expect(resultTable.first()).toBeVisible({ timeout: 15000 });

        // 結果データの確認
        const resultText = page.locator('td');
        await expect(resultText.first()).toBeVisible();

        console.log('✅ SQL実行成功');
      }
    } else {
      console.log('❌ SQLリンクが見つからない');
    }
  });

  test('7. ナビゲーション・メニューテスト', async ({ page }) => {
    console.log('=== ナビゲーション・メニューテスト開始 ===');

    // ログイン処理
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    // メインメニューのリンク確認
    const navigationLinks = [
      'Database',
      'SQL',
      'データベース',
      'クエリ'
    ];

    for (const linkText of navigationLinks) {
      const menuLink = page.locator('a').filter({ hasText: new RegExp(linkText, 'i') });
      if (await menuLink.count() > 0) {
        console.log(`✅ メニューリンク発見: ${linkText}`);

        // リンクをクリックして動作確認
        await menuLink.first().click();
        await page.waitForTimeout(2000);

        // ページが正常に読み込まれたか確認
        await expect(page.locator('body')).toBeVisible();

        // 戻るボタンまたはブラウザバック
        await page.goBack();
        await page.waitForTimeout(1000);
      }
    }

    console.log('✅ ナビゲーションテスト完了');
  });

  test('8. エラーハンドリングテスト', async ({ page }) => {
    console.log('=== エラーハンドリングテスト開始 ===');

    // ログイン処理
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    // 存在しないデータセットへのアクセステスト
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=non_existent_dataset&username=`);
    await page.waitForTimeout(5000);

    // エラーメッセージまたは適切なハンドリングの確認
    const errorMessages = page.locator('.error, .message, .alert');
    const bodyText = await page.locator('body').textContent();

    // エラーが適切にハンドリングされているか確認
    const hasErrorHandling =
      await errorMessages.count() > 0 ||
      bodyText?.includes('not found') ||
      bodyText?.includes('error') ||
      bodyText?.includes('エラー');

    if (hasErrorHandling) {
      console.log('✅ エラーハンドリング確認');
    } else {
      console.log('⚠️ エラーハンドリングの動作が不明');
    }

    // 不正なSQLテスト
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&sql=&username=`);
    await page.waitForTimeout(3000);

    const sqlTextarea = page.locator('textarea[name="query"], #query, textarea');
    if (await sqlTextarea.count() > 0) {
      await sqlTextarea.first().fill('INVALID SQL SYNTAX');

      const executeButton = page.locator('input[type="submit"], button').filter({
        hasText: /Execute|実行|Run/
      });

      if (await executeButton.count() > 0) {
        await executeButton.first().click();
        await page.waitForTimeout(5000);

        // エラーメッセージの表示確認
        const sqlError = await page.locator('body').textContent();
        if (sqlError?.includes('error') || sqlError?.includes('エラー')) {
          console.log('✅ SQL エラーハンドリング確認');
        }
      }
    }
  });

  test('9. UI要素の存在確認テスト', async ({ page }) => {
    console.log('=== UI要素存在確認テスト開始 ===');

    // テーブル選択まで実行
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);

    const datasetLinks = page.locator('a[href*="db="]');
    if (await datasetLinks.count() > 0) {
      await datasetLinks.first().click();
      await page.waitForTimeout(3000);
    }

    const tableLinks = page.locator('a[href*="table="]');
    if (await tableLinks.count() > 0) {
      await tableLinks.first().click();
      await page.waitForTimeout(5000);

      // 重要なUI要素の存在確認
      const uiElements = [
        'Select data',
        'Edit',
        'Create',
        'Drop',
        'Show',
        'Structure',
        'Export'
      ];

      for (const element of uiElements) {
        const elementLink = page.locator('a, button, input').filter({
          hasText: new RegExp(element, 'i')
        });

        const count = await elementLink.count();
        if (count > 0) {
          console.log(`✅ UI要素発見: ${element} (${count}個)`);
        } else {
          console.log(`❌ UI要素未実装: ${element}`);
        }
      }
    }
  });

  test('10. パフォーマンス・読み込み時間テスト', async ({ page }) => {
    console.log('=== パフォーマンステスト開始 ===');

    const startTime = Date.now();

    // ログイン処理の時間測定
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);

    const loginStart = Date.now();
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);
    const loginTime = Date.now() - loginStart;

    console.log(`ログイン時間: ${loginTime}ms`);

    // データセット読み込み時間
    const datasetStart = Date.now();
    const datasetLinks = page.locator('a[href*="db="]');
    if (await datasetLinks.count() > 0) {
      await datasetLinks.first().click();
      await page.waitForTimeout(3000);
    }
    const datasetTime = Date.now() - datasetStart;

    console.log(`データセット読み込み時間: ${datasetTime}ms`);

    // テーブル読み込み時間
    const tableStart = Date.now();
    const tableLinks = page.locator('a[href*="table="]');
    if (await tableLinks.count() > 0) {
      await tableLinks.first().click();
      await page.waitForTimeout(5000);
    }
    const tableTime = Date.now() - tableStart;

    console.log(`テーブル読み込み時間: ${tableTime}ms`);

    const totalTime = Date.now() - startTime;
    console.log(`総実行時間: ${totalTime}ms`);

    // パフォーマンス要件の確認（目安）
    if (loginTime < 10000) console.log('✅ ログイン速度良好');
    if (datasetTime < 5000) console.log('✅ データセット読み込み速度良好');
    if (tableTime < 8000) console.log('✅ テーブル読み込み速度良好');

    console.log('✅ パフォーマンステスト完了');
  });
});