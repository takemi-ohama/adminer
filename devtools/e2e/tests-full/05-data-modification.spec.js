// データ変更操作テスト
// INSERT、UPDATE、DELETEなどのBigQueryデータ変更機能を包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('Data Modification Tests', () => {

    let testTable = '';
    let testDataset = '';

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);

        // テスト用データセットとテーブルの特定
        const datasetLinks = page.locator('a[id^="Db-"]');
        if (await datasetLinks.count() > 0) {
            testDataset = await datasetLinks.first().textContent();
            await datasetLinks.first().click();
            await page.waitForTimeout(2000);

            const tableLinks = page.locator('a[href*="table="]');
            if (await tableLinks.count() > 0) {
                testTable = await tableLinks.first().textContent();
            }
        }
    });

    test('データ挿入（INSERT）フォームテスト', async ({ page }) => {
        console.log('➕ データ挿入（INSERT）フォームテストを開始');

        if (testTable) {
            // テーブルにアクセス
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            // INSERT リンクの確認
            const insertLink = page.locator('a[href*="edit"]').filter({ hasText: /insert|挿入|new/i }).first();

            if (await insertLink.isVisible()) {
                await insertLink.click();
                await page.waitForTimeout(2000);

                console.log('✅ データ挿入フォームアクセス');

                // フィールド入力フォームの確認
                const fieldInputs = page.locator('input[name*="fields"], textarea[name*="fields"]');
                const fieldCount = await fieldInputs.count();
                console.log(`✅ データ入力フィールド数: ${fieldCount}`);

                if (fieldCount > 0) {
                    // 最初のフィールドにテストデータを入力
                    await fieldInputs.first().fill('Test Data');
                    console.log('✅ テストデータ入力');

                    // 保存ボタンの確認
                    const saveButton = page.locator('input[type="submit"]').filter({ hasText: /save|保存|insert/i });
                    await expect(saveButton).toBeVisible();
                    console.log('✅ データ保存ボタン確認');
                }
            } else {
                console.log('⚠️ データ挿入リンクが見つかりません');
            }
        }
    });

    test('データ編集（UPDATE）フォームテスト', async ({ page }) => {
        console.log('✏️ データ編集（UPDATE）フォームテストを開始');

        if (testTable) {
            // テーブルのデータ表示ページに移動
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            const selectLink = page.locator('a[href*="select"]').first();
            if (await selectLink.isVisible()) {
                await selectLink.click();
                await page.waitForTimeout(3000);

                // データ編集リンクの確認
                const editLinks = page.locator('a[href*="edit"]').filter({ hasText: /edit|編集|modify/i });

                if (await editLinks.count() > 0) {
                    await editLinks.first().click();
                    await page.waitForTimeout(2000);

                    console.log('✅ データ編集フォームアクセス');

                    // 編集フォームフィールドの確認
                    const editInputs = page.locator('input[name*="fields"], textarea[name*="fields"]');
                    const inputCount = await editInputs.count();
                    console.log(`✅ 編集可能フィールド数: ${inputCount}`);

                    if (inputCount > 0) {
                        // 既存データの確認
                        const firstInput = editInputs.first();
                        const currentValue = await firstInput.inputValue();
                        console.log(`✅ 現在の値: ${currentValue}`);

                        // 更新ボタンの確認
                        const updateButton = page.locator('input[type="submit"]').filter({ hasText: /save|保存|update/i });
                        await expect(updateButton).toBeVisible();
                        console.log('✅ データ更新ボタン確認');
                    }
                } else {
                    console.log('⚠️ データ編集リンクが見つかりません');
                }
            }
        }
    });

    test('データ削除（DELETE）機能テスト', async ({ page }) => {
        console.log('🗑️ データ削除（DELETE）機能テストを開始');

        if (testTable) {
            // テーブルのデータ表示ページに移動
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            const selectLink = page.locator('a[href*="select"]').first();
            if (await selectLink.isVisible()) {
                await selectLink.click();
                await page.waitForTimeout(3000);

                // データ行選択チェックボックスの確認
                const rowCheckboxes = page.locator('input[type="checkbox"][name="check[]"]');
                const checkboxCount = await rowCheckboxes.count();
                console.log(`✅ 選択可能データ行数: ${checkboxCount}`);

                if (checkboxCount > 0) {
                    // 最初の行を選択
                    await rowCheckboxes.first().check();
                    console.log('✅ データ行選択');

                    // 削除ボタンの確認
                    const deleteButton = page.locator('input[type="submit"]').filter({ hasText: /delete|削除|drop/i });
                    if (await deleteButton.isVisible()) {
                        console.log('✅ データ削除ボタン確認');
                    }
                }
            }
        }
    });

    test('一括データ操作テスト', async ({ page }) => {
        console.log('📦 一括データ操作テストを開始');

        if (testTable) {
            // テーブルのデータ表示ページに移動
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            const selectLink = page.locator('a[href*="select"]').first();
            if (await selectLink.isVisible()) {
                await selectLink.click();
                await page.waitForTimeout(3000);

                // 全選択チェックボックスの確認
                const selectAllCheckbox = page.locator('input[type="checkbox"][onclick*="check"]').first();
                if (await selectAllCheckbox.isVisible()) {
                    await selectAllCheckbox.click();
                    console.log('✅ 全選択チェックボックス動作確認');

                    // 一括操作メニューの確認
                    const bulkOperations = [
                        { name: 'edit selected', desc: '選択行編集' },
                        { name: 'delete', desc: '削除' },
                        { name: 'export', desc: 'エクスポート' }
                    ];

                    for (const op of bulkOperations) {
                        const opButton = page.locator('input[type="submit"]').filter({ hasText: new RegExp(op.name, 'i') });
                        if (await opButton.isVisible()) {
                            console.log(`✅ ${op.desc}操作確認`);
                        }
                    }
                }
            }
        }
    });

    test('データ型別入力テスト', async ({ page }) => {
        console.log('🎯 データ型別入力テストを開始');

        if (testTable) {
            // INSERT フォームにアクセス
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            const insertLink = page.locator('a[href*="edit"]').filter({ hasText: /insert|挿入/i }).first();
            if (await insertLink.isVisible()) {
                await insertLink.click();
                await page.waitForTimeout(2000);

                // フィールドタイプ別のテストデータ
                const testData = {
                    string: 'テストstring値',
                    int: '123456',
                    float: '123.456',
                    boolean: 'true',
                    date: '2023-12-25',
                    datetime: '2023-12-25 10:30:00',
                    timestamp: '2023-12-25 10:30:00 UTC'
                };

                // 各フィールドのタイプを確認して適切なデータを入力
                const fieldInputs = page.locator('input[name*="fields"], textarea[name*="fields"]');
                const fieldCount = await fieldInputs.count();

                for (let i = 0; i < Math.min(fieldCount, 3); i++) {
                    const fieldInput = fieldInputs.nth(i);

                    // フィールドタイプの推定（ラベルやプレースホルダーから）
                    const fieldLabel = await page.locator(`label`).nth(i).textContent() || '';

                    if (fieldLabel.toLowerCase().includes('string')) {
                        await fieldInput.fill(testData.string);
                        console.log(`✅ STRING型テストデータ入力`);
                    } else if (fieldLabel.toLowerCase().includes('int')) {
                        await fieldInput.fill(testData.int);
                        console.log(`✅ INT型テストデータ入力`);
                    } else {
                        await fieldInput.fill(testData.string);
                        console.log(`✅ 汎用テストデータ入力`);
                    }
                }
            }
        }
    });

    test('データ検索・フィルター機能テスト', async ({ page }) => {
        console.log('🔍 データ検索・フィルター機能テストを開始');

        if (testTable) {
            // テーブルにアクセス
            const tableLink = page.locator(`a:has-text("${testTable}")`);
            await tableLink.click();
            await page.waitForTimeout(2000);

            // 検索リンクの確認
            const searchLink = page.locator('a[href*="search"]').filter({ hasText: /search|検索|filter/i }).first();

            if (await searchLink.isVisible()) {
                await searchLink.click();
                await page.waitForTimeout(2000);

                console.log('✅ データ検索フォームアクセス');

                // 検索条件入力フォームの確認
                const searchInputs = page.locator('input[name*="where"], select[name*="where"]');
                const searchCount = await searchInputs.count();
                console.log(`✅ 検索条件入力欄数: ${searchCount}`);

                if (searchCount > 0) {
                    // 簡単な検索条件を設定
                    const firstSearchInput = searchInputs.first();
                    await firstSearchInput.fill('test');
                    console.log('✅ 検索条件設定');

                    // 検索実行ボタンの確認
                    const searchButton = page.locator('input[type="submit"]').filter({ hasText: /search|検索|select/i });
                    await expect(searchButton).toBeVisible();
                    console.log('✅ 検索実行ボタン確認');
                }
            } else {
                console.log('⚠️ データ検索リンクが見つかりません');
            }
        }
    });

    test('BigQuery DML制限テスト', async ({ page }) => {
        console.log('⚠️ BigQuery DML制限テストを開始');

        // SQLコマンドページに移動
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            // BigQueryで制限されるDML操作のテスト
            const restrictedOperations = [
                'DELETE FROM test_table WHERE 1=1', // WHERE句なしDELETE
                'UPDATE test_table SET col1 = "new_value"', // WHERE句なしUPDATE
                'TRUNCATE TABLE test_table' // TRUNCATE操作
            ];

            for (const operation of restrictedOperations) {
                console.log(`🚫 制限操作テスト: ${operation}`);

                const sqlTextarea = page.locator('textarea[name="query"]');
                await sqlTextarea.fill(operation);

                const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
                await executeButton.click();
                await page.waitForTimeout(2000);

                // エラーメッセージまたは制限通知の確認
                const errorMessage = page.locator('.error, .message');
                if (await errorMessage.isVisible()) {
                    console.log('✅ 制限操作の適切なエラーハンドリング確認');
                } else {
                    console.log('⚠️ 制限操作のエラーメッセージなし');
                }

                await page.waitForTimeout(1000);
            }
        }
    });
});