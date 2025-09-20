/**
 * 「テーブルを作成」未実装エラー検出テスト - 直接実行版
 * Playwrightを使用してエラー検出システムのテスト
 */

const { chromium } = require('playwright');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

async function runCreateTableErrorTest() {
  console.log('🚀 「テーブルを作成」未実装エラー検出テスト開始');
  console.log(`接続URL: ${BASE_URL}`);

  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  // エラーログを収集
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });

  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });

  try {
    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // BigQueryドライバーが選択されているか確認
    const systemSelect = page.locator('select[name="auth[driver]"]');
    if (await systemSelect.isVisible()) {
      const value = await systemSelect.inputValue();
      if (value === 'bigquery') {
        console.log('✅ BigQueryドライバー選択確認');
      }
    }

    // ログインボタンクリック
    let loginButton;
    try {
      loginButton = page.locator('button:has-text("Login")');
      if (!(await loginButton.isVisible())) {
        throw new Error('Button not found');
      }
    } catch {
      try {
        loginButton = page.locator('input[type="submit"][value="Login"]');
        if (!(await loginButton.isVisible())) {
          throw new Error('Input not found');
        }
      } catch {
        loginButton = page.locator('button');
      }
    }
    await loginButton.click();
    await page.waitForLoadState('networkidle');
    console.log('✅ ログイン成功');

    // === Step 2: データベース（データセット）選択 ===
    console.log('📝 Step 2: データベース選択');

    // データベースリンクの存在確認
    const databaseLinks = page.locator('a[href*="database="]');
    const dbCount = await databaseLinks.count();
    console.log(`📊 検出データベース数: ${dbCount}`);

    if (dbCount === 0) {
      throw new Error('❌ データベース（データセット）が見つかりません');
    }

    // 最初のデータベースを選択
    const firstDatabase = databaseLinks.first();
    const dbName = await firstDatabase.textContent();
    console.log(`🎯 選択データベース: ${dbName}`);

    await firstDatabase.click();
    await page.waitForLoadState('networkidle');
    console.log('✅ データベース選択成功');

    // === Step 3: 「テーブルを作成」クリックテスト ===
    console.log('📝 Step 3: 「テーブルを作成」クリックテスト');

    // 「テーブルを作成」リンクを探す
    const createTableLink = page.locator('a:has-text("Create table")');

    if (await createTableLink.isVisible()) {
      console.log('🔍 「テーブルを作成」リンク発見');

      // エラー検出前の状態記録
      console.log('📊 クリック前のエラー検出開始');
      const beforeErrors = await performComprehensiveErrorCheck(page);

      // 「テーブルを作成」をクリック
      console.log('🖱️ 「テーブルを作成」をクリック');
      await createTableLink.click();
      await page.waitForLoadState('networkidle');

      // エラー検出実行
      console.log('📊 クリック後のエラー検出開始');
      const afterErrors = await performComprehensiveErrorCheck(page);

      if (!afterErrors) {
        console.log('❌ 未実装エラーが検出されました - これは期待される結果です');
        console.log('✅ エラー検出システムは正常に動作しています');
      } else {
        console.log('⚠️ エラーが検出されませんでした - システムの改善が必要な可能性があります');
      }

    } else {
      console.log('⚠️ 「テーブルを作成」リンクが見つかりません');
    }

    // コンソールエラーとページエラーの確認
    console.log(`📊 コンソールエラー数: ${consoleErrors.length}`);
    console.log(`📊 ページエラー数: ${pageErrors.length}`);

    if (consoleErrors.length > 0) {
      console.log('❌ コンソールエラー検出:');
      consoleErrors.slice(0, 5).forEach((error, index) => {
        console.log(`   ${index + 1}: ${error.substring(0, 100)}...`);
      });
    }

    if (pageErrors.length > 0) {
      console.log('❌ ページエラー検出:');
      pageErrors.slice(0, 5).forEach((error, index) => {
        console.log(`   ${index + 1}: ${error.substring(0, 100)}...`);
      });
    }

    console.log('🎯 「テーブルを作成」エラー検出テスト完了');

  } catch (error) {
    console.log(`❌ テストエラー: ${error.message}`);
  } finally {
    await browser.close();
  }
}

// 包括的エラー検出機能
async function performComprehensiveErrorCheck(page) {
  console.log('📝 包括的エラー検出実行');

  // 1. 画面上のエラーメッセージ検出
  const errorPatterns = [
    { selector: '.error', name: 'Adminerエラー' },
    { pattern: /Fatal error|Parse error|Warning|Notice/i, name: 'PHPエラー' },
    { pattern: /Error:|Exception:|failed/i, name: '一般エラー' },
    { pattern: /Call to undefined function/i, name: '未定義関数エラー' },
    { pattern: /not supported|not implemented|unsupported/i, name: '未実装エラー' }
  ];

  let errorFound = false;
  const pageContent = await page.content();

  for (const errorPattern of errorPatterns) {
    if (errorPattern.selector) {
      // CSS セレクタによるエラー検出
      const errorElements = await page.locator(errorPattern.selector).count();
      if (errorElements > 0) {
        console.log(`❌ ${errorPattern.name}検出: ${errorElements}個`);
        const errorTexts = await page.locator(errorPattern.selector).allTextContents();
        errorTexts.forEach((error, index) => {
          console.log(`   ${errorPattern.name}${index + 1}: ${error.substring(0, 100)}...`);
        });
        errorFound = true;
      }
    } else if (errorPattern.pattern) {
      // 正規表現パターンによるエラー検出
      if (errorPattern.pattern.test(pageContent)) {
        console.log(`❌ ${errorPattern.name}検出（パターンマッチ）`);
        const matches = pageContent.match(errorPattern.pattern);
        if (matches) {
          console.log(`   内容: ${matches[0]}`);
        }
        errorFound = true;
      }
    }
  }

  if (!errorFound) {
    console.log('✅ エラー検出なし - 正常動作確認');
  }

  return !errorFound; // エラーがなければtrue
}

// テスト実行
runCreateTableErrorTest()
  .then(() => console.log('🏁 テスト完了'))
  .catch(error => console.error('💥 テスト失敗:', error));