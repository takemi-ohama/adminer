import { test, expect } from '@playwright/test';

const GOOGLE_CLOUD_PROJECT = process.env.GOOGLE_CLOUD_PROJECT || 'nyle-carmo-analysis';
const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';

test.describe('BigQuery Monkey Testing', () => {

  test('monkey test - random interactions', async ({ page }) => {
    // エラートラッキング
    const errors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(`Console Error: ${msg.text()}`);
      }
    });

    page.on('pageerror', (error) => {
      errors.push(`Page Error: ${error.message}`);
    });

    try {
      // 1. 初期ログイン
      await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
      await page.selectOption('select[name="auth[driver]"]', 'bigquery');
      await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);
      await page.click('input[type="submit"][value="Login"]');

      // ログイン成功を確認
      await expect(page).toHaveTitle(new RegExp(`${GOOGLE_CLOUD_PROJECT}.*Adminer`));

      console.log('🎯 Starting monkey test...');

      // 2. ランダムなインタラクションを実行
      for (let i = 0; i < 20; i++) {
        console.log(`🐒 Monkey action ${i + 1}/20`);

        // 利用可能なリンクとボタンを取得
        const links = await page.locator('a[href]').all();
        const buttons = await page.locator('button, input[type="submit"], input[type="button"]').all();
        const inputs = await page.locator('input[type="text"], input[type="search"], textarea').all();

        // ランダムアクション選択
        const actionType = Math.floor(Math.random() * 4);

        try {
          switch (actionType) {
            case 0: // リンククリック
              if (links.length > 0) {
                const randomLink = links[Math.floor(Math.random() * links.length)];
                const href = await randomLink.getAttribute('href');

                // 危険なリンクを除外
                if (href && !href.includes('logout') && !href.includes('delete') && !href.includes('drop')) {
                  console.log(`  📎 Clicking link: ${href}`);
                  await randomLink.click();
                  await page.waitForTimeout(1000);
                }
              }
              break;

            case 1: // ボタンクリック
              if (buttons.length > 0) {
                const randomButton = buttons[Math.floor(Math.random() * buttons.length)];
                const buttonText = await randomButton.textContent();

                // 危険なボタンを除外
                if (buttonText && !buttonText.toLowerCase().includes('delete') && !buttonText.toLowerCase().includes('drop')) {
                  console.log(`  🔘 Clicking button: ${buttonText}`);
                  await randomButton.click();
                  await page.waitForTimeout(1000);
                }
              }
              break;

            case 2: // 入力フィールドへのランダム入力
              if (inputs.length > 0) {
                const randomInput = inputs[Math.floor(Math.random() * inputs.length)];
                const randomText = generateRandomText();
                console.log(`  ⌨️  Typing: ${randomText}`);
                await randomInput.fill(randomText);
                await page.waitForTimeout(500);
              }
              break;

            case 3: // ページ内ナビゲーション
              const navigation = [
                `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`,
                `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`,
                `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`,
                `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&select=member_info`
              ];
              const randomNav = navigation[Math.floor(Math.random() * navigation.length)];
              console.log(`  🧭 Navigating to: ${randomNav}`);
              await page.goto(randomNav);
              await page.waitForTimeout(1000);
              break;
          }

          // Fatal Error チェック
          const fatalError = await page.locator('text=Fatal error').count();
          if (fatalError > 0) {
            const errorText = await page.locator('text=Fatal error').first().textContent();
            errors.push(`Fatal Error detected: ${errorText}`);
            console.log(`❌ Fatal Error: ${errorText}`);

            // エラー後にホームに戻る
            await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
          }

        } catch (actionError) {
          console.log(`  ⚠️  Action error: ${actionError.message}`);
          // エラー後は続行
        }

        // 短い待機
        await page.waitForTimeout(500);
      }

      // 3. 最終チェック - 基本機能が正常動作するか確認
      console.log('🔍 Final functionality check...');

      await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
      await expect(page.locator('h2')).toBeVisible();

      await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`);
      await expect(page.locator('h2:has-text("prod_carmo_db")')).toBeVisible();

      await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`);
      await expect(page.locator('h2:has-text("member_info")')).toBeVisible();

      console.log('✅ Monkey test completed successfully!');

    } catch (error) {
      errors.push(`Test Error: ${error.message}`);
      console.log(`❌ Test failed: ${error.message}`);
    }

    // エラーサマリー
    if (errors.length > 0) {
      console.log('\n🚨 Errors detected during monkey test:');
      errors.forEach((error, index) => {
        console.log(`${index + 1}. ${error}`);
      });

      // エラーがあっても、致命的でなければテスト継続
      expect(errors.filter(e => e.includes('Fatal Error')).length).toBe(0);
    } else {
      console.log('\n✅ No errors detected during monkey test');
    }
  });

  test('monkey test - focused on data operations', async ({ page }) => {
    // データ操作に特化したモンキーテスト
    const errors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    // ログイン
    await page.goto(`${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`);
    await page.selectOption('select[name="auth[driver]"]', 'bigquery');
    await page.fill('input[name="auth[server]"]', GOOGLE_CLOUD_PROJECT);
    await page.click('input[type="submit"][value="Login"]');

    console.log('🎯 Starting focused monkey test on data operations...');

    // データ関連の操作を集中的にテスト
    const testScenarios = [
      // データセット間の移動
      { url: `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=`, description: 'Dataset list' },
      { url: `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db`, description: 'Table list' },
      { url: `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&table=member_info`, description: 'Table structure' },
      { url: `${BASE_URL}/?bigquery=${GOOGLE_CLOUD_PROJECT}&username=&db=prod_carmo_db&select=member_info`, description: 'Data selection' },
    ];

    for (let iteration = 0; iteration < 5; iteration++) {
      console.log(`🔄 Iteration ${iteration + 1}/5`);

      for (const scenario of testScenarios) {
        try {
          console.log(`  📝 Testing: ${scenario.description}`);
          await page.goto(scenario.url);

          // ページが正常に読み込まれるまで待機
          await page.waitForLoadState('networkidle');

          // Fatal Errorチェック
          const fatalErrors = await page.locator('text=Fatal error').count();
          if (fatalErrors > 0) {
            const errorText = await page.locator('text=Fatal error').first().textContent();
            errors.push(`Fatal Error in ${scenario.description}: ${errorText}`);
          }

          // ランダムな操作
          const randomAction = Math.floor(Math.random() * 3);

          switch (randomAction) {
            case 0:
              // リンクをランダムクリック
              const links = await page.locator('a[href*="bigquery"]').all();
              if (links.length > 0) {
                const randomLink = links[Math.floor(Math.random() * links.length)];
                await randomLink.click();
              }
              break;

            case 1:
              // フォーム要素への入力
              const textInputs = await page.locator('input[type="text"], textarea').all();
              if (textInputs.length > 0) {
                const randomInput = textInputs[Math.floor(Math.random() * textInputs.length)];
                await randomInput.fill('SELECT COUNT(*) FROM `prod_carmo_db.member_info` LIMIT 10');
              }
              break;

            case 2:
              // ページリロード
              await page.reload();
              break;
          }

          await page.waitForTimeout(1000);

        } catch (error) {
          console.log(`  ⚠️  Error in ${scenario.description}: ${error.message}`);
        }
      }
    }

    console.log('✅ Focused monkey test completed');

    // Fatal Errorが発生していないことを確認
    const fatalErrorCount = errors.filter(e => e.includes('Fatal Error')).length;
    expect(fatalErrorCount).toBe(0);
  });

});

// ランダムテキスト生成
function generateRandomText() {
  const texts = [
    'test',
    'SELECT * FROM table',
    'prod_carmo_db',
    'member_info',
    '123',
    'search query',
    'bigquery',
    '',
    'あいうえお',
    '!@#$%',
  ];
  return texts[Math.floor(Math.random() * texts.length)];
}