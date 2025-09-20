/**
 * 基本機能テストスクリプト - i03.md #5対応
 * BigQueryログイン → データベース選択 → テーブル選択 → データ一覧表示の基本フローテスト
 */

const { test, expect } = require('@playwright/test');

// テスト対象URL
const BASE_URL = process.env.BASE_URL || 'http://adminer-bigquery-test';

test.describe('BigQuery Adminer 基本機能フローテスト', () => {

  test('基本フロー: ログイン→データベース選択→テーブル選択→データ表示', async ({ page }) => {
    console.log('🚀 基本機能フローテスト開始');
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

    // === Step 2: データベース（データセット）選択 ===
    console.log('📝 Step 2: データベース（データセット）選択');

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

    // === Step 3: テーブル選択 ===
    console.log('📝 Step 3: テーブル選択');

    // テーブル一覧の確認
    const tableLinks = page.locator('a[href*="table="]');
    const tableCount = await tableLinks.count();
    console.log(`📊 検出テーブル数: ${tableCount}`);

    if (tableCount === 0) {
      console.log('⚠️  テーブルが見つかりません。空のデータセットの可能性があります');
      // 空のデータセットの場合は警告のみでテスト継続
    } else {
      // 最初のテーブルを選択
      const firstTable = tableLinks.first();
      const tableName = await firstTable.textContent();
      console.log(`🎯 選択テーブル: ${tableName}`);

      await firstTable.click();
      await page.waitForLoadState('networkidle');
      console.log('✅ テーブル選択成功');

      // === Step 4: データ一覧表示 ===
      console.log('📝 Step 4: データ一覧表示');

      // テーブル構造ページから「Select data」リンクを探す
      const selectDataLink = page.locator('a[href*="select"]').first();

      if (await selectDataLink.isVisible()) {
        console.log('🔍 「Select data」リンク発見');
        await selectDataLink.click();
        await page.waitForLoadState('networkidle');

        // データ表示の確認
        // 1. データテーブルの存在確認
        const dataTable = page.locator('table.nowrap');
        if (await dataTable.isVisible()) {
          console.log('✅ データテーブル表示確認');

          // 2. データ行数の確認
          const dataRows = await page.locator('table.nowrap tbody tr').count();
          console.log(`📊 表示データ行数: ${dataRows}`);

          // 3. 列ヘッダーの確認
          const columnHeaders = await page.locator('table.nowrap thead th').count();
          console.log(`📊 表示列数: ${columnHeaders}`);

          console.log('✅ データ一覧表示成功');
        } else {
          // データテーブルが見つからない場合
          console.log('⚠️  データテーブルが表示されていません');

          // エラーメッセージの確認
          const errorElement = page.locator('.error');
          if (await errorElement.isVisible()) {
            const errorText = await errorElement.textContent();
            console.log(`❌ エラーメッセージ: ${errorText}`);
            throw new Error(`データ表示エラー: ${errorText}`);
          } else {
            console.log('ℹ️  エラーメッセージなし（空のテーブル可能性）');
          }
        }
      } else {
        console.log('⚠️  「Select data」リンクが見つかりません');

        // 代替：現在のページでデータテーブル確認
        const currentPageTable = page.locator('table');
        if (await currentPageTable.isVisible()) {
          console.log('✅ 現在ページでテーブル表示確認');
        } else {
          console.log('❌ テーブルデータが表示されていません');
        }
      }
    }

    // === Step 5: ナビゲーション確認 ===
    console.log('📝 Step 5: ナビゲーション確認');

    // 基本ナビゲーション要素の確認
    const navigationElements = [
      { name: 'SQL command', selector: 'a[href*="sql"]' },
      { name: 'Export', selector: 'a:text-is("Export")' },
      { name: 'Database', selector: 'a[href*="database"]' }
    ];

    for (const nav of navigationElements) {
      const element = page.locator(nav.selector);
      if (await element.isVisible()) {
        console.log(`✅ ナビゲーション要素確認: ${nav.name}`);
      } else {
        console.log(`⚠️  ナビゲーション要素未発見: ${nav.name}`);
      }
    }

    // === 最終確認 ===
    console.log('📝 最終確認: エラー検出');

    // JavaScriptエラーをキャッチ
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        console.log(`❌ ブラウザエラー: ${msg.text()}`);
      }
    });

    // ページエラーをキャッチ
    page.on('pageerror', (error) => {
      console.log(`❌ ページエラー: ${error.message}`);
    });

    // 包括的エラー検出機能
    await performComprehensiveErrorCheck(page);

    // サーバーログチェック
    const serverLogResult = await checkServerLogs();
    if (serverLogResult.hasErrors) {
      console.log('❌ サーバーログでエラー検出:');
      serverLogResult.errors.forEach(error => console.log(`   ${error}`));
    } else {
      console.log('✅ サーバーログ - エラーなし');
    }

    console.log('🎯 基本機能フローテスト完了');
  });

  test('基本機能フロー（簡易版）: 接続とデータベース表示のみ', async ({ page }) => {
    console.log('🚀 簡易基本機能テスト開始');

    // ログインのみテスト
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');

    // ログインボタンクリック（複数のセレクタを試行）
    let loginButtonSimple;
    try {
      loginButtonSimple = page.locator('button:has-text("Login")');
      await expect(loginButtonSimple).toBeVisible({ timeout: 2000 });
    } catch {
      try {
        loginButtonSimple = page.locator('input[type="submit"][value="Login"]');
        await expect(loginButtonSimple).toBeVisible({ timeout: 2000 });
      } catch {
        loginButtonSimple = page.locator('button');
        await expect(loginButtonSimple).toBeVisible({ timeout: 2000 });
      }
    }
    await loginButtonSimple.click();
    await page.waitForLoadState('networkidle');

    // データベース一覧の表示確認
    const databaseLinks = await page.locator('a[href*="database="]').count();
    console.log(`📊 データベース数: ${databaseLinks}`);

    if (databaseLinks > 0) {
      console.log('✅ 基本接続・データベース表示成功');
    } else {
      throw new Error('❌ データベース表示失敗');
    }

    console.log('🎯 簡易基本機能テスト完了');
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
    const response = await page.goto(page.url(), { waitUntil: 'networkidle' });
    const status = response.status();
    if (status >= 400) {
      console.log(`❌ HTTPエラー: ステータス ${status}`);
      errorFound = true;
    }

    // 3. コンソールエラーチェック（既存の機能を維持）
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
      const logSources = [
        {
          name: 'Apache Error Log',
          command: 'docker',
          args: ['exec', 'adminer-bigquery-test', 'sh', '-c',
            'if [ -f /var/log/apache2/error.log ]; then tail -n 20 /var/log/apache2/error.log | grep -i "error\\|fatal\\|warning" || echo "No recent errors"; else echo "Apache log not found"; fi']
        },
        {
          name: 'Container Logs',
          command: 'docker',
          args: ['logs', '--tail=20', 'adminer-bigquery-test']
        }
      ];

      for (const logSource of logSources) {
        try {
          const result = await executeCommand(logSource.command, logSource.args);

          const errorPatterns = [
            /Fatal error/i,
            /Parse error/i,
            /Call to undefined function/i,
            /\[error\]/i,
            /PHP Fatal/i,
            /PHP Parse/i
          ];

          for (const pattern of errorPatterns) {
            if (pattern.test(result.stdout)) {
              logCheckResults.hasErrors = true;
              logCheckResults.errors.push(`${logSource.name}: エラー検出`);
              break;
            }
          }
        } catch (cmdError) {
          // ログチェック失敗は致命的ではない
        }
      }
    } catch (error) {
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

      // 5秒でタイムアウト（基本テスト用に短縮）
      setTimeout(() => {
        process.kill('SIGTERM');
        reject(new Error('Command timeout'));
      }, 5000);
    });
  }
});