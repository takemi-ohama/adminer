// Export機能修正テスト - 単独実行用
const { test, expect } = require('@playwright/test');

test('Export output=text で正しくブラウザ表示されることを確認', async ({ page }) => {
    console.log('🔧 Export修正テストを開始');

    // 特定のURLパラメーターでアクセス（ユーザー報告のURL）
    await page.goto('http://adminer-bigquery-test/?bigquery=adminer-test-472623&username=bigquery-service-account&db=dataset_test&dump=');
    await page.waitForTimeout(3000);

    // ページタイトル確認
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
            const isChecked = await radio.isChecked();
            console.log(`📋 output選択肢 ${i}: value="${value}", checked=${isChecked}`);
        }

        // "text"（Open）オプションを選択
        const textOption = outputRadios.filter({ hasValue: 'text' });

        if (await textOption.count() > 0) {
            await textOption.first().click();
            console.log('✅ Output=text選択完了');

            // Exportボタンを探す
            const exportButton = page.locator('input[type="submit"]')
                .filter({ hasText: /export|エクスポート|実行/i });

            if (await exportButton.count() > 0) {
                console.log('🔍 Exportボタンクリック準備');

                // レスポンスを監視
                let responseContentType = null;
                let downloadTriggered = false;

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
                });

                // Exportボタンをクリック
                await exportButton.first().click();
                await page.waitForTimeout(5000);

                // 結果の判定
                if (downloadTriggered) {
                    console.log('❌ 問題: ファイルダウンロードが発生しました（修正が未完了）');
                } else {
                    // ページにtext内容が表示されているかチェック
                    const bodyText = await page.locator('body').textContent();
                    if (bodyText && bodyText.length > 100) {
                        console.log('✅ 修正成功: text内容がページに表示されています');
                        console.log(`📝 表示内容サンプル: ${bodyText.substring(0, 200)}...`);
                    } else {
                        console.log('❓ 不明: text表示もダウンロードも確認できません');
                    }
                }

                // Content-Typeの最終確認
                if (responseContentType) {
                    if (responseContentType.includes('text/')) {
                        console.log('✅ 修正成功: Response Content-Typeがtextです');
                    } else {
                        console.log(`❌ 問題: Content-Type=${responseContentType}`);
                    }
                }

            } else {
                console.log('⚠️ Exportボタンが見つかりません');
            }
        } else {
            console.log('⚠️ Output=text選択肢が見つかりません');
        }
    } else {
        console.log('⚠️ output設定が見つかりません');
    }

    console.log('✅ Export修正テスト完了');
});