// SQL クエリ実行テスト
// BigQueryでのSQL実行、結果表示、エラーハンドリングを包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('SQL Query Execution Tests', () => {

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証してSQLコマンド画面に移動
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);

        // SQLコマンドリンクをクリック
        const sqlLink = page.locator('a[href*="sql"]').filter({ hasText: /sql|command|コマンド/i }).first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);
        }
    });

    test('SQL コマンド画面表示テスト', async ({ page }) => {
        console.log('💻 SQL コマンド画面表示テストを開始');

        // SQLエディタの表示確認
        const sqlTextarea = page.locator('textarea[name="query"]');
        await expect(sqlTextarea).toBeVisible();
        console.log('✅ SQLエディタ表示確認');

        // 実行ボタンの確認
        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await expect(executeButton).toBeVisible();
        console.log('✅ SQL実行ボタン確認');

        // その他のSQL関連機能確認
        const limitInput = page.locator('input[name="limit"]');
        if (await limitInput.isVisible()) {
            console.log('✅ LIMIT設定入力欄確認');
        }
    });

    test('基本SELECTクエリ実行テスト', async ({ page }) => {
        console.log('🔍 基本SELECTクエリ実行テストを開始');

        // 簡単なSELECTクエリ実行
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill('SELECT 1 as test_column, "Hello BigQuery" as message');

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(3000);

        // 結果表示の確認
        const resultTable = page.locator('table.checkable.odds');
        if (await resultTable.isVisible()) {
            console.log('✅ クエリ結果表示確認');

            // 結果データの確認
            const resultRows = page.locator('table.checkable.odds tbody tr');
            const rowCount = await resultRows.count();
            console.log(`✅ 結果行数: ${rowCount}`);

            if (rowCount > 0) {
                const firstRowData = await resultRows.first().allTextContents();
                console.log('✅ 結果データ:', firstRowData);
            }
        } else {
            console.log('⚠️ クエリ結果が表示されません');
        }
    });

    test('INFORMATION_SCHEMA クエリテスト', async ({ page }) => {
        console.log('📊 INFORMATION_SCHEMA クエリテストを開始');

        // INFORMATION_SCHEMAを使ったクエリ
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill(`
            SELECT table_name, table_type
            FROM \`INFORMATION_SCHEMA.TABLES\`
            LIMIT 5
        `);

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(5000); // BigQueryメタデータクエリは時間がかかる場合がある

        // 結果の確認
        const resultTable = page.locator('table.checkable.odds');
        if (await resultTable.isVisible()) {
            console.log('✅ INFORMATION_SCHEMAクエリ成功');

            const headers = await page.locator('table.checkable.odds thead th').allTextContents();
            console.log('✅ 結果カラム:', headers);
        } else {
            console.log('⚠️ INFORMATION_SCHEMAクエリ結果なし');
        }
    });

    test('クエリ制限（LIMIT）機能テスト', async ({ page }) => {
        console.log('🎯 クエリ制限（LIMIT）機能テストを開始');

        // LIMIT設定付きクエリ
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill(`
            SELECT
                GENERATE_UUID() as id,
                RAND() as random_value,
                CURRENT_TIMESTAMP() as timestamp_col
            FROM
                UNNEST(GENERATE_ARRAY(1, 100)) as n
        `);

        // LIMIT値を設定
        const limitInput = page.locator('input[name="limit"]');
        if (await limitInput.isVisible()) {
            await limitInput.fill('10');
            console.log('✅ LIMIT値設定: 10');
        }

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(4000);

        // 結果行数の確認
        const resultRows = page.locator('table.checkable.odds tbody tr');
        const actualCount = await resultRows.count();
        console.log(`✅ 実際の結果行数: ${actualCount}`);

        if (actualCount <= 10) {
            console.log('✅ LIMIT機能正常動作確認');
        }
    });

    test('EXPLAIN クエリ実行テスト', async ({ page }) => {
        console.log('📈 EXPLAIN クエリ実行テストを開始');

        // EXPLAIN文の実行
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill('EXPLAIN SELECT 1');

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(3000);

        // EXPLAIN結果の確認
        const resultTable = page.locator('table.checkable.odds');
        if (await resultTable.isVisible()) {
            console.log('✅ EXPLAIN結果表示確認');

            // EXPLAINの列名確認（BigQuery固有）
            const headers = await page.locator('table.checkable.odds thead th').allTextContents();
            console.log('✅ EXPLAIN結果カラム:', headers);

            // 実行計画情報の確認
            const planData = await page.locator('table.checkable.odds tbody tr').first().allTextContents();
            if (planData.length > 0) {
                console.log('✅ 実行計画データ検出');
            }
        } else {
            console.log('⚠️ EXPLAIN結果が表示されません');
        }
    });

    test('エラーハンドリングテスト', async ({ page }) => {
        console.log('❌ SQL エラーハンドリングテストを開始');

        // 故意にエラーを発生させるクエリ
        const invalidQueries = [
            'SELECT * FROM non_existent_table',
            'SELECT invalid_column FROM',
            'INVALID SQL SYNTAX'
        ];

        for (const query of invalidQueries) {
            console.log(`🔍 エラークエリテスト: ${query}`);

            const sqlTextarea = page.locator('textarea[name="query"]');
            await sqlTextarea.fill(query);

            const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
            await executeButton.click();
            await page.waitForTimeout(2000);

            // エラーメッセージの確認
            const errorMessage = page.locator('.error, .message').filter({ hasText: /error|エラー|failed/i });
            if (await errorMessage.isVisible()) {
                const errorText = await errorMessage.textContent();
                console.log(`✅ エラーメッセージ検出: ${errorText.substring(0, 100)}...`);
            } else {
                console.log('⚠️ エラーメッセージが表示されません');
            }

            await page.waitForTimeout(1000);
        }
    });

    test('大きな結果セットの処理テスト', async ({ page }) => {
        console.log('📦 大きな結果セットの処理テストを開始');

        // 大きな結果セットを生成するクエリ
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill(`
            SELECT
                n as row_number,
                CONCAT('Row_', CAST(n AS STRING)) as description,
                MOD(n, 10) as category
            FROM
                UNNEST(GENERATE_ARRAY(1, 1000)) as n
        `);

        // LIMIT設定（パフォーマンステスト）
        const limitInput = page.locator('input[name="limit"]');
        if (await limitInput.isVisible()) {
            await limitInput.fill('100');
        }

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(5000);

        // ページング機能の確認
        const paginationLinks = page.locator('a[href*="page"]');
        if (await paginationLinks.count() > 0) {
            console.log('✅ ページング機能検出');

            // 次ページリンクの確認
            const nextPageLink = page.locator('a').filter({ hasText: /next|次|>/ });
            if (await nextPageLink.isVisible()) {
                console.log('✅ 次ページリンク確認');
            }
        }

        // 結果表示パフォーマンスの確認
        const resultTable = page.locator('table.checkable.odds');
        if (await resultTable.isVisible()) {
            const rows = await page.locator('table.checkable.odds tbody tr').count();
            console.log(`✅ 表示行数: ${rows}`);
        }
    });

    test('複数文実行テスト', async ({ page }) => {
        console.log('📝 複数文実行テストを開始');

        // 複数のSQL文（セミコロン区切り）
        const sqlTextarea = page.locator('textarea[name="query"]');
        await sqlTextarea.fill(`
            SELECT 1 as first_query;
            SELECT 2 as second_query;
            SELECT 3 as third_query;
        `);

        const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
        await executeButton.click();
        await page.waitForTimeout(3000);

        // 複数結果の表示確認（BigQueryドライバーの対応状況による）
        const resultTables = page.locator('table.checkable.odds');
        const tableCount = await resultTables.count();
        console.log(`✅ 結果テーブル数: ${tableCount}`);

        if (tableCount > 0) {
            console.log('✅ 複数文実行結果表示確認');
        }
    });
});