# BigQuery認証問題の詳細分析

## 現状の課題と解決策

### 1. 認証フローの問題

#### Adminer標準認証フロー
- `Adminer/login($login, $password)`: パスワードが空の場合はエラーを返す
- `Adminer/loginForm()`: System/Server/Username/Password/Databaseフィールドを表示
- 認証情報は`auth[driver]`, `auth[server]`, `auth[username]`, `auth[password]`, `auth[db]`として送信

#### BigQueryの認証要件
- サービスアカウントJSONファイルによる認証
- プロジェクトIDのみが必要（Username/Password不要）
- GOOGLE_APPLICATION_CREDENTIALS環境変数でファイルパス指定

### 2. 既存の認証プラグインパターン

#### AdminerLoginPasswordLess
```php
function credentials() {
    return array(SERVER, $_GET["username"], ""); // パスワードを空にする
}
function login($login, $password) {
    if ($password != "") return true; // パスワードがある場合のみ許可
}
```

#### AdminerLoginServers
```php
function loginFormField($name, $heading, $value) {
    if ($name == 'driver') return ''; // ドライバー選択を隠す
    if ($name == 'server') return $heading . html_select(...); // サーバー選択
}
```

### 3. 現在のBigQuery実装の問題

#### container/tests/plugins/login-bigquery.php
- フィールドのカスタマイズは実装済み
- credentials()メソッドでGOOGLE_APPLICATION_CREDENTIALS設定
- login()メソッドでJSONファイル検証実装済み

#### 問題点
1. Adminer本体の`login()`が空パスワードを拒否
2. BigQueryドライバーの`connect()`でのエラー処理不十分
3. 認証失敗時のユーザーフレンドリーなエラー表示不足

### 4. テスト環境の構成

#### container/tests/構造
- Docker Compose: adminer-bigquery-testコンテナ
- Dockerfile: PHP8.3-apache + Composer + BigQuery依存関係
- index.php: カスタムエントリーポイント（デバッグ機能付き）
- プラグイン統合: login-bigquery.phpで認証カスタマイズ
- 環境変数: GOOGLE_APPLICATION_CREDENTIALS, BIGQUERY_PROJECT_ID

## 解決策

### 1. 認証プラグインの修正
login-bigquery.phpで以下を実装:
```php
function login($login, $password) {
    // ファイル存在確認は既に実装済み
    return true; // 必ずtrueを返してAdminerの標準チェックをバイパス
}

function credentials() {
    // 環境変数設定は実装済み
    return array($server, '', ''); // username, passwordは空文字列
}
```

### 2. BigQueryドライバーの修正
plugins/drivers/bigquery.php:
- connect()メソッドでより詳細なエラーハンドリング
- 認証エラーの分類と適切なメッセージ表示
- テスト用の簡易接続確認機能

### 3. テスト環境での動作確認
1. container/tests/compose.ymlでコンテナ起動
2. http://adminer-bigquery-test:80でアクセス
3. プロジェクトID入力、認証ファイルパス入力
4. 正常な接続とエラー処理の確認

### 4. 実装優先度
1. **高**: login-bigquery.phpの認証ロジック修正
2. **中**: BigQueryドライバーのエラーハンドリング改善  
3. **低**: UI/UXの追加改良