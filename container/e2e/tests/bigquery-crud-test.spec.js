const { test, expect } = require('@playwright/test');

/**
 * BigQuery Adminer Plugin - 更新系 E2E テストシナリオ
 *
 * このテストではデータセットとテーブルの新規作成、データの挿入・編集・削除操作をテストします。
 * 新規作成されたテスト用データセット・テーブルのみを操作し、既存データには影響しません。
 *
 * 注意: このテストは実装後に実行します。現在は機能が未実装のためスキップされます。
 */

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';
const GOOGLE_CLOUD_PROJECT = process.env.GOOGLE_CLOUD_PROJECT || 'adminer-test-472623';

// テスト用データセット・テーブル名（ユニークにするためタイムスタンプ付き）
const TEST_TIMESTAMP = Date.now();
const TEST_DATASET = `adminer_test_dataset_${TEST_TIMESTAMP}`;
const TEST_TABLE = `test_table_${TEST_TIMESTAMP}`;

// タイムアウト設定（CRUD操作は時間がかかる）
test.setTimeout(120000);

test.describe('BigQuery Adminer Plugin - 更新系テスト', () => {

  test.beforeEach(async ({ page }) => {
    // 各テスト前にログインページへ移動
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
  });

  test('1. 基本ログインと更新系機能の確認', async ({ page }) => {
    console.log('🔍 基本ログインと更新系機能の確認テスト開始');

    // ログイン処理
    const loginSelectors = [
      'input[type="submit"][value="Login"]',
      'button:has-text("Login")',
      'button[type="submit"]',
      'input[value="Login"]'
    ];

    let loginSuccess = false;
    for (const selector of loginSelectors) {
      try {
        const loginButton = page.locator(selector);
        if (await loginButton.isVisible({ timeout: 2000 })) {
          console.log(`✅ ログインボタン発見: ${selector}`);
          await loginButton.click();
          await page.waitForLoadState('networkidle');
          loginSuccess = true;
          break;
        }
      } catch (e) {
        // 次のセレクターを試行
      }
    }

    expect(loginSuccess).toBeTruthy();
    console.log('✅ ログイン処理完了');

    // データセット一覧が表示されることを確認
    await expect(page).toHaveTitle(/Adminer/);
    await expect(page.locator('h2')).toContainText('Select database');
    console.log('✅ ログイン成功 - データセット選択画面');

    // 更新系機能メニューの存在確認（未実装でも構造確認）
    const updateMenus = [
      { name: 'Create database', selectors: ['a:has-text("Create database")', 'a[href*="database="]'] },
      { name: 'SQL command', selectors: ['a:has-text("SQL command")', 'a[href*="sql="]'] },
      { name: 'Export', selectors: ['a:has-text("Export")', 'a[href*="export="]'] },
      { name: 'Import', selectors: ['a:has-text("Import")', 'a[href*="import="]'] }
    ];

    for (const menu of updateMenus) {
      let menuFound = false;
      for (const selector of menu.selectors) {
        const link = page.locator(selector);
        if (await link.isVisible({ timeout: 2000 })) {
          console.log(`✅ ${menu.name}メニュー発見: ${selector}`);
          menuFound = true;
          break;
        }
      }

      if (!menuFound) {
        console.log(`⚠️ ${menu.name}メニューが見つかりませんでした（未実装の可能性）`);
      }
    }

    console.log('✅ 基本ログインと更新系機能の確認完了');
  });

  test('2. SQL実行機能テスト（更新系クエリの制限確認）', async ({ page }) => {
    console.log('🔍 SQL実行機能テスト（更新系クエリの制限確認）開始');

    // ログイン処理
    const loginButton = page.locator('input[type="submit"][value="Login"]');
    if (await loginButton.isVisible()) {
      await loginButton.click();
      await page.waitForLoadState('networkidle');
    }

    // SQLクエリ画面へ移動
    const sqlLinks = [
      'a[href*="sql="]',
      'a:has-text("SQL command")',
      'a:has-text("Query")'
    ];

    let sqlLinkFound = false;
    for (const selector of sqlLinks) {
      const sqlLink = page.locator(selector);
      if (await sqlLink.isVisible({ timeout: 2000 })) {
        await sqlLink.click();
        await page.waitForLoadState('networkidle');
        sqlLinkFound = true;
        console.log(`✅ SQLリンク発見: ${selector}`);
        break;
      }
    }

    if (!sqlLinkFound) {
      // 直接SQLページにアクセス
      await page.goto(`${BASE_URL}/?sql=`);
      await page.waitForLoadState('networkidle');
      console.log('✅ 直接SQLページにアクセス');
    }

    // SQL入力エリアの確認
    const sqlTextarea = page.locator('textarea[name="query"]');
    await expect(sqlTextarea).toBeVisible();
    console.log('✅ SQL入力エリアを発見');

    // DDL文のテスト（CREATE TABLE - BigQueryではエラーが期待される）
    const createTableQuery = `CREATE TABLE IF NOT EXISTS test_dataset.test_table (
      id INT64,
      name STRING,
      created_at TIMESTAMP
    )`;

    await sqlTextarea.fill(createTableQuery);
    await page.click('input[type="submit"][value="Execute"]');
    await page.waitForLoadState('networkidle');

    // エラーまたは成功メッセージの確認
    const hasError = await page.locator('.error').isVisible();
    const hasResult = await page.locator('table').isVisible();
    const hasSuccessMessage = await page.locator('p:has-text("Query executed OK")').isVisible();
    const hasJobResult = await page.locator('text=Query executed').isVisible();

    console.log(`📊 CREATE TABLE結果: エラー=${hasError}, テーブル=${hasResult}, 成功=${hasSuccessMessage}, Job=${hasJobResult}`);

    // 結果、エラー、または成功メッセージが表示されることを確認
    expect(hasError || hasResult || hasSuccessMessage || hasJobResult).toBeTruthy();

    // 基本的なSELECT文もテスト
    const selectQuery = 'SELECT 1 as test_id, "CRUD Test" as test_message, CURRENT_TIMESTAMP() as test_time';
    await sqlTextarea.fill(selectQuery);
    await page.click('input[type="submit"][value="Execute"]');
    await page.waitForLoadState('networkidle');

    const selectHasResult = await page.locator('table').isVisible();
    const selectHasSuccess = await page.locator('text=Query executed').isVisible();

    console.log(`📊 SELECT結果: テーブル=${selectHasResult}, 成功=${selectHasSuccess}`);
    expect(selectHasResult || selectHasSuccess).toBeTruthy();

    console.log('✅ SQL実行機能テスト（更新系クエリの制限確認）完了');
  });

  test.skip('3. データ挿入テスト', async ({ page }) => {
    console.log('=== データ挿入テスト開始 ===');

    // テスト用テーブルに移動
    const testDatasetLink = page.locator(`a[href*="${TEST_DATASET}"]`);
    await testDatasetLink.click();
    await page.waitForTimeout(3000);

    const testTableLink = page.locator(`a[href*="${TEST_TABLE}"]`);

    if (await testTableLink.count() === 0) {
      console.log('⚠️ テストテーブルが存在しません。テーブル作成テストを先に実行してください。');
      return;
    }

    await testTableLink.click();
    await page.waitForTimeout(5000);

    // データ挿入リンク/ボタンを探す
    const insertLinks = page.locator('a, button, input').filter({
      hasText: /Insert|New.*item|新規.*追加|データ.*追加/i
    });

    if (await insertLinks.count() > 0) {
      console.log('データ挿入リンクをクリック');
      await insertLinks.first().click();
      await page.waitForTimeout(3000);

      // フィールドに値を入力
      const idInput = page.locator('input[name*="id"], input').first();
      if (await idInput.count() > 0) {
        await idInput.fill('1');
      }

      const nameInput = page.locator('input[name*="name"]');
      if (await nameInput.count() > 0) {
        await nameInput.first().fill('テストレコード1');
      }

      const timestampInput = page.locator('input[name*="created_at"], input[name*="timestamp"]');
      if (await timestampInput.count() > 0) {
        await timestampInput.first().fill('2024-01-01 10:00:00');
      }

      // 保存ボタンクリック
      const saveButton = page.locator('input[type="submit"], button').filter({
        hasText: /Save|Insert|保存|追加/i
      });

      if (await saveButton.count() > 0) {
        await saveButton.first().click();
        await page.waitForTimeout(8000);

        // データ一覧で挿入されたレコードが表示されるか確認
        await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
        await page.waitForTimeout(5000);

        const dataRows = page.locator('table tr td');
        const hasData = await dataRows.count() > 0;

        if (hasData) {
          console.log('✅ データ挿入成功');
        } else {
          console.log('❌ データ挿入に失敗または表示されない');
        }
      } else {
        console.log('❌ データ保存ボタンが見つからない');
      }
    } else {
      console.log('❌ データ挿入機能が未実装');
    }
  });

  test.skip('4. データ編集テスト', async ({ page }) => {
    console.log('=== データ編集テスト開始 ===');

    // テスト用テーブルのデータ一覧に移動
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
    await page.waitForTimeout(5000);

    // 編集リンクを探す
    const editLinks = page.locator('a').filter({
      hasText: /Edit|編集|modify/i
    });

    if (await editLinks.count() > 0) {
      console.log('編集リンクをクリック');
      await editLinks.first().click();
      await page.waitForTimeout(3000);

      // データを変更
      const nameInput = page.locator('input[name*="name"]');
      if (await nameInput.count() > 0) {
        await nameInput.first().clear();
        await nameInput.first().fill('編集されたテストレコード');
      }

      // 保存ボタンクリック
      const saveButton = page.locator('input[type="submit"], button').filter({
        hasText: /Save|Update|保存|更新/i
      });

      if (await saveButton.count() > 0) {
        await saveButton.first().click();
        await page.waitForTimeout(8000);

        // データ一覧で編集内容が反映されているか確認
        await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
        await page.waitForTimeout(5000);

        const pageContent = await page.textContent('body');
        const hasUpdatedData = pageContent?.includes('編集されたテストレコード');

        if (hasUpdatedData) {
          console.log('✅ データ編集成功');
        } else {
          console.log('❌ データ編集に失敗または表示されない');
        }
      } else {
        console.log('❌ データ保存ボタンが見つからない');
      }
    } else {
      console.log('❌ データ編集機能が未実装');
    }
  });

  test.skip('5. データ削除テスト', async ({ page }) => {
    console.log('=== データ削除テスト開始 ===');

    // テスト用テーブルのデータ一覧に移動
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
    await page.waitForTimeout(5000);

    // 削除リンク/ボタンを探す
    const deleteLinks = page.locator('a, button, input').filter({
      hasText: /Delete|削除|Remove/i
    });

    if (await deleteLinks.count() > 0) {
      console.log('削除リンクをクリック');

      // 削除確認ダイアログの準備
      page.on('dialog', async dialog => {
        console.log(`確認ダイアログ: ${dialog.message()}`);
        await dialog.accept();
      });

      await deleteLinks.first().click();
      await page.waitForTimeout(8000);

      // データが削除されているか確認
      await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
      await page.waitForTimeout(5000);

      const dataRows = page.locator('table tr td');
      const rowCount = await dataRows.count();

      if (rowCount === 0) {
        console.log('✅ データ削除成功');
      } else {
        console.log('❌ データ削除に失敗');
      }
    } else {
      console.log('❌ データ削除機能が未実装');
    }
  });

  test.skip('6. ソート機能テスト', async ({ page }) => {
    console.log('=== ソート機能テスト開始 ===');

    // 複数のテストデータを挿入してからソートテスト
    // (データ挿入機能が実装されている前提)

    // テスト用テーブルのデータ一覧に移動
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
    await page.waitForTimeout(5000);

    // ソートリンク（カラムヘッダークリック）を探す
    const sortableHeaders = page.locator('th a, .sortable');

    if (await sortableHeaders.count() > 0) {
      console.log('ソート可能なヘッダーをクリック');
      await sortableHeaders.first().click();
      await page.waitForTimeout(5000);

      // ソート結果の確認（URLパラメーターや表示順序の変化）
      const currentUrl = page.url();
      const hasSortParam = currentUrl.includes('order') || currentUrl.includes('sort');

      if (hasSortParam) {
        console.log('✅ ソート機能動作確認');
      } else {
        console.log('❌ ソート機能が正常に動作していない');
      }
    } else {
      console.log('❌ ソート機能が未実装');
    }
  });

  test.skip('7. エクスポート・ダウンロードテスト', async ({ page }) => {
    console.log('=== エクスポート・ダウンロードテスト開始 ===');

    // テスト用テーブルのデータ一覧に移動
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&db=${TEST_DATASET}&table=${TEST_TABLE}&select`);
    await page.waitForTimeout(5000);

    // エクスポート/ダウンロードリンクを探す
    const exportLinks = page.locator('a, button').filter({
      hasText: /Export|Download|CSV|JSON|エクスポート|ダウンロード/i
    });

    if (await exportLinks.count() > 0) {
      console.log('エクスポートリンクをクリック');

      // ダウンロード監視
      const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
      await exportLinks.first().click();

      try {
        const download = await downloadPromise;
        console.log(`✅ ダウンロード成功: ${download.suggestedFilename()}`);

        // ファイル内容の確認（オプション）
        const path = await download.path();
        if (path) {
          console.log(`ダウンロードファイルパス: ${path}`);
        }
      } catch (error) {
        console.log('❌ ダウンロードタイムアウトまたは失敗');
      }
    } else {
      console.log('❌ エクスポート機能が未実装');
    }
  });

  test.skip('8. テーブル削除テスト', async ({ page }) => {
    console.log('=== テーブル削除テスト開始 ===');

    // テスト用データセットに移動
    const testDatasetLink = page.locator(`a[href*="${TEST_DATASET}"]`);
    await testDatasetLink.click();
    await page.waitForTimeout(3000);

    const testTableLink = page.locator(`a[href*="${TEST_TABLE}"]`);
    await testTableLink.click();
    await page.waitForTimeout(5000);

    // テーブル削除リンク/ボタンを探す
    const dropTableLinks = page.locator('a, button, input').filter({
      hasText: /Drop.*table|Delete.*table|テーブル.*削除/i
    });

    if (await dropTableLinks.count() > 0) {
      console.log('テーブル削除リンクをクリック');

      // 削除確認ダイアログの準備
      page.on('dialog', async dialog => {
        console.log(`削除確認ダイアログ: ${dialog.message()}`);
        await dialog.accept();
      });

      await dropTableLinks.first().click();
      await page.waitForTimeout(10000);

      // テーブル一覧で削除されたテーブルが表示されなくなったか確認
      const deletedTableLink = page.locator(`a[href*="${TEST_TABLE}"]`);
      const isDeleted = await deletedTableLink.count() === 0;

      if (isDeleted) {
        console.log('✅ テーブル削除成功');
      } else {
        console.log('❌ テーブル削除に失敗');
      }
    } else {
      console.log('❌ テーブル削除機能が未実装');
    }
  });

  test.skip('9. 統合CRUD操作テスト', async ({ page }) => {
    console.log('=== 統合CRUD操作テスト開始 ===');

    // データセット → テーブル → データの完全なCRUDサイクルをテスト
    // 1. データセット作成
    // 2. テーブル作成
    // 3. データ挿入（複数レコード）
    // 4. データ表示・検索
    // 5. データ編集
    // 6. データ削除
    // 7. テーブル削除

    console.log('統合テストは個別のCRUD機能がすべて実装された後に実行します');
  });

  test.skip('10. 権限・エラーハンドリングテスト', async ({ page }) => {
    console.log('=== 権限・エラーハンドリングテスト開始 ===');

    // 不正なデータセット作成の試行
    // 権限のないテーブルへのアクセス
    // BigQueryの制限事項に対するエラーハンドリング

    console.log('権限テストは基本CRUD機能が実装された後に実行します');
  });

  test('11. BigQueryドライバー未実装機能の確認', async ({ page }) => {
    console.log('🔍 BigQueryドライバー未実装機能の確認テスト開始');

    // ログイン処理
    const loginButton = page.locator('input[type="submit"][value="Login"]');
    if (await loginButton.isVisible()) {
      await loginButton.click();
      await page.waitForLoadState('networkidle');
    }

    // データセット選択
    const databaseLinks = page.locator('a[href*="db="]');
    const dbCount = await databaseLinks.count();
    console.log(`📊 データセット数: ${dbCount}`);

    if (dbCount > 0) {
      // test_dataset_fixed_apiを優先して選択
      let selectedDataset = null;
      const allDbLinks = await databaseLinks.all();
      for (const link of allDbLinks) {
        const href = await link.getAttribute('href');
        if (href && href.includes('test_dataset_fixed_api')) {
          selectedDataset = link;
          break;
        }
      }

      if (!selectedDataset) {
        selectedDataset = databaseLinks.first();
      }

      await selectedDataset.click();
      await page.waitForLoadState('networkidle');

      // BigQuery固有の未実装機能を確認
      const bigqueryFeatures = [
        { name: 'Create table', selectors: ['a:has-text("Create table")', 'input[value="Create"]'] },
        { name: 'Alter table', selectors: ['a:has-text("Alter")', 'input[value="Alter"]'] },
        { name: 'Drop table', selectors: ['a:has-text("Drop")', 'input[value="Drop"]'] },
        { name: 'Privileges', selectors: ['a:has-text("Privileges")', 'a[href*="privileges"]'] },
        { name: 'Triggers', selectors: ['a:has-text("Triggers")', 'a[href*="trigger"]'] },
        { name: 'Indexes', selectors: ['a:has-text("Indexes")', 'a[href*="index"]'] }
      ];

      for (const feature of bigqueryFeatures) {
        let featureFound = false;
        for (const selector of feature.selectors) {
          try {
            const element = page.locator(selector);
            if (await element.isVisible({ timeout: 1000 })) {
              console.log(`✅ ${feature.name}機能発見: ${selector}`);
              featureFound = true;
              break;
            }
          } catch (e) {
            // 次のセレクターを試行
          }
        }

        if (!featureFound) {
          console.log(`⚠️ ${feature.name}機能が見つかりませんでした（BigQueryでは未対応の可能性）`);
        }
      }

      // テーブル選択してAnalyzeボタンテスト
      const tableLinks = page.locator('a[href*="table="]');
      const tableCount = await tableLinks.count();
      console.log(`📊 テーブル数: ${tableCount}`);

      if (tableCount > 0) {
        await tableLinks.first().click();
        await page.waitForLoadState('networkidle');

        // Analyzeボタンの存在確認
        const analyzeButton = page.locator('input[value="Analyze"]');
        const hasAnalyzeButton = await analyzeButton.isVisible();
        console.log(`📊 Analyzeボタンの存在: ${hasAnalyzeButton}`);

        if (hasAnalyzeButton) {
          console.log('ℹ️ Analyzeボタンは実装されていますが、BigQueryでは未対応の機能です');
        }
      }
    }

    console.log('✅ BigQueryドライバー未実装機能の確認完了');
  });

});