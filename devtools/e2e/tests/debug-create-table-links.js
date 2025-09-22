/**
 * 「テーブルを作成」リンク調査スクリプト
 * 実際のページで利用可能な作成系リンクを調査
 */

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

async function debugCreateTableLinks() {
  console.log('🔍 「テーブルを作成」リンク調査開始');
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

    // データベース選択
    console.log('📝 Step 2: データベース選択');
    const databaseLinks = page.locator('a[href*="database="]');
    const dbCount = await databaseLinks.count();
    console.log(`📊 検出データベース数: ${dbCount}`);

    if (dbCount > 0) {
      const firstDatabase = databaseLinks.first();
      const dbName = await firstDatabase.textContent();
      console.log(`🎯 選択データベース: ${dbName}`);

      await firstDatabase.click();
      await page.waitForLoadState('networkidle');
      console.log('✅ データベース選択成功');

      // ページの詳細調査
      console.log('\n📋 ページ内の全リンクテキストを調査:');

      const allLinks = await page.locator('a').all();
      console.log(`総リンク数: ${allLinks.length}`);

      for (let i = 0; i < Math.min(allLinks.length, 30); i++) {
        try {
          const linkText = await allLinks[i].textContent();
          const href = await allLinks[i].getAttribute('href');
          if (linkText && linkText.trim()) {
            console.log(`  ${i+1}: "${linkText.trim()}" -> ${href || 'no href'}`);
          }
        } catch (e) {
          // リンク取得失敗は無視
        }
      }

      console.log('\n🔍 作成関連のリンクを検索:');

      // 作成関連のパターンを試行
      const createPatterns = [
        { pattern: 'a:has-text("Create table")', desc: '英語：Create table' },
        { pattern: 'a:has-text("テーブルを作成")', desc: '日本語：テーブルを作成' },
        { pattern: 'a:has-text("Create")', desc: '英語：Create' },
        { pattern: 'a:has-text("作成")', desc: '日本語：作成' },
        { pattern: 'a[href*="create"]', desc: 'URL：create含有' },
        { pattern: 'a[href*="table"][href*="create"]', desc: 'URL：table+create' },
        { pattern: 'a[href*="Create"]', desc: 'URL：Create（大文字）' },
        { pattern: 'a[href*="new"]', desc: 'URL：new含有' },
        { pattern: 'a:has-text("New")', desc: '英語：New' }
      ];

      for (const { pattern, desc } of createPatterns) {
        try {
          const links = await page.locator(pattern).count();
          if (links > 0) {
            console.log(`✅ ${desc}: ${links}個発見`);

            // 最初のマッチするリンクの詳細を表示
            for (let i = 0; i < Math.min(links, 3); i++) {
              const link = page.locator(pattern).nth(i);
              const text = await link.textContent();
              const href = await link.getAttribute('href');
              console.log(`   ${i+1}: "${text?.trim() || ''}" -> ${href || 'no href'}`);
            }
          } else {
            console.log(`❌ ${desc}: 見つからず`);
          }
        } catch (e) {
          console.log(`⚠️  ${desc}: 検索エラー - ${e.message}`);
        }
      }

      // ページのHTMLソースから作成関連テキストを検索
      console.log('\n🔍 HTML内テキスト検索:');
      const pageContent = await page.content();
      const searchTerms = ['Create', 'create', '作成', 'テーブル', 'table', 'New', 'new'];

      for (const term of searchTerms) {
        const regex = new RegExp(term, 'gi');
        const matches = pageContent.match(regex);
        if (matches && matches.length > 0) {
          console.log(`✅ "${term}": ${matches.length}箇所で発見`);
        }
      }
    }

  } catch (error) {
    console.log(`❌ エラー: ${error.message}`);
  } finally {
    await browser.close();
  }
}

debugCreateTableLinks()
  .then(() => console.log('🏁 調査完了'))
  .catch(error => console.error('💥 調査失敗:', error));