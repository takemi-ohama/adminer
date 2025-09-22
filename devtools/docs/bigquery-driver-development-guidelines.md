# BigQuery ドライバー開発ガイドライン

## 1. 概要

本ドキュメントは、Adminer BigQuery ドライバープラグインの開発に関するガイドラインです。開発者がプラグインの拡張や修正を行う際の指針と技術的詳細を提供します。

## 2. プロジェクト構成

### 2.1 ディレクトリ構造

```
adminer/
├── adminer/                    # Adminerコア
│   ├── drivers/               # 標準ドライバー
│   └── include/              # コア機能
├── plugins/                   # プラグイン群
│   ├── drivers/              # ドライバープラグイン
│   │   └── bigquery.php      # BigQueryドライバー（本体）
│   └── login-bigquery.php    # BigQuery認証プラグイン（任意）
├── container/                 # 開発・テスト用コンテナ
│   ├── dev/                  # 開発環境
│   └── tests/                # テスト環境
├── container/docs/           # ドキュメント
├── container/issues/         # プロジェクト管理
└── vendor/                   # Composer依存関係
```

### 2.2 主要ファイル

| ファイル | 役割 | 必須 |
|---------|------|------|
| `plugins/drivers/bigquery.php` | BigQueryドライバー本体 | ✅ |
| `container/tests/plugins/login-bigquery.php` | BigQuery用認証プラグイン | ⭕ |
| `composer.json` | PHP依存関係定義 | ✅ |
| `container/tests/index.php` | テスト用エントリーポイント | ⭕ |

## 3. 開発環境セットアップ

### 3.1 必要な依存関係

#### PHP拡張
- PHP 8.0以上
- PDO拡張
- JSON拡張
- OpenSSL拡張

#### Composer依存関係
```json
{
    "require": {
        "google/cloud-bigquery": "^1.34"
    }
}
```

#### システム依存関係
- Docker & Docker Compose（テスト環境用）
- Google Cloud SDK（テスト用）

### 3.2 開発環境起動

```bash
# Composer依存関係のインストール
composer install

# 開発用コンテナ起動
cd container/dev
docker-compose up -d

# テスト用コンテナ起動
cd container/tests
docker-compose up -d
```

## 4. アーキテクチャ設計

### 4.1 Adminerドライバーインターフェース

BigQueryドライバーは以下の標準インターフェースを実装する必要があります：

#### 必須メソッド

```php
class Driver {
    // 接続関連
    static function connect($server, $username, $password);

    // 機能サポート宣言
    static function support($feature);

    // データベース操作
    static function logged_user();
    static function get_databases();
    static function tables_list();
    static function table_status($table);
    static function fields($table);
}

class Db {
    // 接続管理
    function connect($server, $username, $password);
    function select_db($database);

    // クエリ実行
    function query($query);
    function quote($string);
    function error();
}

class Result {
    // 結果処理
    function fetch_row();
    function fetch_assoc();
    function fetch_field();
    function num_fields();
    function num_rows();
}
```

### 4.2 BigQuery固有の実装

#### データモデルマッピング

| Adminer概念 | BigQuery対応 | 実装上の注意 |
|-------------|-------------|-------------|
| Server | GCP Project | プロジェクトIDを使用 |
| Database | Dataset | `get_databases()`でデータセット一覧 |
| Schema | Dataset | BigQueryではDataset≒Schema |
| Table | Table/View/MaterializedView | `table_status()`で種別判定 |

#### 認証フロー

```php
// 1. 認証プラグインでの前処理
AdminerLoginBigQuery::credentials()
→ 環境変数設定 + (server, '', '') を返却

// 2. ドライバーでの接続処理
Driver::connect($server, '', '')
→ Db::connect($project_id, '', '')
→ BigQueryClient初期化
```

### 4.3 エラーハンドリング設計

#### エラー分類

```php
try {
    // BigQuery操作
} catch (ServiceException $e) {
    // Google Cloud API関連エラー
    if (strpos($e->getMessage(), 'UNAUTHENTICATED') !== false) {
        // 認証エラー
    } elseif (strpos($e->getMessage(), 'PERMISSION_DENIED') !== false) {
        // 権限エラー
    } elseif (strpos($e->getMessage(), 'NOT_FOUND') !== false) {
        // リソース未発見
    }
} catch (Exception $e) {
    // その他のエラー
}
```

#### ログ出力

```php
// セキュリティに配慮した安全なログ出力
$safeMessage = preg_replace('/project[s]?[\\s:]+[a-z0-9\\-]+/i', 'project: [REDACTED]', $e->getMessage());
error_log("BigQuery error: " . $safeMessage);
```

## 5. 開発プラクティス

### 5.1 コーディング規約

#### 命名規則
- クラス名: PascalCase (`BigQueryDriver`)
- メソッド名: camelCase (`connectToProject`)
- 定数: SNAKE_CASE (`DRIVER_NAME`)

#### セキュリティ原則
1. **最小権限**: 必要最小限のBigQuery権限のみ使用
2. **情報秘匿**: ログにプロジェクトID・認証情報を出力しない
3. **入力検証**: SQLインジェクション対策の徹底
4. **READ-ONLY**: MVP段階ではDML操作を禁止

### 5.2 テスト手法

