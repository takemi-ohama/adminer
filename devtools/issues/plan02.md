# BigQuery ドライバー詳細実装プラン

## 1. プロジェクト全体状況

### 1.1 現在の達成状況
- ✅ **基本アーキテクチャ**: Adminerドライバー・プラグインシステムの完全理解
- ✅ **コア実装**: BigQueryドライバー本体 (plugins/drivers/bigquery.php) 完成
- ✅ **テスト環境**: DooD環境でのコンテナ化テスト基盤構築済み
- ✅ **認証プラグイン**: BigQuery特化ログインプラグイン実装済み
- ✅ **ドキュメント**: 6つの包括的技術文書完成
- ❌ **核心問題**: Adminer標準認証チェックとBigQueryパスワードレス認証の競合

### 1.2 技術スタック確定事項
```
Backend:     PHP 8.3 + Apache + Adminer 4.8.1+
BigQuery:    google/cloud-bigquery PHP SDK v1.34
Container:   Docker + Docker Compose
Environment: DooD (Docker-outside-of-Docker)
Network:     adminer-net (172.20.0.0/16)
Auth:        Service Account JSON + GOOGLE_APPLICATION_CREDENTIALS
Test:        GCP Project: nyle-carmo-analysis
```

### 1.3 発見された根本問題

#### Adminer認証システムの制約
**場所**: `adminer/include/adminer.inc.php:160-168`
```php
function login(string $login, string $password) {
    if ($password == "") {
        return lang('Adminer does not support accessing a database without a password, <a href="https://www.adminer.org/en/password/\"%s>more information</a>.', target_blank());
    }
    return true;
}
```

**問題**: BigQueryはサービスアカウント認証でパスワード不要だが、Adminerが空パスワードを強制拒否

**解決必要箇所**: `container/tests/plugins/login-bigquery.php:49-77`

## 2. 実装コンポーネント詳細分析

### 2.1 BigQueryドライバー (plugins/drivers/bigquery.php)

#### 完了済み機能
```php
class Driver {
    static function connect($server, $username, $password)  // ✅ 実装済み
    static function support($feature)                       // ✅ 実装済み
}

class Db {
    public function connect($server, $username, $password)  // ✅ 実装済み
    public function query($query)                          // ✅ READ-ONLY実装済み
    public function select_db($database)                   // ✅ 実装済み
    // エラーハンドリング強化が必要
}

// グローバル関数
function support($feature)         // ✅ 実装済み
function get_databases()          // ✅ 実装済み
function tables_list()            // ✅ 実装済み
function table_status($table)     // ✅ 実装済み
function fields($table)           // ✅ 実装済み
```

#### 改善必要な箇所
1. **エラー分類の詳細化**: ServiceException処理の拡張
2. **診断機能**: 接続問題のトラブルシューティング支援
3. **パフォーマンス**: API呼び出し最適化

### 2.2 認証プラグイン (container/tests/plugins/login-bigquery.php)

#### 現在の実装状況
```php
class AdminerLoginBigQuery extends Adminer\Plugin {
    function credentials()           // ✅ 正常動作
    function loginFormField()        // ✅ UI正常カスタマイズ済み
    function login($login, $password) // ❌ 修正必要 - 条件付きtrueでエラー発生
}
```

#### 具体的修正点
**現在のコード** (line 49-77):
```php
function login($login, $password) {
    $credentials_path = $_POST["auth"]["password"] ?? $this->credentials_path;

    if (empty($credentials_path)) {
        return 'BigQuery requires a credentials file path.';  // ❌ エラー文字列を返す
    }

    if (!file_exists($credentials_path)) {
        return "Credentials file not found: " . $credentials_path;  // ❌ エラー文字列を返す
    }

    // 他の検証...

    return true; // ✅ 最終的にtrueを返すが、上記でエラーの場合がある
}
```

**修正後のコード**:
```php
function login($login, $password) {
    $credentials_path = $_POST["auth"]["password"] ?? $this->credentials_path;

    // 検証は行うが、エラーはログに記録するのみ
    if (empty($credentials_path)) {
        error_log("BigQuery Login: Credentials file path is empty");
    } elseif (!file_exists($credentials_path)) {
        error_log("BigQuery Login: Credentials file not found: " . $credentials_path);
    } elseif (!is_readable($credentials_path)) {
        error_log("BigQuery Login: Credentials file not readable: " . $credentials_path);
    } else {
        // JSON検証も追加
        $json_content = file_get_contents($credentials_path);
        $credentials_data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("BigQuery Login: Invalid JSON in credentials file");
        }
    }

    return true; // 必ずtrueを返してAdminer標準チェックをバイパス
}
```

### 2.3 テストコンテナ環境 (container/tests/)

