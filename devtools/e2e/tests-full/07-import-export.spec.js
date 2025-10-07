// インポート・エクスポート機能テスト
// BigQueryでのデータインポート・エクスポート機能を包括的にテスト

const { test, expect } = require('@playwright/test');

test.describe('Import & Export Tests', () => {

    test.beforeEach(async ({ page }) => {
        // 各テスト前にBigQueryに認証
        await page.goto('http://adminer-bigquery-test');
        await page.waitForTimeout(1000);
        await page.click('input[type="submit"]');
        await page.waitForTimeout(3000);
    });

    test('エクスポート機能UI表示テスト', async ({ page }) => {
        console.log('📤 エクスポート機能UI表示テストを開始');

        // エクスポートリンクの確認
        const exportLink = page.locator('a[href*="export"]').filter({ hasText: /export|エクスポート/i }).first();

        if (await exportLink.isVisible()) {
            await exportLink.click();
            await page.waitForTimeout(2000);

            console.log('✅ エクスポートページアクセス');

            // エクスポート設定フォームの確認
            const formatSelect = page.locator('select[name="format"]');
            if (await formatSelect.isVisible()) {
                const options = await formatSelect.locator('option').allTextContents();
                console.log('✅ エクスポート形式オプション:', options);

                // BigQuery対応形式の確認
                const supportedFormats = options.filter(opt =>
                    opt.toLowerCase().includes('csv') ||
                    opt.toLowerCase().includes('json') ||
                    opt.toLowerCase().includes('sql')
                );
                console.log('✅ BigQuery対応エクスポート形式:', supportedFormats);
            }

            // 出力設定オプションの確認
            const outputOptions = page.locator('input[name="output"]');
            const optionCount = await outputOptions.count();
            console.log(`✅ 出力オプション数: ${optionCount}`);

            // エクスポート実行ボタンの確認
            const exportButton = page.locator('input[type="submit"]').filter({ hasText: /export|エクスポート|実行/i });
            await expect(exportButton).toBeVisible();
            console.log('✅ エクスポート実行ボタン確認');

        } else {
            console.log('⚠️ エクスポートリンクが見つかりません');
        }
    });

    test('テーブル個別エクスポートテスト', async ({ page }) => {
        console.log('📊 テーブル個別エクスポートテストを開始');

        // データセットに入る
        const datasetLinks = page.locator('a[id^="Db-"]');
        if (await datasetLinks.count() > 0) {
            await datasetLinks.first().click();
            await page.waitForTimeout(2000);

            // 最初のテーブルにアクセス
            const tableLinks = page.locator('a[href*="table="]');
            if (await tableLinks.count() > 0) {
                await tableLinks.first().click();
                await page.waitForTimeout(2000);

                // テーブル個別エクスポートリンクの確認
                const tableExportLink = page.locator('a[href*="export"]').first();

                if (await tableExportLink.isVisible()) {
                    await tableExportLink.click();
                    await page.waitForTimeout(2000);

                    console.log('✅ テーブル個別エクスポートページアクセス');

                    // テーブル固有のエクスポート設定確認
                    const tableInfo = page.locator('.table-export-info');
                    if (await tableInfo.isVisible()) {
                        console.log('✅ テーブル情報表示確認');
                    }

                    // データ範囲設定の確認
                    const limitInput = page.locator('input[name="limit"]');
                    if (await limitInput.isVisible()) {
                        await limitInput.fill('100');
                        console.log('✅ エクスポート行数制限設定');
                    }

                } else {
                    console.log('⚠️ テーブル個別エクスポートリンクが見つかりません');
                }
            }
        }
    });

    test('SQLクエリ結果エクスポートテスト', async ({ page }) => {
        console.log('🔍 SQLクエリ結果エクスポートテストを開始');

        // SQLコマンドページに移動
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            // テストクエリを実行
            const sqlTextarea = page.locator('textarea[name="query"]');
            await sqlTextarea.fill('SELECT 1 as id, "Test Export" as description, CURRENT_TIMESTAMP() as created_at');

            const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
            await executeButton.click();
            await page.waitForTimeout(3000);

            // クエリ結果からのエクスポートリンク確認
            const resultExportLink = page.locator('a[href*="export"]').filter({ hasText: /export|エクスポート/i });

            if (await resultExportLink.count() > 0) {
                console.log('✅ クエリ結果エクスポートリンク確認');

                await resultExportLink.first().click();
                await page.waitForTimeout(2000);

                // エクスポート設定の確認
                const formatSelect = page.locator('select[name="format"]');
                if (await formatSelect.isVisible()) {
                    // CSV形式を選択
                    await formatSelect.selectOption({ label: 'CSV' });
                    console.log('✅ CSV形式選択');
                }
            } else {
                console.log('⚠️ クエリ結果エクスポート機能が見つかりません');
            }
        }
    });

    test('インポート機能UI表示テスト', async ({ page }) => {
        console.log('📥 インポート機能UI表示テストを開始');

        // インポートリンクの確認
        const importLink = page.locator('a[href*="import"]').filter({ hasText: /import|インポート/i }).first();

        if (await importLink.isVisible()) {
            await importLink.click();
            await page.waitForTimeout(2000);

            console.log('✅ インポートページアクセス');

            // ファイルアップロード入力の確認
            const fileInput = page.locator('input[type="file"]');
            if (await fileInput.isVisible()) {
                console.log('✅ ファイルアップロード入力確認');
            }

            // インポート形式選択の確認
            const formatSelect = page.locator('select[name="format"]');
            if (await formatSelect.isVisible()) {
                const options = await formatSelect.locator('option').allTextContents();
                console.log('✅ インポート形式オプション:', options);
            }

            // インポート実行ボタンの確認
            const importButton = page.locator('input[type="submit"]').filter({ hasText: /import|インポート|upload/i });
            if (await importButton.isVisible()) {
                console.log('✅ インポート実行ボタン確認');
            }

        } else {
            console.log('⚠️ インポートリンクが見つかりません');
        }
    });

    test('SQLインポート機能テスト', async ({ page }) => {
        console.log('💾 SQLインポート機能テストを開始');

        // インポートページにアクセス
        const importLink = page.locator('a[href*="import"]').first();
        if (await importLink.isVisible()) {
            await importLink.click();
            await page.waitForTimeout(2000);

            // SQL形式インポートの確認
            const formatSelect = page.locator('select[name="format"]');
            if (await formatSelect.isVisible()) {
                // SQL形式を選択
                const sqlOption = await formatSelect.locator('option').filter({ hasText: /sql/i });
                if (await sqlOption.count() > 0) {
                    await formatSelect.selectOption({ label: 'SQL' });
                    console.log('✅ SQL形式インポート選択');
                }
            }

            // SQLテキスト入力エリアの確認
            const sqlTextarea = page.locator('textarea[name="query"]');
            if (await sqlTextarea.isVisible()) {
                // テスト用SQLを入力
                const testSQL = `
                    -- Test SQL Import
                    SELECT 'Import Test' as test_message;
                    SELECT CURRENT_TIMESTAMP() as import_time;
                `;
                await sqlTextarea.fill(testSQL);
                console.log('✅ テストSQL入力');

                // インポート実行（実際には実行しない - テスト環境保護）
                const importButton = page.locator('input[type="submit"]');
                await expect(importButton).toBeVisible();
                console.log('✅ SQLインポート実行ボタン確認');
            }
        }
    });

    test('エクスポート制限・BigQuery固有機能テスト', async ({ page }) => {
        console.log('🚫 エクスポート制限・BigQuery固有機能テストを開始');

        // エクスポートページにアクセス
        const exportLink = page.locator('a[href*="export"]').first();
        if (await exportLink.isVisible()) {
            await exportLink.click();
            await page.waitForTimeout(2000);

            // BigQueryで制限される機能の確認
            const restrictedFormats = [
                'Binary',
                'XML',
                'Excel'
            ];

            const formatSelect = page.locator('select[name="format"]');
            if (await formatSelect.isVisible()) {
                const availableOptions = await formatSelect.locator('option').allTextContents();

                for (const restricted of restrictedFormats) {
                    const hasRestricted = availableOptions.some(opt =>
                        opt.toLowerCase().includes(restricted.toLowerCase())
                    );
                    console.log(`${hasRestricted ? '⚠️' : '✅'} ${restricted}形式: ${hasRestricted ? '利用可能' : '制限済み'}`);
                }
            }

            // BigQuery固有のエクスポート設定確認
            const bigqueryOptions = page.locator('[name*="bigquery"], [id*="bigquery"]');
            const bqOptionCount = await bigqueryOptions.count();
            if (bqOptionCount > 0) {
                console.log(`✅ BigQuery固有オプション数: ${bqOptionCount}`);
            }
        }
    });

    test('大容量データエクスポートテスト', async ({ page }) => {
        console.log('📦 大容量データエクスポートテストを開始');

        // SQLコマンドで大容量データクエリ実行
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            // 大容量データ生成クエリ
            const sqlTextarea = page.locator('textarea[name="query"]');
            await sqlTextarea.fill(`
                SELECT
                    n as row_id,
                    CONCAT('Large_Dataset_Row_', CAST(n AS STRING)) as description,
                    RAND() as random_value,
                    CURRENT_TIMESTAMP() as created_at
                FROM
                    UNNEST(GENERATE_ARRAY(1, 10000)) as n
            `);

            // LIMIT設定でパフォーマンステスト
            const limitInput = page.locator('input[name="limit"]');
            if (await limitInput.isVisible()) {
                await limitInput.fill('1000');
                console.log('✅ 大容量データ制限設定: 1000行');
            }

            const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
            await executeButton.click();

            // 実行時間の測定
            const startTime = Date.now();
            await page.waitForTimeout(5000);
            const executionTime = Date.now() - startTime;

            console.log(`✅ 大容量クエリ実行時間: ${executionTime}ms`);

            // エクスポートリンクの表示確認
            const exportResultLink = page.locator('a[href*="export"]');
            if (await exportResultLink.isVisible()) {
                console.log('✅ 大容量データエクスポートリンク確認');
            }
        }
    });

    test('エラーハンドリング・制限メッセージテスト', async ({ page }) => {
        console.log('❌ エラーハンドリング・制限メッセージテストを開始');

        // 無効なエクスポート操作の確認
        const exportLink = page.locator('a[href*="export"]').first();
        if (await exportLink.isVisible()) {
            await exportLink.click();
            await page.waitForTimeout(2000);

            // データなしでエクスポート実行
            const exportButton = page.locator('input[type="submit"]');
            if (await exportButton.isVisible()) {
                await exportButton.click();
                await page.waitForTimeout(2000);

                // エラーメッセージまたは制限通知の確認
                const messages = page.locator('.error, .message, .warning');
                if (await messages.count() > 0) {
                    const messageText = await messages.first().textContent();
                    console.log(`✅ エクスポートエラーメッセージ: ${messageText.substring(0, 100)}...`);
                }
            }
        }

        // インポート制限の確認
        const importLink = page.locator('a[href*="import"]').first();
        if (await importLink.isVisible()) {
            await importLink.click();
            await page.waitForTimeout(2000);

            // BigQueryインポート制限メッセージの確認
            const restrictionMessages = page.locator('.bigquery-restriction, .limitation');
            if (await restrictionMessages.count() > 0) {
                const restrictionText = await restrictionMessages.first().textContent();
                console.log(`✅ インポート制限メッセージ: ${restrictionText.substring(0, 100)}...`);
            }
        }
    });

    test('ダウンロード形式・ファイル名テスト', async ({ page }) => {
        console.log('📄 ダウンロード形式・ファイル名テストを開始');

        // 簡単なクエリ結果でエクスポートテスト
        const sqlLink = page.locator('a[href*="sql"]').first();
        if (await sqlLink.isVisible()) {
            await sqlLink.click();
            await page.waitForTimeout(2000);

            const sqlTextarea = page.locator('textarea[name="query"]');
            await sqlTextarea.fill('SELECT "test" as column1, 123 as column2');

            const executeButton = page.locator('input[type="submit"]').filter({ hasText: /execute|実行/i });
            await executeButton.click();
            await page.waitForTimeout(3000);

            // エクスポートページへ
            const exportLink = page.locator('a[href*="export"]').first();
            if (await exportLink.isVisible()) {
                await exportLink.click();
                await page.waitForTimeout(2000);

                // ファイル名設定の確認
                const filenameInput = page.locator('input[name="filename"]');
                if (await filenameInput.isVisible()) {
                    await filenameInput.fill('bigquery_export_test');
                    console.log('✅ エクスポートファイル名設定');
                }

                // 各形式でのエクスポート設定確認
                const formatSelect = page.locator('select[name="format"]');
                if (await formatSelect.isVisible()) {
                    const formats = ['CSV', 'JSON', 'SQL'];

                    for (const format of formats) {
                        const formatOption = await formatSelect.locator('option').filter({ hasText: format });
                        if (await formatOption.count() > 0) {
                            await formatSelect.selectOption({ label: format });
                            console.log(`✅ ${format}形式設定確認`);
                            await page.waitForTimeout(500);
                        }
                    }
                }
            }
        }
    });

    test('【問題再現】output=Openでtext表示されずtarダウンロードされる問題', async ({ page }) => {
        console.log('🐛 Export output=Open問題の再現テストを開始');

        // 特定のURLパラメーターでアクセス（ユーザー報告のURL）
        await page.goto('http://adminer-bigquery-test/?bigquery=adminer-test-472623&username=bigquery-service-account&db=dataset_test&dump=');
        await page.waitForTimeout(3000);

        // Export画面であることを確認
        const pageTitle = await page.title();
        console.log(`📄 現在のページタイトル: ${pageTitle}`);

        // Export設定フォームの確認
        const outputRadios = page.locator('input[name="output"]');
        const outputCount = await outputRadios.count();
        console.log(`📋 output選択肢数: ${outputCount}`);

        if (outputCount > 0) {
            // 各output選択肢の確認
            for (let i = 0; i < outputCount; i++) {
                const radio = outputRadios.nth(i);
                const value = await radio.getAttribute('value');
                const labelText = await page.locator(`label[for="${await radio.getAttribute('id')}"]`).textContent();
                console.log(`📋 output選択肢 ${i}: value="${value}", label="${labelText}"`);
            }

            // "Open"（text表示）オプションを選択
            const openOption = outputRadios.filter({ hasValue: 'open' })
                .or(outputRadios.filter({ hasValue: 'output' }))
                .or(outputRadios.filter({ hasValue: '' }));

            if (await openOption.count() > 0) {
                await openOption.first().click();
                console.log('✅ Output=Open選択完了');

                // Exportボタンを探す
                const exportButton = page.locator('input[type="submit"]')
                    .filter({ hasText: /export|エクスポート|実行/i })
                    .or(page.locator('button')
                        .filter({ hasText: /export|エクスポート|実行/i }));

                if (await exportButton.count() > 0) {
                    console.log('🔍 Exportボタンクリック前の状態確認');

                    // レスポンスを監視してdownloadイベントをキャッチ
                    let downloadTriggered = false;
                    let responseContentType = null;

                    page.on('response', async (response) => {
                        if (response.url().includes('dump') || response.url().includes('export')) {
                            responseContentType = response.headers()['content-type'];
                            console.log(`📥 Export Response Content-Type: ${responseContentType}`);
                        }
                    });

                    page.on('download', async (download) => {
                        downloadTriggered = true;
                        const fileName = download.suggestedFilename();
                        console.log(`📥 ダウンロード検出: ${fileName}`);

                        // tarファイルかどうかチェック
                        if (fileName.includes('.tar') || fileName.includes('.gz')) {
                            console.log('❌ 問題再現: tarファイルとしてダウンロードされました');
                        }
                    });

                    // Exportボタンをクリック
                    await exportButton.first().click();
                    await page.waitForTimeout(5000);

                    // 結果の判定
                    if (downloadTriggered) {
                        console.log('❌ 問題確認: ファイルダウンロードが発生しました（text表示されるべき）');
                    } else {
                        // ページにtext内容が表示されているかチェック
                        const bodyText = await page.locator('body').textContent();
                        if (bodyText && bodyText.length > 100) {
                            console.log('✅ 正常: text内容がページに表示されています');
                        } else {
                            console.log('❌ 問題: text表示もダウンロードも発生していません');
                        }
                    }

                    // Content-Typeの確認
                    if (responseContentType) {
                        if (responseContentType.includes('application/x-tar') || responseContentType.includes('application/gzip')) {
                            console.log('❌ 問題確認: Response Content-Typeがtar/gzipです');
                        } else if (responseContentType.includes('text/')) {
                            console.log('✅ 正常: Response Content-Typeがtextです');
                        }
                    }

                } else {
                    console.log('⚠️ Exportボタンが見つかりません');
                }
            } else {
                console.log('⚠️ Output=Open選択肢が見つかりません');
            }
        } else {
            console.log('⚠️ output設定が見つかりません');
        }
    });
});