#### ユニットテスト
```php
// plugins/drivers/bigquery.php のテスト例
class BigQueryDriverTest extends PHPUnit\Framework\TestCase {
    public function testConnect() {
        $driver = new Driver();
        $result = $driver->connect('test-project', '', '');
        $this->assertInstanceOf(Db::class, $result);
    }
}
```

#### 統合テスト
```bash
# container/testsでの手動テスト手順
1. docker-compose up -d
2. http://adminer-bigquery-test:80 でアクセス
3. Project ID + Credentials Path でログイン
4. 各機能の動作確認
```

### 5.3 デバッグ手法

#### デバッグ機能の活用

```php
// container/tests/index.php のデバッグURL
http://adminer-bigquery-test:80?debug=1  // 環境情報表示
http://adminer-bigquery-test:80?test=1   // プラグイン読み込み状況
```

#### エラーログの確認

```bash
# コンテナ内のPHPエラーログ
docker exec adminer-bigquery-test tail -f /var/log/apache2/error.log

# BigQuery関連ログの抽出
docker logs adminer-bigquery-test | grep "BigQuery"
```

## 6. プラグイン拡張ガイド

### 6.1 認証プラグインのカスタマイズ

```php
class AdminerLoginBigQuery extends Adminer\Plugin {
    // ログインフォームのカスタマイズ
    function loginFormField($name, $heading, $value) {
        if ($name == 'server') {
            // Project ID入力フィールド
            return '<tr><th>Project ID<td><input name="auth[server]" value="' . h($value) . '">';
        }
        // その他フィールドの処理...
    }

    // 認証ロジックのオーバーライド
    function login($login, $password) {
        // カスタム認証ロジック
        return true; // Adminer標準チェックをバイパス
    }
}
```

### 6.2 ドライバー機能の拡張

#### 新機能の追加手順

1. **support()メソッドで機能宣言**
```php
static function support($feature) {
    return in_array($feature, [
        'sql',        // SQLクエリ実行
        'table',      // テーブル操作
        'explain',    // EXPLAIN（dryRun）
        'new_feature' // 新機能追加
    ]);
}
```

2. **対応メソッドの実装**
```php
static function new_feature_handler($params) {
    // 新機能の実装
}
```

3. **テストの追加**
```php
public function testNewFeature() {
    // 新機能のテスト
}
```

## 7. パフォーマンス最適化

### 7.1 BigQuery API最適化

#### クエリ最適化
- `LIMIT` / `OFFSET` の適切な使用
- 不必要な `SELECT *` の回避
- パーティション列の活用

#### API呼び出し最適化
```php
// バッチ処理での最適化例
$queryConfig = $bigQueryClient->query($sql)
    ->jobConfig([
        'location' => $this->location,
        'dryRun' => false,
        'useQueryCache' => true
    ]);
```

### 7.2 メモリ管理

```php
// 大きな結果セットの処理
class Result {
    private $iterator;

    public function fetch_row() {
        // ストリーミング処理でメモリ効率化
        if (!$this->iterator->valid()) return false;

        $row = $this->iterator->current();
        $this->iterator->next();
        return $row;
    }
}
```

## 8. デプロイメント

### 8.1 プロダクション環境への配布

#### ファイル構成
```
adminer-bigquery-plugin/
├── plugins/
│   └── drivers/
│       └── bigquery.php      # 単体配布用
├── composer.json              # 依存関係
└── README.md                 # 使用方法
```

#### インストール手順
1. Composerで依存関係をインストール
2. `plugins/drivers/bigquery.php` をAdminerプラグインディレクトリに配置
3. 認証プラグイン（任意）も配置
4. Adminerの`index.php`でプラグインを有効化

### 8.2 バージョン管理

```php
// plugins/drivers/bigquery.php でのバージョン管理
class Driver {
    const VERSION = '1.0.0';

    static function version() {
        return self::VERSION;
    }
}
```

## 9. トラブルシューティング

### 9.1 一般的な問題と解決策

| 問題 | 原因 | 解決策 |
|------|------|--------|
| 認証エラー | サービスアカウント権限不足 | BigQuery権限の確認・追加 |
| 接続エラー | プロジェクトID不正 | プロジェクトIDの確認 |
| クエリエラー | SQL構文エラー | BigQuery標準SQLの使用 |
| パフォーマンス問題 | 非効率なクエリ | EXPLAIN実行・最適化 |

### 9.2 ログ分析

```bash
# エラーパターンの分析
grep "BigQuery" /var/log/apache2/error.log | \
grep "UNAUTHENTICATED\|PERMISSION_DENIED\|NOT_FOUND"
```

## 10. リリース管理

### 10.1 リリースプロセス

1. **開発・テスト**: `container/tests` での動作確認
2. **コードレビュー**: セキュリティ・性能の確認
3. **ドキュメント更新**: 変更点の文書化
4. **バージョンタグ**: Git タグでのリリース管理
5. **配布**: プラグインファイルの配布・公開

### 10.2 後方互換性

- Adminerコアバージョンとの互換性維持
- 既存設定ファイルとの互換性確保
- 段階的な機能廃止（Deprecation）の実施

---

このガイドラインに従って、安全で効率的なBigQueryドライバーの開発・運用を行ってください。