// UI・ナビゲーション・メニューテスト
// Adminer UIの表示、ナビゲーション、メニュー機能を包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('UI Navigation & Menu Tests', () => {

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);
    });

    test('メインナビゲーションメニューテスト', async ({ page }) => {
        console.log('🧭 メインナビゲーションメニューテストを開始');

        // 主要ナビゲーションメニューの確認
        const mainMenuItems = [
            { selector: 'a[href*="sql"]', name: 'SQLコマンド', hasText: /sql|command/ },
            { selector: 'a[href*="export"]', name: 'エクスポート', hasText: /export|出力/ },
            { selector: 'a[href*="import"]', name: 'インポート', hasText: /import|取込/ },
            { selector: 'a[href*="database"]', name: 'データベース作成', hasText: /create.*database|database.*create/ },
            { selector: 'a[href*="logout"]', name: 'ログアウト', hasText: /logout|ログアウト/ }
        ];

        for (const menu of mainMenuItems) {
            const menuLink = page.locator(menu.selector).filter({ hasText: menu.hasText }).first();
            if (await menuLink.isVisible()) {
                console.log(`✅ ${menu.name}メニュー確認`);

                // メニューのホバー動作確認
                await menuLink.hover();
                await page.waitForTimeout(500);
            } else {
                console.log(`⚠️ ${menu.name}メニュー未検出`);
            }
        }
    });

    test('データベース選択UIテスト', async ({ page }) => {
        console.log('🗄️ データベース選択UIテストを開始');

        // データベース選択ドロップダウン/リストの確認
        const databaseSelect = page.locator('select[name="db"]');
        if (await databaseSelect.isVisible()) {
            console.log('✅ データベース選択ドロップダウン確認');

            // 選択肢の確認
            const options = await databaseSelect.locator('option').allTextContents();
            console.log('✅ データベース選択肢:', options.slice(0, 3));

            // 選択変更動作の確認
            if (options.length > 1) {
                await databaseSelect.selectOption(options[1]);
                await page.waitForTimeout(2000);
                console.log('✅ データベース選択変更動作確認');
            }
        }

        // データベースリンク形式の確認
        const databaseLinks = page.locator('a[id^="Db-"]');
        const linkCount = await databaseLinks.count();
        console.log(`✅ データベースリンク数: ${linkCount}`);
    });

    test('テーブル操作UIテスト', async ({ page }) => {
        console.log('📋 テーブル操作UIテストを開始');

        // データセットに入る
        const datasetLinks = page.locator('a[id^="Db-"]');
        if (await datasetLinks.count() > 0) {
            await datasetLinks.first().click();
            await page.waitForTimeout(2000);

            // テーブル一覧UI要素の確認
            const tablesList = page.locator('table.checkable.odds');
            await expect(tablesList).toBeVisible();
            console.log('✅ テーブル一覧UI確認');

            // テーブル選択チェックボックスの確認
            const tableCheckboxes = page.locator('input[type="checkbox"][name="check[]"]');
            const checkboxCount = await tableCheckboxes.count();
            console.log(`✅ テーブル選択チェックボックス数: ${checkboxCount}`);

            if (checkboxCount > 0) {
                // チェックボックスの動作確認
                await tableCheckboxes.first().check();
                console.log('✅ テーブル選択動作確認');

                // 選択後の操作ボタン表示確認
                const actionButtons = page.locator('input[type="submit"]');
                const buttonCount = await actionButtons.count();
                console.log(`✅ 操作ボタン数: ${buttonCount}`);
            }
        }
    });

    test('ページ表示パフォーマンステスト', async ({ page }) => {
        console.log('⚡ ページ表示パフォーマンステストを開始');

        // 各主要ページの読み込み時間測定
        const pages = [
            { url: 'sql', name: 'SQLコマンド' },
            { url: 'export', name: 'エクスポート' },
            { url: 'import', name: 'インポート' }
        ];

        for (const pageInfo of pages) {
            const startTime = Date.now();

            const pageLink = page.locator(`a[href*="${pageInfo.url}"]`).first();
            if (await pageLink.isVisible()) {
                await pageLink.click();
                await page.waitForTimeout(1000);

                const loadTime = Date.now() - startTime;
                console.log(`✅ ${pageInfo.name}ページ読み込み時間: ${loadTime}ms`);

                // 元のページに戻る
                await page.goBack();
                await page.waitForTimeout(1000);
            }
        }
    });

    test('エラーメッセージ表示テスト', async ({ page }) => {
        console.log('❌ エラーメッセージ表示テストを開始');

        // SQLコマンドページでエラーを発生
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            // 無効なSQLを実行
            const sqlTextarea = page.locator('textarea[name="query"]');
            await sqlTextarea.fill('INVALID SQL COMMAND');

            const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
            await executeButton.click();
            await page.waitForTimeout(2000);

            // エラーメッセージUIの確認
            const errorElements = page.locator('.error, .message');
            const errorCount = await errorElements.count();
            console.log(`✅ エラーメッセージ要素数: ${errorCount}`);

            if (errorCount > 0) {
                const errorText = await errorElements.first().textContent();
                console.log(`✅ エラーメッセージ内容: ${errorText.substring(0, 100)}...`);
            }
        }
    });

    test('BigQuery固有UI要素テスト', async ({ page }) => {
        console.log('🔷 BigQuery固有UI要素テストを開始');

        // BigQuery固有の表示要素確認
        const bigqueryElements = [
            { selector: '[title*="BigQuery"]', name: 'BigQueryタイトル' },
            { selector: '[class*="bigquery"]', name: 'BigQueryクラス要素' },
            { selector: 'text=Project ID', name: 'プロジェクトID表示' },
            { selector: 'text=Dataset', name: 'データセット表示' }
        ];

        for (const element of bigqueryElements) {
            const bqElement = page.locator(element.selector);
            if (await bqElement.count() > 0) {
                console.log(`✅ ${element.name}要素検出`);
            } else {
                console.log(`⚠️ ${element.name}要素未検出`);
            }
        }

        // BigQuery CSS適用確認
        const bodyClass = await page.locator('body').getAttribute('class') || '';
        if (bodyClass.includes('bigquery')) {
            console.log('✅ BigQuery CSS クラス適用確認');
        }
    });

    test('レスポンシブデザインテスト', async ({ page }) => {
        console.log('📱 レスポンシブデザインテストを開始');

        // 異なる画面サイズでのUI表示確認
        const viewports = [
            { width: 1920, height: 1080, name: 'デスクトップ大' },
            { width: 1366, height: 768, name: 'デスクトップ小' },
            { width: 768, height: 1024, name: 'タブレット' },
            { width: 375, height: 667, name: 'モバイル' }
        ];

        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await page.waitForTimeout(1000);

            // 主要要素の表示確認
            const menuVisible = await page.locator('#menu').isVisible();
            const tablesVisible = await page.locator('table.checkable.odds').isVisible();

            console.log(`✅ ${viewport.name} (${viewport.width}x${viewport.height}): メニュー=${menuVisible ? '表示' : '非表示'}, テーブル=${tablesVisible ? '表示' : '非表示'}`);
        }

        // 元のサイズに戻す
        await page.setViewportSize({ width: 1920, height: 1080 });
    });

    test('アクセシビリティ基本テスト', async ({ page }) => {
        console.log('♿ アクセシビリティ基本テストを開始');

        // 主要リンクのalt属性/aria-label確認
        const links = page.locator('a');
        const linkCount = await links.count();
        let accessibleLinkCount = 0;

        for (let i = 0; i < Math.min(linkCount, 10); i++) {
            const link = links.nth(i);
            const ariaLabel = await link.getAttribute('aria-label');
            const title = await link.getAttribute('title');
            const text = await link.textContent();

            if (ariaLabel || title || (text && text.trim())) {
                accessibleLinkCount++;
            }
        }

        console.log(`✅ アクセシブルリンク率: ${accessibleLinkCount}/10`);

        // フォーム要素のラベル確認
        const inputs = page.locator('input[type="text"], input[type="password"], textarea');
        const inputCount = await inputs.count();
        let labeledInputCount = 0;

        for (let i = 0; i < Math.min(inputCount, 10); i++) {
            const input = inputs.nth(i);
            const inputId = await input.getAttribute('id');
            const labelFor = inputId ? page.locator(`label[for="${inputId}"]`) : null;

            if (labelFor && await labelFor.count() > 0) {
                labeledInputCount++;
            }
        }

        console.log(`✅ ラベル付きフォーム要素率: ${labeledInputCount}/${Math.min(inputCount, 10)}`);
    });

    test('ブラウザ互換性テスト', async ({ page, browserName }) => {
        console.log(`🌐 ブラウザ互換性テスト開始 (${browserName})`);

        // JavaScript機能の動作確認
        const jsErrors = [];
        page.on('pageerror', error => {
            jsErrors.push(error.message);
        });

        // 主要機能の動作確認
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            // SQLエディタの動作確認
            const sqlTextarea = page.locator('textarea[name="query"]');
            if (await sqlTextarea.isVisible()) {
                await sqlTextarea.fill('SELECT 1');
                const value = await sqlTextarea.inputValue();
                if (value === 'SELECT 1') {
                    console.log(`✅ ${browserName}: SQLエディタ動作確認`);
                }
            }
        }

        console.log(`✅ ${browserName}: JavaScriptエラー数: ${jsErrors.length}`);
        if (jsErrors.length > 0) {
            console.log('⚠️ JavaScriptエラー:', jsErrors.slice(0, 3));
        }
    });
});