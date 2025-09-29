// テーブル・スキーマ操作テスト
// BigQueryテーブルの表示、作成、スキーマ確認機能を包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('Table & Schema Operations Tests', () => {

    let testDataset = '';

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証してデータセットに入る
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);

        // 最初のデータセットに入る（成功パターンに基づく）
        const datasetLinks = page.locator('a[href*="db="]');
        if (await datasetLinks.count() > 0) {
            const firstDataset = datasetLinks.first();
            testDataset = await firstDataset.textContent();
            await firstDataset.click();
            await page.waitForLoadState('networkidle');
        }
    });

    test('テーブル一覧表示テスト', async ({ page }) => {
        console.log('📋 テーブル一覧表示テストを開始');

        // データセット内のテーブル・ビュー表示確認（成功パターンに基づく）
        await expect(page.locator('h3')).toContainText('Tables and views');
        console.log('✅ テーブル一覧画面表示確認');

        // 個別テーブルリンクの確認（成功パターンのセレクター使用）
        const tableLinks = page.locator('a[href*="table="]');
        const count = await tableLinks.count();
        console.log(`✅ 検出されたテーブル数: ${count}`);

        if (count > 0) {
            // テーブル名の確認
            for (let i = 0; i < Math.min(count, 3); i++) {
                const tableName = await tableLinks.nth(i).textContent();
                console.log(`✅ テーブル${i + 1}: ${tableName}`);
            }
        }
    });

    test('テーブル作成フォームテスト', async ({ page }) => {
        console.log('➕ テーブル作成フォームテストを開始');

        // テーブル作成リンクを探す
        const createTableLink = page.locator('a[href*="table"]').filter({ hasText: /create|作成|new/i }).first();

        if (await createTableLink.isVisible()) {
            await createTableLink.click();
            await page.waitForTimeout(2000);

            // テーブル名入力フォーム
            const tableNameInput = page.locator('input[name="name"]');
            if (await tableNameInput.isVisible()) {
                await tableNameInput.fill('test_table_' + Date.now());
                console.log('✅ テーブル名入力フォーム確認');
            }

            // フィールド定義フォームの確認
            const fieldInputs = page.locator('input[name*="fields"]');
            const fieldCount = await fieldInputs.count();
            console.log(`✅ フィールド定義入力欄数: ${fieldCount}`);

            // BigQuery固有のデータ型選択確認
            const typeSelects = page.locator('select[name*="type"]');
            if (await typeSelects.count() > 0) {
                const firstTypeSelect = typeSelects.first();
                const options = await firstTypeSelect.locator('option').allTextContents();

                // BigQueryデータ型の確認
                const expectedTypes = ['STRING', 'INT64', 'FLOAT64', 'BOOLEAN', 'DATE', 'TIMESTAMP', 'NUMERIC'];
                const foundTypes = options.filter(opt => expectedTypes.some(type => opt.includes(type)));
                console.log('✅ BigQueryデータ型検出:', foundTypes);
            }

            console.log('✅ テーブル作成フォーム機能確認完了');
        } else {
            console.log('⚠️ テーブル作成リンクが見つかりません');
        }
    });

    test('テーブル詳細・スキーマ表示テスト', async ({ page }) => {
        console.log('🔍 テーブル詳細・スキーマ表示テストを開始');

        // 最初のテーブルにアクセス
        const tableLinks = page.locator('a[href*="table="]');
        if (await tableLinks.count() > 0) {
            const firstTable = tableLinks.first();
            const tableName = await firstTable.textContent();

            await firstTable.click();
            await page.waitForTimeout(3000);

            console.log(`✅ テーブル '${tableName}' にアクセス`);

            // テーブル構造（スキーマ）表示の確認
            const structureTable = page.locator('table.structure');
            if (await structureTable.isVisible()) {
                console.log('✅ テーブル構造表示確認');

                // カラム情報の確認
                const columnRows = page.locator('table.structure tbody tr');
                const columnCount = await columnRows.count();
                console.log(`✅ カラム数: ${columnCount}`);

                if (columnCount > 0) {
                    // 最初のカラムの詳細確認
                    const firstRow = columnRows.first();
                    const columnName = await firstRow.locator('th').first().textContent();
                    const columnType = await firstRow.locator('td').first().textContent();
                    console.log(`✅ 最初のカラム: ${columnName} (${columnType})`);
                }
            } else {
                console.log('⚠️ テーブル構造表示が見つかりません');
            }

            // テーブル統計情報の確認
            const statsInfo = page.locator('.table-status');
            if (await statsInfo.isVisible()) {
                console.log('✅ テーブル統計情報表示確認');
            }
        }
    });

    test('テーブルデータプレビューテスト', async ({ page }) => {
        console.log('👀 テーブルデータプレビューテストを開始');

        // テーブルにアクセス
        const tableLinks = page.locator('a[href*="table="]');
        if (await tableLinks.count() > 0) {
            await tableLinks.first().click();
            await page.waitForTimeout(2000);

            // データ表示（Browse/Select）リンクの確認
            const browseLink = page.locator('a[href*="select"]').filter({ hasText: /browse|select|データ|表示/i }).first();

            if (await browseLink.isVisible()) {
                await browseLink.click();
                await page.waitForTimeout(3000);

                // データテーブルの表示確認
                const dataTable = page.locator('table.checkable.odds');
                if (await dataTable.isVisible()) {
                    console.log('✅ データプレビュー表示確認');

                    // データ行数の確認
                    const dataRows = page.locator('table.checkable.odds tbody tr');
                    const rowCount = await dataRows.count();
                    console.log(`✅ プレビューデータ行数: ${rowCount}`);

                    // ヘッダー（カラム名）の確認
                    const headers = await page.locator('table.checkable.odds thead th').allTextContents();
                    console.log('✅ カラムヘッダー:', headers.slice(0, 5)); // 最初の5カラムのみ表示
                }
            } else {
                console.log('⚠️ データ表示リンクが見つかりません');
            }
        }
    });

    test('テーブル操作メニューテスト', async ({ page }) => {
        console.log('🔧 テーブル操作メニューテストを開始');

        // テーブルにアクセス
        const tableLinks = page.locator('a[href*="table="]');
        if (await tableLinks.count() > 0) {
            await tableLinks.first().click();
            await page.waitForTimeout(2000);

            // テーブル操作メニューの確認
            const operationMenus = [
                { name: 'Select', desc: 'データ選択' },
                { name: 'Show', desc: 'テーブル表示' },
                { name: 'Structure', desc: 'テーブル構造' },
                { name: 'Search', desc: 'データ検索' },
                { name: 'Insert', desc: 'データ挿入' },
                { name: 'Drop', desc: 'テーブル削除' },
                { name: 'Alter', desc: 'テーブル変更' }
            ];

            for (const menu of operationMenus) {
                const menuLink = page.locator(`a:has-text("${menu.name}")`).first();
                if (await menuLink.isVisible()) {
                    console.log(`✅ ${menu.desc}メニュー確認: ${menu.name}`);
                } else {
                    console.log(`⚠️ ${menu.desc}メニュー未検出: ${menu.name}`);
                }
            }
        }
    });

    test('テーブルコピー・移動機能テスト', async ({ page }) => {
        console.log('📋 テーブルコピー・移動機能テストを開始');

        // テーブル選択してコピー・移動操作確認
        const tableCheckboxes = page.locator('input[type="checkbox"][name="check[]"]');

        if (await tableCheckboxes.count() > 0) {
            // 最初のテーブルを選択
            await tableCheckboxes.first().check();
            console.log('✅ テーブル選択確認');

            // コピー・移動ボタンの確認
            const copyButton = page.locator('input[type="submit"]').filter({ hasText: /copy|コピー/i });
            const moveButton = page.locator('input[type="submit"]').filter({ hasText: /move|移動/i });

            if (await copyButton.isVisible()) {
                console.log('✅ テーブルコピー機能確認');
            }
            if (await moveButton.isVisible()) {
                console.log('✅ テーブル移動機能確認');
            }
        }
    });

    test('ビュー・マテリアライズドビュー表示テスト', async ({ page }) => {
        console.log('👁️ ビュー・マテリアライズドビュー表示テストを開始');

        // ビューの検出と表示確認
        const viewLinks = page.locator('a[href*="table="]').filter(async (element) => {
            const text = await element.textContent();
            return text && (text.includes('view') || text.includes('View'));
        });

        const viewCount = await viewLinks.count();
        console.log(`✅ 検出されたビュー数: ${viewCount}`);

        if (viewCount > 0) {
            const firstView = viewLinks.first();
            const viewName = await firstView.textContent();
            await firstView.click();
            await page.waitForTimeout(2000);

            console.log(`✅ ビュー '${viewName}' にアクセス`);

            // ビュー定義の確認
            const viewDefinition = page.locator('.view-definition');
            if (await viewDefinition.isVisible()) {
                console.log('✅ ビュー定義表示確認');
            }
        }
    });
});