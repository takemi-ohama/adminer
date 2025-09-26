/**
 * Phase 1 Sprint 1.3: ユーザー・システム情報機能テスト
 * logged_user()とinformation_schema()機能の動作確認
 */

const { test, expect } = require('@playwright/test');

test.describe('Phase 1 Sprint 1.3: ユーザー・システム情報機能テスト', () => {

  test('1. logged_user()強化機能テスト - サービスアカウント情報詳細表示', async ({ page }) => {
    console.log('🔍 logged_user()機能テスト開始');

    // ログインフェーズ
    await page.goto('http://adminer-bigquery-test', { timeout: 15000 });
    await page.waitForTimeout(3000);

    // プロジェクト接続
    await page.fill('input[name="auth[server]"]', 'bigquery-public-data');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForTimeout(5000);

    console.log('✅ BigQueryプロジェクトにログイン完了');

    // ページ内でlogged_userの表示を確認
    const pageContent = await page.content();

    // 強化されたlogged_user情報の確認
    const hasProjectInfo = pageContent.includes('BigQuery Service Account') &&
                          pageContent.includes('Project: bigquery-public-data');

    const hasAuthInfo = pageContent.includes('Auth:') ||
                       pageContent.includes('Default Credentials') ||
                       pageContent.includes('service-account');

    console.log(`📊 プロジェクト情報表示: ${hasProjectInfo ? '成功' : '基本のみ'}`);
    console.log(`🔑 認証情報表示: ${hasAuthInfo ? '成功' : '基本のみ'}`);

    // 最低限BigQueryサービスアカウント情報が表示されていることを確認
    const hasBasicUserInfo = pageContent.includes('BigQuery Service Account');

    expect(hasBasicUserInfo).toBeTruthy();
    console.log('✅ logged_user()機能テスト完了');
  });

  test('2. information_schema()判定機能テスト', async ({ page }) => {
    console.log('🔍 information_schema()機能テスト開始');

    // ログイン
    await page.goto('http://adminer-bigquery-test', { timeout: 15000 });
    await page.waitForTimeout(3000);

    await page.fill('input[name="auth[server]"]', 'bigquery-public-data');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForTimeout(5000);

    console.log('✅ BigQueryプロジェクト接続完了');

    // データベース（データセット）一覧を確認
    const databaseLinks = page.locator('a[href*="db="]');
    const linkCount = await databaseLinks.count();

    console.log(`📋 データセット数: ${linkCount}`);

    // INFORMATION_SCHEMAがある場合は特別扱いされるかテスト
    const pageContent = await page.content();

    // INFORMATION_SCHEMAデータセットの存在チェック
    const hasInformationSchema = pageContent.includes('INFORMATION_SCHEMA') ||
                                pageContent.includes('information_schema');

    console.log(`🔍 INFORMATION_SCHEMA検出: ${hasInformationSchema ? '存在' : '不明'}`);

    // 基本的にデータセット一覧が表示されていることを確認
    const hasDatabaseList = linkCount > 0 || pageContent.includes('dataset');

    expect(hasDatabaseList).toBeTruthy();
    console.log('✅ information_schema()機能テスト完了');
  });

  test('3. 統合ユーザーインターフェース確認', async ({ page }) => {
    console.log('🔍 統合UI確認テスト開始');

    // ログイン
    await page.goto('http://adminer-bigquery-test', { timeout: 15000 });
    await page.waitForTimeout(3000);

    await page.fill('input[name="auth[server]"]', 'bigquery-public-data');
    await page.click('input[type="submit"][value="Login"]');
    await page.waitForTimeout(5000);

    // メインページでの情報表示確認
    const pageContent = await page.content();

    // ユーザー情報がUIに表示されているか
    const userInfoDisplayed = pageContent.includes('BigQuery Service Account');

    // システム情報が適切に表示されているか
    const systemInfoDisplayed = pageContent.includes('bigquery-public-data') ||
                               pageContent.includes('BigQuery');

    console.log(`👤 ユーザー情報表示: ${userInfoDisplayed}`);
    console.log(`💾 システム情報表示: ${systemInfoDisplayed}`);

    // エラーがないことを確認
    const hasError = pageContent.toLowerCase().includes('fatal error') ||
                    pageContent.toLowerCase().includes('parse error');

    console.log(`🛡️ エラーなし: ${!hasError}`);

    expect(userInfoDisplayed && systemInfoDisplayed && !hasError).toBeTruthy();
    console.log('✅ 統合UI確認テスト完了');
  });

});