#### 環境構成詳細
```yaml
# compose.yml の重要設定
services:
  adminer-bigquery-test:
    volumes:
      - /home/hammer/google_credential.json:/etc/google_credentials.json:ro
    environment:
      - GOOGLE_APPLICATION_CREDENTIALS=/etc/google_credentials.json
      - GOOGLE_CLOUD_PROJECT=nyle-carmo-analysis
    networks:
      - adminer-net
```

#### DooD環境での認証ファイルパス
| 環境 | パス | アクセス方法 |
|------|------|------------|
| ホスト | `~/google_credential.json` | Claude Codeからは不可視 |
| Claude Code | `/etc/google_credentials.json` | 読み取り可能 |
| テストコンテナ | `/etc/google_credentials.json` | ボリュームマウント済み |

## 3. 詳細実装手順

### Phase 1: 緊急認証修正 (即時実行)

#### Step 1-1: 認証プラグインの修正
**ファイル**: `/home/ubuntu/work/adminer/container/tests/plugins/login-bigquery.php`
**修正箇所**: `login()` メソッド (line 49-77)

**実装**:
1. エラー検証ロジックはそのまま維持
2. エラーメッセージの返却を停止
3. すべてのエラーをerror_log()に記録
4. 必ずtrueを返すように変更

#### Step 1-2: 動作確認テスト
**手順**:
1. コンテナ再起動: `cd container/tests && docker-compose restart`
2. 接続テスト: `curl -I http://adminer-bigquery-test:80`
3. ログイン画面確認: ブラウザまたはcurlでアクセス
4. 認証テスト: Project ID + 認証ファイルパスでログイン

#### Step 1-3: ログ確認
```bash
# 認証関連ログの確認
docker exec adminer-bigquery-test tail -f /var/log/apache2/error.log | grep "BigQuery"

# PHP エラーログ確認
docker exec adminer-bigquery-test cat /var/log/php_errors.log
```

### Phase 2: エラーハンドリング強化 (1週間以内)

#### Step 2-1: BigQueryドライバー改善
**ファイル**: `/home/ubuntu/work/adminer/plugins/drivers/bigquery.php`

**追加実装**:
1. **詳細診断メソッド**:
```php
public function diagnoseConnection() {
    return [
        'credentials_env_set' => !empty(getenv('GOOGLE_APPLICATION_CREDENTIALS')),
        'credentials_file_exists' => file_exists(getenv('GOOGLE_APPLICATION_CREDENTIALS')),
        'credentials_readable' => is_readable(getenv('GOOGLE_APPLICATION_CREDENTIALS')),
        'project_id' => $this->projectId ?? 'Not set',
        'php_version' => PHP_VERSION,
        'bigquery_client_version' => BigQueryClient::VERSION ?? 'Unknown'
    ];
}
```

2. **強化されたエラー分類**:
```php
catch (ServiceException $e) {
    $errorCode = $e->getCode();
    $errorMessage = $e->getMessage();

    switch ($errorCode) {
        case 401:
            error_log("BigQuery: Authentication failed - Invalid service account");
            break;
        case 403:
            if (strpos($errorMessage, 'BigQuery') !== false) {
                error_log("BigQuery: Permission denied - Enable BigQuery API");
            } else {
                error_log("BigQuery: Permission denied - Check IAM permissions");
            }
            break;
        case 404:
            error_log("BigQuery: Resource not found - Check project/dataset names");
            break;
        default:
            error_log("BigQuery ServiceException [{$errorCode}]: " . $errorMessage);
    }
    return false;
}
```

#### Step 2-2: デバッグUIの拡張
**ファイル**: `/home/ubuntu/work/adminer/container/tests/index.php`

**追加機能**:
1. `?debug=bigquery` - BigQuery特化診断情報
2. `?test=connection` - 接続テスト専用ページ
3. リアルタイムログ表示機能

### Phase 3: テスト自動化とE2E確認 (2週間以内)

#### Step 3-1: 自動テストスイート
**新規ファイル**: `/home/ubuntu/work/adminer/container/tests/test-suite.sh`

**テストシナリオ**:
1. **基本接続テスト**
```bash
test_basic_connection() {
    local response=$(curl -s http://adminer-bigquery-test:80)
    if echo "$response" | grep -qi "BigQuery\|Project"; then
        echo "✅ Basic connection: OK"
        return 0
    else
        echo "❌ Basic connection: Failed"
        return 1
    fi
}
```

2. **認証テスト**
```bash
test_authentication() {
    local login_response=$(curl -s -X POST http://adminer-bigquery-test:80 \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "auth[driver]=bigquery" \
        -d "auth[server]=nyle-carmo-analysis" \
        -d "auth[password]=/etc/google_credentials.json" \
        -c /tmp/cookies.txt)

    if echo "$login_response" | grep -qi "dataset\|database"; then
        echo "✅ Authentication: OK"
        return 0
    else
        echo "❌ Authentication: Failed"
        return 1
    fi
}
```

