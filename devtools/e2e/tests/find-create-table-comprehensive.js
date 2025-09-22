/**
 * 包括的「テーブルを作成」リンク調査スクリプト
 * 複数のデータセットを調査して、作成系リンクを発見
 */

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

async function findCreateTableComprehensive() {
  console.log('🔍 包括的「テーブルを作成」リンク調査開始');
  console.log(`接続URL: ${BASE_URL}`);

  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    // ログイン処理
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    const loginButton = page.locator('input[type="submit"][value="Login"]');
    await loginButton.click();
    await page.waitForLoadState('networkidle');
    console.log('✅ ログイン成功');

    // 利用可能な全データベースを調査
    console.log('📝 Step 2: 全データベース調査');
    const databaseLinks = page.locator('a[href*="database="]');
    const dbCount = await databaseLinks.count();
    console.log(`📊 検出データベース数: ${dbCount}`);

    for (let i = 0; i < dbCount; i++) {
      console.log(`\n🔄 データベース ${i + 1}/${dbCount} を調査中...`);

      try {
        // データベースリンクを再取得（ページ遷移後のため）
        const currentDbLinks = page.locator('a[href*="database="]');
        const dbLink = currentDbLinks.nth(i);
        const dbName = await dbLink.textContent();
        console.log(`🎯 データベース: ${dbName}`);

        await dbLink.click();
        await page.waitForLoadState('networkidle');

        // このデータベースで作成系リンクを調査
        const createPatterns = [
          { pattern: 'a:has-text("Create table")', desc: 'Create table' },
          { pattern: 'a:has-text("テーブルを作成")', desc: 'テーブルを作成' },
          { pattern: 'a:has-text("Create")', desc: 'Create' },
          { pattern: 'a:has-text("作成")', desc: '作成' },
          { pattern: 'a[href*="create"]', desc: 'create含有URL' },
          { pattern: 'a[href*="table"]', desc: 'table含有URL' },
          { pattern: 'a[href*="new"]', desc: 'new含有URL' }
        ];

        let foundInThisDb = false;
        for (const { pattern, desc } of createPatterns) {
          try {
            const links = await page.locator(pattern).count();
            if (links > 0) {
              console.log(`✅ 【${desc}】発見: ${links}個`);
              foundInThisDb = true;

              // 詳細を表示
              for (let j = 0; j < Math.min(links, 3); j++) {
                const link = page.locator(pattern).nth(j);
                const text = await link.textContent();
                const href = await link.getAttribute('href');
                console.log(`   ${j+1}: "${text?.trim() || ''}" -> ${href || 'no href'}`);

                // 「テーブルを作成」系リンクが見つかった場合、クリックしてエラーテスト
                if (pattern.includes('Create table') || pattern.includes('テーブルを作成') || href?.includes('create')) {
                  console.log(`\n🖱️ テストクリック: "${text?.trim()}"`);

                  try {
                    await link.click();
                    await page.waitForLoadState('networkidle');

                    // エラー検出実行
                    const errorResult = await performQuickErrorCheck(page);
                    if (!errorResult) {
                      console.log('❌ エラー検出成功！未実装機能エラーを確認');
                    } else {
                      console.log('✅ エラーなし（期待される動作）');
                    }

                    // 元のページに戻る
                    await page.goBack();
                    await page.waitForLoadState('networkidle');

                  } catch (clickError) {
                    console.log(`⚠️ クリックテストエラー: ${clickError.message}`);
                  }
                }
              }
            }
          } catch (e) {
            // パターン検索エラーは無視
          }
        }

        if (!foundInThisDb) {
          console.log('❌ 作成系リンクなし');
        }

        // トップページ（データベース一覧）に戻る
        await page.goto(`${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account`);
        await page.waitForLoadState('networkidle');

      } catch (dbError) {
        console.log(`⚠️ データベース ${i + 1} 調査エラー: ${dbError.message}`);
      }
    }

  } catch (error) {
    console.log(`❌ エラー: ${error.message}`);
  } finally {
    await browser.close();
  }
}

// 簡易エラー検出
async function performQuickErrorCheck(page) {
  const pageContent = await page.content();
  const errorPatterns = [
    /Fatal error/i,
    /Parse error/i,
    /Call to undefined function/i,
    /not supported|not implemented|unsupported/i
  ];

  for (const pattern of errorPatterns) {
    if (pattern.test(pageContent)) {
      console.log(`   📋 エラーパターン検出: ${pattern.source}`);
      return false; // エラー検出
    }
  }
  return true; // エラーなし
}

findCreateTableComprehensive()
  .then(() => console.log('🏁 包括調査完了'))
  .catch(error => console.error('💥 調査失敗:', error));