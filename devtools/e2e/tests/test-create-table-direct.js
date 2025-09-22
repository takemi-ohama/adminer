/**
 * 直接テーブル作成URLテスト - エラー検出システム確認
 * 「テーブルを作成」機能の未実装エラーを直接確認
 */

const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

async function testCreateTableDirect() {
  console.log('🚀 直接テーブル作成URL エラー検出テスト開始');
  console.log(`接続URL: ${BASE_URL}`);

  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  // エラーログを収集
  const consoleErrors = [];
  const pageErrors = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });

  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });

  try {
    // ログイン処理
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    const loginButton = page.locator('input[type="submit"][value="Login"]');
    await loginButton.click();
    await page.waitForLoadState('networkidle');
    console.log('✅ ログイン成功');

    // テーブル作成関連URLを直接テスト
    console.log('📝 Step 2: テーブル作成URL直接テスト');

    const createTableUrls = [
      // 一般的なAdminerのテーブル作成URLパターン
      `${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account&db=dataset_test&create=`,
      `${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account&create=table`,
      `${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account&table=`,
      `${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account&edit=`
    ];

    for (let i = 0; i < createTableUrls.length; i++) {
      const testUrl = createTableUrls[i];
      console.log(`\n🔄 URL ${i + 1}/${createTableUrls.length}: ${testUrl}`);

      try {
        await page.goto(testUrl);
        await page.waitForLoadState('networkidle');

        // エラー検出実行
        console.log('📊 エラー検出開始');
        const errorResult = await performComprehensiveErrorCheck(page);

        if (!errorResult) {
          console.log('❌ 未実装エラーが検出されました - これは期待される結果です');
          console.log('✅ エラー検出システムは正常に動作しています');

          // スクリーンショットを保存
          await page.screenshot({
            path: `/app/container/e2e/test-results/create_table_error_${Date.now()}.png`,
            fullPage: true
          });
          console.log('📸 エラー画面のスクリーンショットを保存しました');

        } else {
          console.log('ℹ️ このURLではエラーが検出されませんでした');
        }

      } catch (urlError) {
        console.log(`⚠️ URL ${i + 1} テストエラー: ${urlError.message}`);
      }
    }

    // コンソール・ページエラーの確認
    console.log(`\n📊 コンソールエラー数: ${consoleErrors.length}`);
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

    console.log('🎯 直接テーブル作成URL エラー検出テスト完了');

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

testCreateTableDirect()
  .then(() => console.log('🏁 テスト完了'))
  .catch(error => console.error('💥 テスト失敗:', error));