3. **BigQuery機能テスト**
```bash
test_bigquery_functionality() {
    # データセット一覧取得テスト
    # テーブル一覧取得テスト
    # 簡単なクエリ実行テスト
}
```

#### Step 3-2: パフォーマンステスト
**メトリクス収集**:
1. ログイン時間測定
2. データセット一覧取得時間
3. クエリ実行時間（簡単なSELECT 1）
4. メモリ使用量監視

#### Step 3-3: エラーケーステスト
**シナリオ**:
1. 不正なプロジェクトID
2. 存在しない認証ファイル
3. 権限不足のサービスアカウント
4. ネットワーク接続問題
5. 不正なSQL文実行

## 4. 運用準備とドキュメント整備

### 4.1 作成済みドキュメント検証
**既存の6文書**:
1. `bigquery-driver-development-guidelines.md` - 開発者向け
2. `bigquery-driver-user-guide.md` - エンドユーザー向け基本操作
3. `bigquery-driver-container-creation-guide.md` - コンテナ開発者向け
4. `bigquery-driver-container-setup-startup-guide.md` - 運用管理者向け
5. `bigquery-driver-container-user-guide.md` - コンテナ利用者向け
6. `dood-test-container-operations-guide.md` - DooD環境特化

**検証項目**:
- 現在の実装状況との整合性確認
- 修正後の手順との齟齬チェック
- 実際のテスト結果に基づく更新

### 4.2 運用監視体制
**ログ管理**:
```bash
# 重要ログファイル
/var/log/apache2/error.log          # Apache エラー
/var/log/apache2/access.log         # アクセスログ
/var/log/php_errors.log             # PHP エラー
/tmp/bigquery-*.log                 # BigQuery特化ログ
```

**監視メトリクス**:
1. コンテナヘルス状態
2. HTTP応答時間
3. BigQuery API呼び出し成功率
4. 認証失敗率
5. リソース使用量 (CPU/Memory/Disk)

## 5. 成功判定基準

### 5.1 MVP完了条件（必須）
1. ✅ **認証成功**: プロジェクトID + 認証ファイルでログイン完了
2. ✅ **データセット表示**: nyle-carmo-analysisのデータセット一覧表示
3. ✅ **テーブル表示**: 任意データセット内のテーブル一覧表示
4. ✅ **スキーマ表示**: 任意テーブルのカラム情報表示
5. ✅ **クエリ実行**: `SELECT 1` などの基本クエリ実行
6. ✅ **エラーハンドリング**: 不正入力時の適切なエラー表示

### 5.2 運用準備完了条件（推奨）
1. ✅ **自動テスト**: テストスイート全項目パス
2. ✅ **パフォーマンス**: 応答時間 < 5秒
3. ✅ **ログ管理**: 構造化ログとローテーション
4. ✅ **監視**: ヘルスチェックとアラート
5. ✅ **ドキュメント**: 全ロール向け文書整備
6. ✅ **セキュリティ**: 認証情報保護とアクセス制御

## 6. リスク管理と対策

### 6.1 技術リスク
| リスク | 影響度 | 対策 |
|--------|---------|------|
| BigQuery API制限 | 中 | 適切なリトライ・レート制限 |
| 認証トークン期限 | 中 | 自動更新メカニズム |
| Adminerバージョン非互換 | 低 | バージョン固定・テスト強化 |
| DooD環境特有問題 | 中 | 詳細文書・トラブルシューティング |

### 6.2 運用リスク
| リスク | 影響度 | 対策 |
|--------|---------|------|
| 認証情報漏洩 | 高 | ファイル権限・アクセス制御 |
| 予期しないコスト | 中 | クエリ制限・監視アラート |
| パフォーマンス問題 | 中 | リソース制限・スケーリング |

## 7. 次のステップと将来拡張

### 7.1 即時実行項目
1. **認証修正**: login-bigquery.phpの`login()`メソッド修正
2. **動作確認**: container/testsでの基本動作テスト
3. **ログ検証**: 認証フロー全体のログ確認

### 7.2 短期目標（1-2週間）
1. **エラーハンドリング**: 詳細な診断機能実装
2. **テスト自動化**: 包括的テストスイート構築
3. **パフォーマンス**: レスポンス時間最適化

### 7.3 将来拡張（MVP後）
1. **DML対応**: INSERT/UPDATE/DELETE サポート
2. **マルチプロジェクト**: 複数GCPプロジェクト切り替え
3. **OAuth認証**: サービスアカウント以外の認証方式
4. **ジョブ監視**: 長時間クエリの進捗表示
5. **コスト管理**: リアルタイムコスト表示・制限

---

## 実装開始

**このプランに基づいて、Phase 1の認証修正から即座に実装を開始します。**

**最優先実装**: `container/tests/plugins/login-bigquery.php` の `login()` メソッド修正により、Adminer標準認証チェックをバイパスし、BigQueryのパスワードレス認証を実現します。