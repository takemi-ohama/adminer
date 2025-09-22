/**
 * エラー検出システム専用テスト - idf_escape()エラー再現テスト
 * Fatal errorやPHPエラーを検出する機能をテスト
 */

const { test, expect } = require('@playwright/test');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('エラー検出システム テスト', () => {

  test('Fatal errorやPHPエラーの検出テスト', async ({ page }) => {
    console.log('🚀 エラー検出システムテスト開始');
    console.log(`接続URL: ${BASE_URL}`);

    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // BigQueryドライバーが選択されているか確認
    const systemSelect = page.locator('select[name="auth[driver]"]');
    if (await systemSelect.isVisible()) {
      await expect(systemSelect).toHaveValue('bigquery');
      console.log('✅ BigQueryドライバー選択確認');
    }

    // ログインボタンクリック（複数のセレクタを試行）
    let loginButton;
    try {
      loginButton = page.locator('button:has-text("Login")');
      await expect(loginButton).toBeVisible({ timeout: 2000 });
    } catch {
      try {
        loginButton = page.locator('input[type="submit"][value="Login"]');
        await expect(loginButton).toBeVisible({ timeout: 2000 });
      } catch {
        loginButton = page.locator('button');
        await expect(loginButton).toBeVisible({ timeout: 2000 });
      }
    }
    await loginButton.click();
    await page.waitForLoadState('networkidle');

    // ログイン成功確認（Adminerタイトル確認）
    await expect(page).toHaveTitle(/Adminer/);
    console.log('✅ ログイン成功');

    // === Step 2: エラーが発生するURLにアクセス ===
    console.log('📝 Step 2: エラー発生URLテスト');

    // 問題のあったURL（テーブルデータ表示）にアクセス
    const errorUrl = `${BASE_URL}/?bigquery=adminer-test-472623&username=bigquery-service-account&db=dataset_test&select=board_kpi`;
    console.log(`🎯 テストURL: ${errorUrl}`);

    await page.goto(errorUrl);
    await page.waitForLoadState('networkidle');

    // === Step 3: 包括的エラー検出 ===
    console.log('📝 Step 3: 包括的エラー検出実行');
    const errorCheckResult = await performComprehensiveErrorCheck(page);

    if (!errorCheckResult) {
      console.log('⚠️  エラーが検出されました - これは期待される結果です（テスト用）');
    } else {
      console.log('✅ エラー検出なし - 修正が成功している可能性があります');
    }

    console.log('🎯 エラー検出システムテスト完了');
  });

  test('「テーブルを作成」未実装エラー検出テスト', async ({ page }) => {
    console.log('🚀 「テーブルを作成」未実装エラー検出テスト開始');
    console.log(`接続URL: ${BASE_URL}`);

    // === Step 1: ログイン処理 ===
    console.log('📝 Step 1: BigQueryログイン処理');
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // BigQueryドライバーが選択されているか確認
    const systemSelect = page.locator('select[name="auth[driver]"]');
    if (await systemSelect.isVisible()) {
      await expect(systemSelect).toHaveValue('bigquery');
      console.log('✅ BigQueryドライバー選択確認');
    }

    // ログインボタンクリック
    let loginButton;
    try {
      loginButton = page.locator('button:has-text("Login")');
      await expect(loginButton).toBeVisible({ timeout: 2000 });
    } catch {
      try {
        loginButton = page.locator('input[type="submit"][value="Login"]');
        await expect(loginButton).toBeVisible({ timeout: 2000 });
      } catch {
        loginButton = page.locator('button');
        await expect(loginButton).toBeVisible({ timeout: 2000 });
      }
    }
    await loginButton.click();
    await page.waitForLoadState('networkidle');

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
      const beforeClickErrors = await performComprehensiveErrorCheck(page);

      // 「テーブルを作成」をクリック
      console.log('🖱️ 「テーブルを作成」をクリック');
      await createTableLink.click();
      await page.waitForLoadState('networkidle');

      // エラー検出実行
      console.log('📊 クリック後のエラー検出開始');
      const afterClickErrors = await performComprehensiveErrorCheck(page);

      if (!afterClickErrors) {
        console.log('❌ 未実装エラーが検出されました - これは期待される結果です');

        // サーバーログもチェック
        const serverLogResult = await checkServerLogs();
        if (serverLogResult.hasErrors) {
          console.log('❌ サーバーログでもエラー検出:');
          serverLogResult.errors.forEach((error, index) => {
            console.log(`   ${index + 1}: ${error}`);
          });
        }

        console.log('✅ エラー検出システムは正常に動作しています');
      } else {
        console.log('⚠️ エラーが検出されませんでした - システムの改善が必要な可能性があります');
      }

    } else {
      console.log('⚠️ 「テーブルを作成」リンクが見つかりません');
      // 代替として、他の作成系リンクを探す
      const alternativeLinks = [
        'Create table',
        'テーブルを作成',
        'a[href*="create"]',
        'a:has-text("作成")'
      ];

      for (const linkSelector of alternativeLinks) {
        const altLink = page.locator(linkSelector);
        if (await altLink.isVisible()) {
          console.log(`🔍 代替リンク発見: ${linkSelector}`);
          await altLink.click();
          await page.waitForLoadState('networkidle');

          const errorResult = await performComprehensiveErrorCheck(page);
          if (!errorResult) {
            console.log('❌ 代替リンクでエラー検出成功');
          }
          break;
        }
      }
    }

    console.log('🎯 「テーブルを作成」エラー検出テスト完了');
  });

  test('サーバーログ監視テスト', async ({ page }) => {
    console.log('🚀 サーバーログ監視テスト開始');

    // === ログイン処理 ===
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // ログイン
    try {
      const loginButton = page.locator('input[type="submit"][value="Login"]');
      await expect(loginButton).toBeVisible({ timeout: 2000 });
      await loginButton.click();
    } catch {
      const loginButtonAlt = page.locator('button:has-text("Login")');
      await loginButtonAlt.click();
    }
    await page.waitForLoadState('networkidle');

    // === サーバーログ確認機能テスト ===
    console.log('📝 サーバーログ確認テスト');

    const serverLogResult = await checkServerLogs();

    if (serverLogResult.hasErrors) {
      console.log('❌ サーバーログでエラーが検出されました');
      serverLogResult.errors.forEach((error, index) => {
        console.log(`   サーバーエラー${index + 1}: ${error}`);
      });
    } else {
      console.log('✅ サーバーログ - エラーなし');
    }

    console.log('🎯 サーバーログ監視テスト完了');
  });

  // 包括的エラー検出機能（共通関数）
  async function performComprehensiveErrorCheck(page) {
    console.log('📝 包括的エラー検出実行');

    // 1. 画面上のエラーメッセージ検出
    const errorPatterns = [
      { selector: '.error', name: 'Adminerエラー' },
      { pattern: /Fatal error|Parse error|Warning|Notice/i, name: 'PHPエラー' },
      { pattern: /Error:|Exception:|failed/i, name: '一般エラー' },
      { pattern: /Call to undefined function/i, name: '未定義関数エラー' },
      { pattern: /idf_escape/i, name: 'idf_escape関数エラー' }
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

    // 2. HTTPステータスコードチェック
    const currentUrl = page.url();
    const response = await page.goto(currentUrl, { waitUntil: 'networkidle' });
    const status = response.status();
    if (status >= 400) {
      console.log(`❌ HTTPエラー: ステータス ${status}`);
      errorFound = true;
    }

    // 3. コンソールエラーチェック
    let consoleErrors = 0;
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        console.log(`❌ ブラウザコンソールエラー: ${msg.text()}`);
        consoleErrors++;
        errorFound = true;
      }
    });

    // 4. 結果サマリー
    if (errorFound) {
      console.log('⚠️  エラーが検出されました');

      // エラー詳細情報をスクリーンショットに保存
      await page.screenshot({
        path: `./test-results/error_detection_${Date.now()}.png`,
        fullPage: true
      });

    } else {
      console.log('✅ エラー検出なし - 正常動作確認');
    }

    return !errorFound; // エラーがなければtrue
  }

  // サーバーログチェック機能
  async function checkServerLogs() {
    console.log('📊 サーバーログ監視実行');

    const { spawn } = require('child_process');
    const logCheckResults = {
      hasErrors: false,
      errors: []
    };

    try {
      // Docker execを使用してWebコンテナのログを確認
      // Apacheエラーログとlog系のチェック
      const logSources = [
        {
          name: 'Apache Error Log',
          command: 'docker',
          args: ['exec', 'adminer-bigquery-test', 'sh', '-c',
            'if [ -f /var/log/apache2/error.log ]; then tail -n 50 /var/log/apache2/error.log | grep -i "error\\|fatal\\|warning" || echo "No recent errors"; else echo "Apache log not found"; fi']
        },
        {
          name: 'PHP Error Log',
          command: 'docker',
          args: ['exec', 'adminer-bigquery-test', 'sh', '-c',
            'if [ -f /var/log/php_errors.log ]; then tail -n 50 /var/log/php_errors.log | grep -i "error\\|fatal\\|warning" || echo "No recent errors"; else echo "PHP log not found"; fi']
        },
        {
          name: 'Container Logs',
          command: 'docker',
          args: ['logs', '--tail=50', 'adminer-bigquery-test']
        }
      ];

      for (const logSource of logSources) {
        console.log(`   - ${logSource.name}チェック中...`);

        try {
          const result = await executeCommand(logSource.command, logSource.args);

          // エラーパターンをチェック
          const errorPatterns = [
            /Fatal error/i,
            /Parse error/i,
            /Call to undefined function/i,
            /\[error\]/i,
            /PHP Fatal/i,
            /PHP Parse/i,
            /segmentation fault/i,
            /core dumped/i
          ];

          let foundErrors = false;
          for (const pattern of errorPatterns) {
            if (pattern.test(result.stdout)) {
              foundErrors = true;
              const matches = result.stdout.match(new RegExp(pattern.source, 'gi'));
              matches?.forEach(match => {
                logCheckResults.errors.push(`${logSource.name}: ${match}`);
              });
            }
          }

          if (foundErrors) {
            logCheckResults.hasErrors = true;
            console.log(`     ❌ ${logSource.name}でエラー検出`);
          } else {
            console.log(`     ✅ ${logSource.name} - エラーなし`);
          }

        } catch (cmdError) {
          console.log(`     ⚠️ ${logSource.name}チェック失敗: ${cmdError.message}`);
          // ログチェック失敗は致命的ではないため、テスト継続
        }
      }

    } catch (error) {
      console.log(`⚠️ サーバーログチェックでエラー: ${error.message}`);
      // ログチェック自体の失敗はテストを失敗させない
    }

    return logCheckResults;
  }

  // コマンド実行ヘルパー関数
  function executeCommand(command, args) {
    return new Promise((resolve, reject) => {
      const { spawn } = require('child_process');
      const process = spawn(command, args);

      let stdout = '';
      let stderr = '';

      process.stdout.on('data', (data) => {
        stdout += data.toString();
      });

      process.stderr.on('data', (data) => {
        stderr += data.toString();
      });

      process.on('close', (code) => {
        resolve({
          code: code,
          stdout: stdout,
          stderr: stderr
        });
      });

      process.on('error', (error) => {
        reject(error);
      });

      // 10秒でタイムアウト
      setTimeout(() => {
        process.kill('SIGTERM');
        reject(new Error('Command timeout'));
      }, 10000);
    });
  }

});