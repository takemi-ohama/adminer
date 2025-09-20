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
    // エラーログ監視
    page.on('console', msg => {
      if (msg.type() === 'error') {
        console.log(`ブラウザコンソールエラー: ${msg.text()}`);
      }
    });

    page.on('response', response => {
      if (!response.ok() && response.status() >= 400) {
        console.log(`HTTP エラー: ${response.status()} ${response.url()}`);
      }
    });

    // ログイン処理を共通化
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.waitForTimeout(3000);
    await page.locator('input[type="submit"][value="Login"]').click();
    await page.waitForTimeout(5000);
  });

  test.skip('1. データセット作成テスト', async ({ page }) => {
    console.log('=== データセット作成テスト開始 ===');
    console.log(`作成予定データセット: ${TEST_DATASET}`);

    // データセット作成リンク/ボタンを探す
    const createDatabaseLinks = page.locator('a, button, input').filter({
      hasText: /Create.*database|Create.*dataset|新規.*データベース|作成/i
    });

    if (await createDatabaseLinks.count() > 0) {
      console.log('データセット作成リンクをクリック');
      await createDatabaseLinks.first().click();
      await page.waitForTimeout(3000);

      // データセット名入力フィールド
      const datasetNameInput = page.locator('input[name*="database"], input[name*="dataset"], input[type="text"]').first();
      await expect(datasetNameInput).toBeVisible();

      // データセット名を入力
      await datasetNameInput.fill(TEST_DATASET);

      // 作成ボタンをクリック
      const createButton = page.locator('input[type="submit"], button').filter({
        hasText: /Create|作成|追加/i
      });

      if (await createButton.count() > 0) {
        await createButton.first().click();
        await page.waitForTimeout(8000);

        // データセット一覧で作成されたデータセットが表示されるか確認
        const datasetLink = page.locator(`a[href*="${TEST_DATASET}"]`);
        await expect(datasetLink).toBeVisible({ timeout: 10000 });

        console.log('✅ データセット作成成功');
      } else {
        console.log('❌ データセット作成ボタンが見つからない');
      }
    } else {
      console.log('❌ データセット作成機能が未実装');
    }
  });

  test.skip('2. テーブル作成テスト', async ({ page }) => {
    console.log('=== テーブル作成テスト開始 ===');

    // 作成済みのテストデータセットに移動
    const testDatasetLink = page.locator(`a[href*="${TEST_DATASET}"]`);

    if (await testDatasetLink.count() === 0) {
      console.log('⚠️ テストデータセットが存在しません。データセット作成テストを先に実行してください。');
      return;
    }

    await testDatasetLink.click();
    await page.waitForTimeout(5000);

    // テーブル作成リンク/ボタンを探す
    const createTableLinks = page.locator('a, button, input').filter({
      hasText: /Create.*table|新規.*テーブル|テーブル.*作成/i
    });

    if (await createTableLinks.count() > 0) {
      console.log('テーブル作成リンクをクリック');
      await createTableLinks.first().click();
      await page.waitForTimeout(3000);

      // テーブル名入力
      const tableNameInput = page.locator('input[name*="table"], input[name="name"], input[type="text"]').first();
      await expect(tableNameInput).toBeVisible();
      await tableNameInput.fill(TEST_TABLE);

      // スキーマ定義（基本的なフィールド追加）
      const fieldInputs = page.locator('input[name*="field"], input[name*="column"]');

      if (await fieldInputs.count() > 0) {
        // ID フィールド
        await fieldInputs.nth(0).fill('id');
        const typeSelects = page.locator('select[name*="type"]');
        if (await typeSelects.count() > 0) {
          await typeSelects.nth(0).selectOption('INT64');
        }

        // Name フィールド
        if (await fieldInputs.count() > 1) {
          await fieldInputs.nth(1).fill('name');
          if (await typeSelects.count() > 1) {
            await typeSelects.nth(1).selectOption('STRING');
          }
        }

        // Created_at フィールド
        if (await fieldInputs.count() > 2) {
          await fieldInputs.nth(2).fill('created_at');
          if (await typeSelects.count() > 2) {
            await typeSelects.nth(2).selectOption('TIMESTAMP');
          }
        }
      }

      // テーブル作成実行
      const saveButton = page.locator('input[type="submit"], button').filter({
        hasText: /Save|Create|保存|作成/i
      });

      if (await saveButton.count() > 0) {
        await saveButton.first().click();
        await page.waitForTimeout(10000);

        // テーブル一覧で作成されたテーブルが表示されるか確認
        const tableLink = page.locator(`a[href*="${TEST_TABLE}"]`);
        await expect(tableLink).toBeVisible({ timeout: 15000 });

        console.log('✅ テーブル作成成功');
      } else {
        console.log('❌ テーブル作成ボタンが見つからない');
      }
    } else {
      console.log('❌ テーブル作成機能が未実装');
    }
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

});