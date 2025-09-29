const { test, expect } = require('@playwright/test');

test('BigQuery Adminer UI Structure Investigation', async ({ page }) => {
  console.log('=== 1. 初期ログインページへアクセス ===');
  await page.goto('http://adminer-bigquery-test');
  await page.waitForLoadState('networkidle');

  // 初期ページのスクリーンショット
  await page.screenshot({ path: '/tmp/login_page.png' });
  console.log('ログインページのスクリーンショット保存: /tmp/login_page.png');

  // ページタイトルとURL確認
  const title = await page.title();
  const url = page.url();
  console.log(`ページタイトル: ${title}`);
  console.log(`現在のURL: ${url}`);

  console.log('\n=== 2. ログインボタンをクリック ===');
  // ログインボタンを探してクリック
  const loginButton = await page.locator('input[type="submit"]').first();
  if (await loginButton.count() > 0) {
    console.log('ログインボタンが見つかりました');
    await loginButton.click();
    // BigQuery認証処理のため3秒待機
    await page.waitForTimeout(3000);
    await page.waitForLoadState('networkidle');
  } else {
    console.log('ログインボタンが見つかりません');
  }

  // 認証後のページスクリーンショット
  await page.screenshot({ path: '/tmp/authenticated_page.png' });
  console.log('認証後ページのスクリーンショット保存: /tmp/authenticated_page.png');

  // 新しいページタイトルとURL確認
  const newTitle = await page.title();
  const newUrl = page.url();
  console.log(`認証後ページタイトル: ${newTitle}`);
  console.log(`認証後URL: ${newUrl}`);

  console.log('\n=== 3. データセット/データベース要素の調査 ===');

  // データベース関連の要素を探す
  const databaseElements = await page.locator('a[href*="database"], a[href*="db"], a[href*="schema"]').all();
  console.log(`データベース関連リンク数: ${databaseElements.length}`);

  for (let i = 0; i < Math.min(databaseElements.length, 5); i++) {
    const elem = databaseElements[i];
    const href = await elem.getAttribute('href');
    const text = await elem.innerText();
    console.log(`  データベースリンク ${i+1}: href='${href}', text='${text}'`);
  }

  // ID "databases" を持つ要素を探す
  const databasesElem = await page.locator('#databases').first();
  if (await databasesElem.count() > 0) {
    console.log('要素 #databases が見つかりました');
    const tagName = await databasesElem.evaluate(el => el.tagName);
    const innerHTML = await databasesElem.innerHTML();
    console.log(`  タグ: ${tagName}`);
    console.log(`  内容: ${innerHTML.substring(0, 200)}...`);
  } else {
    console.log('要素 #databases は見つかりません');
  }

  // データセット関連の要素を探す（BigQuery特有）
  const datasetElements = await page.locator('a[href*="dataset"], *[class*="dataset"], *[id*="dataset"]').all();
  console.log(`データセット関連要素数: ${datasetElements.length}`);

  for (let i = 0; i < Math.min(datasetElements.length, 3); i++) {
    const elem = datasetElements[i];
    const tagName = await elem.evaluate(el => el.tagName);
    const className = await elem.getAttribute('class') || '';
    const idName = await elem.getAttribute('id') || '';
    const href = await elem.getAttribute('href') || '';
    const text = await elem.innerText();
    console.log(`  データセット要素 ${i+1}: <${tagName}> class='${className}' id='${idName}' href='${href}' text='${text.substring(0, 50)}'`);
  }

  console.log('\n=== 4. テーブル要素の調査 ===');

  // ID "tables" を持つ要素を探す
  const tablesElem = await page.locator('#tables').first();
  if (await tablesElem.count() > 0) {
    console.log('要素 #tables が見つかりました');
    const tagName = await tablesElem.evaluate(el => el.tagName);
    const innerHTML = await tablesElem.innerHTML();
    console.log(`  タグ: ${tagName}`);
    console.log(`  内容: ${innerHTML.substring(0, 200)}...`);
  } else {
    console.log('要素 #tables は見つかりません');
  }

  // テーブル関連の要素を探す
  const tableElements = await page.locator('a[href*="table"], *[class*="table"], table.data').all();
  console.log(`テーブル関連要素数: ${tableElements.length}`);

  for (let i = 0; i < Math.min(tableElements.length, 5); i++) {
    const elem = tableElements[i];
    const tagName = await elem.evaluate(el => el.tagName);
    const className = await elem.getAttribute('class') || '';
    const idName = await elem.getAttribute('id') || '';
    const href = await elem.getAttribute('href') || '';
    const text = await elem.innerText();
    console.log(`  テーブル要素 ${i+1}: <${tagName}> class='${className}' id='${idName}' href='${href}' text='${text.substring(0, 50)}'`);
  }

  // table.data要素を特別に調査
  const dataTable = await page.locator('table.data').first();
  if (await dataTable.count() > 0) {
    console.log('table.data要素が見つかりました');
    const rows = await dataTable.locator('tr').all();
    console.log(`  行数: ${rows.length}`);
    if (rows.length > 0) {
      const firstRowHtml = await rows[0].innerHTML();
      console.log(`  最初の行内容: ${firstRowHtml.substring(0, 100)}...`);
    }
  } else {
    console.log('table.data要素は見つかりません');
  }

  console.log('\n=== 5. ページ全体の主要構造分析 ===');

  // 主要なナビゲーション要素
  const navElements = await page.locator('nav, .menu, #menu, .navigation').all();
  console.log(`ナビゲーション要素数: ${navElements.length}`);

  // フォーム要素
  const forms = await page.locator('form').all();
  console.log(`フォーム数: ${forms.length}`);

  // リンク要素（href属性付き）
  const links = await page.locator('a[href]').all();
  console.log(`リンク数: ${links.length}`);

  // すべてのID付き要素を取得
  const idElements = await page.locator('*[id]').all();
  console.log(`ID付き要素数: ${idElements.length}`);
  console.log('主要なID要素:');

  for (let i = 0; i < Math.min(idElements.length, 10); i++) {
    const elem = idElements[i];
    const elemId = await elem.getAttribute('id');
    const tagName = await elem.evaluate(el => el.tagName);
    console.log(`  #${elemId} (${tagName})`);
  }

  console.log('\n=== 6. HTMLページソースの調査 ===');

  // ページ全体のHTMLを取得してキーワード検索
  const pageContent = await page.content();

  // データベース/データセット関連のテキストを検索
  const databaseMatches = pageContent.match(/database[s]?|dataset[s]?/gi) || [];
  console.log(`ページ内の database/dataset キーワード出現回数: ${databaseMatches.length}`);

  // テーブル関連のテキストを検索
  const tableMatches = pageContent.match(/table[s]?/gi) || [];
  console.log(`ページ内の table キーワード出現回数: ${tableMatches.length}`);

  // Adminer特有の要素を検索
  const adminerMatches = pageContent.match(/adminer|structure|select|schema/gi) || [];
  console.log(`ページ内の Adminer関連キーワード出現回数: ${adminerMatches.length}`);

  console.log('\n=== 7. 実際のHTML構造の出力 ===');

  // bodyタグ内の主要構造を取得
  const bodyElement = await page.locator('body').first();
  if (await bodyElement.count() > 0) {
    const bodyHtml = await bodyElement.innerHTML();
    console.log('\n--- Body内のHTML構造 (最初の1000文字) ---');
    console.log(bodyHtml.substring(0, 1000));
    console.log('--- End of HTML structure ---\n');
  }

  console.log('\n=== UI構造調査完了 ===');
});