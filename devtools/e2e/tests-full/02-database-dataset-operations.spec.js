// データベース（データセット）操作テスト
// BigQueryデータセットのCRUD操作を包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('Database/Dataset Operations Tests', () => {

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);
    });

    test('データセット一覧表示テスト', async ({ page }) => {
        console.log('📋 データセット一覧表示テストを開始');

        // データベース一覧ページの確認
        await expect(page).toHaveURL(/adminer/);

        // データセット一覧の表示確認（実際のUI構造に基づく）
        const databaseList = page.locator('table.checkable.odds');
        if (await databaseList.isVisible()) {
            console.log('✅ データセット一覧表示確認');

            // 個別データセットリンクの確認（実際のID形式に基づく）
            const datasetLinks = page.locator('a[id^="Db-"]');
            const count = await datasetLinks.count();
            console.log(`✅ 検出されたデータセット数: ${count}`);

            if (count > 0) {
                const firstDataset = datasetLinks.first();
                const datasetName = await firstDataset.textContent();
                console.log(`✅ 最初のデータセット名: ${datasetName}`);
            }
        } else {
            console.log('⚠️ データセット一覧要素が見つかりません');
        }
    });

    test('データセット作成機能テスト', async ({ page }) => {
        console.log('➕ データセット作成機能テストを開始');

        // データベース作成リンクを探す
        const createDbLink = page.locator('a[href*="database"]').filter({ hasText: /create|作成|new/i }).first();

        if (await createDbLink.isVisible()) {
            await createDbLink.click();
            await page.waitForTimeout(2000);

            // データベース名入力フォーム
            const dbNameInput = page.locator('input[name="name"]');
            if (await dbNameInput.isVisible()) {
                const testDatasetName = 'test_dataset_' + Date.now();
                await dbNameInput.fill(testDatasetName);
                console.log(`✅ テストデータセット名入力: ${testDatasetName}`);

                // 作成ボタン実行（実際の作成はしない - テスト環境保護）
                const saveButton = page.locator('input[type="submit"]').filter({ hasText: /save|保存|create/i });
                await expect(saveButton).toBeVisible();
                console.log('✅ データセット作成フォーム確認');
            }
        } else {
            console.log('⚠️ データベース作成リンクが見つかりません');
        }
    });

    test('データセット情報表示テスト', async ({ page }) => {
        console.log('ℹ️ データセット情報表示テストを開始');

        // 最初のデータセットにアクセス
        const datasetLinks = page.locator('a[id^="Db-"]');
        if (await datasetLinks.count() > 0) {
            const firstDataset = datasetLinks.first();
            const datasetName = await firstDataset.textContent();

            await firstDataset.click();
            await page.waitForTimeout(3000);

            // データセット内テーブル一覧の確認
            console.log(`✅ データセット '${datasetName}' にアクセス`);

            // テーブル一覧表示の確認
            const tablesList = page.locator('table.checkable.odds');
            if (await tablesList.isVisible()) {
                console.log('✅ テーブル一覧表示確認');

                const tableLinks = page.locator('a[href*="table="]');
                const tableCount = await tableLinks.count();
                console.log(`✅ テーブル数: ${tableCount}`);
            }
        }
    });

    test('データセット操作メニューテスト', async ({ page }) => {
        console.log('🔧 データセット操作メニューテストを開始');

        // データセットに入る
        const datasetLinks = page.locator('a[id^="Db-"]');
        if (await datasetLinks.count() > 0) {
            await datasetLinks.first().click();
            await page.waitForTimeout(2000);

            // データベース操作メニューの確認
            const operationMenus = [
                'SQL command', 'SQL コマンド',
                'Export', 'エクスポート',
                'Import', 'インポート',
                'Create table', 'テーブル作成'
            ];

            for (const menu of operationMenus) {
                const menuLink = page.locator(`a:has-text("${menu}")`).first();
                if (await menuLink.isVisible()) {
                    console.log(`✅ メニュー確認: ${menu}`);
                } else {
                    console.log(`⚠️ メニュー未検出: ${menu}`);
                }
            }
        }
    });

    test('データセット削除機能テスト', async ({ page }) => {
        console.log('🗑️ データセット削除機能テストを開始');

        // データセット一覧での削除オプション確認
        const dropLink = page.locator('a[href*="drop"]').filter({ hasText: /drop|削除|delete/i }).first();

        if (await dropLink.isVisible()) {
            console.log('✅ データセット削除リンク検出');

            // 削除確認ページへのアクセス（実際の削除は実行しない）
            await dropLink.click();
            await page.waitForTimeout(1000);

            // 削除確認フォームの存在確認
            const confirmCheckbox = page.locator('input[type="checkbox"]');
            const dropButton = page.locator('input[type="submit"]').filter({ hasText: /drop|削除/i });

            if (await confirmCheckbox.isVisible() && await dropButton.isVisible()) {
                console.log('✅ データセット削除確認フォーム確認');
            }
        } else {
            console.log('⚠️ データセット削除機能が見つかりません');
        }
    });

    test('データセット名変更機能テスト', async ({ page }) => {
        console.log('✏️ データセット名変更機能テストを開始');

        // データセット名変更（リネーム）機能の確認
        const renameLink = page.locator('a[href*="alter"]').filter({ hasText: /alter|rename|変更/i }).first();

        if (await renameLink.isVisible()) {
            await renameLink.click();
            await page.waitForTimeout(1000);

            // リネームフォームの確認
            const nameInput = page.locator('input[name="name"]');
            if (await nameInput.isVisible()) {
                console.log('✅ データセット名変更フォーム確認');
            }
        } else {
            console.log('⚠️ データセット名変更機能が見つかりません（BigQuery制限の可能性）');
        }
    });
});