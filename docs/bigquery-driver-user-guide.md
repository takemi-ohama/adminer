# BigQuery ドライバー設定・利用方法（ユーザー向け）

## 1. 概要

Adminer BigQuery ドライバーは、Google Cloud BigQuery に接続してデータベース操作を行うためのプラグインです。このドキュメントでは、エンドユーザー向けの設定方法と基本的な使用方法を説明します。

## 2. 前提条件

### 2.1 必要な環境

- **PHP**: 8.0以上
- **Webサーバー**: Apache、Nginx等
- **Adminer**: 4.8.1以上
- **Google Cloud Platform アカウント**
- **BigQuery が有効化されたGCPプロジェクト**

### 2.2 必要な権限

BigQuery への接続には、以下の IAM 権限が必要です：

| 権限 | 用途 | 必須度 |
|------|------|--------|
| `bigquery.datasets.get` | データセット情報取得 | ✅ 必須 |
| `bigquery.tables.list` | テーブル一覧取得 | ✅ 必須 |
| `bigquery.tables.get` | テーブル情報取得 | ✅ 必須 |
| `bigquery.jobs.create` | クエリ実行 | ✅ 必須 |
| `bigquery.jobs.listAll` | ジョブ一覧取得 | ⭕ 推奨 |

### 2.3 推奨 IAM ロール

```bash
# 読み取り専用での利用（推奨）
roles/bigquery.dataViewer
roles/bigquery.user

# より限定的な権限での利用
roles/bigquery.metadataViewer
roles/bigquery.jobUser
```

## 3. インストール手順

### 3.1 プラグインファイルの配置

1. **BigQueryドライバーの配置**
```bash
# Adminerのプラグインディレクトリに配置
cp plugins/drivers/bigquery.php /path/to/adminer/plugins/drivers/
```

2. **認証プラグインの配置（オプション）**
```bash
# BigQuery用ログインプラグイン（ログイン画面の簡素化）
cp plugins/login-bigquery.php /path/to/adminer/plugins/
```

### 3.2 Composer依存関係のインストール

```bash
# BigQuery PHP クライアントライブラリのインストール
composer require google/cloud-bigquery
```

### 3.3 Adminer設定

#### 標準的な設定（adminer/index.php）

```php
<?php
// Google Cloud BigQuery クライアントの読み込み
require_once 'vendor/autoload.php';

// BigQuery ドライバーの読み込み
require_once 'plugins/drivers/bigquery.php';

// オプション：認証プラグインの使用
function adminer_object() {
    include_once 'plugins/login-bigquery.php';

    $plugins = array(
        // BigQuery用ログインフォームのカスタマイズ
        new AdminerLoginBigQuery('your-default-project-id', '/path/to/credentials.json')
    );

    return new AdminerPlugin($plugins);
}

include 'adminer.php';
?>
```

## 4. 認証設定

### 4.1 サービスアカウントの作成

1. **Google Cloud Console でサービスアカウント作成**
```bash
# gcloud CLI での作成例
gcloud iam service-accounts create adminer-bigquery \
    --display-name="Adminer BigQuery Access" \
    --description="Service account for Adminer BigQuery plugin"
```

2. **キーファイルのダウンロード**
```bash
gcloud iam service-accounts keys create credentials.json \
    --iam-account=adminer-bigquery@YOUR_PROJECT_ID.iam.gserviceaccount.com
```

3. **権限の割り当て**
```bash
# プロジェクトレベルでの権限付与
gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
    --member="serviceAccount:adminer-bigquery@YOUR_PROJECT_ID.iam.gserviceaccount.com" \
    --role="roles/bigquery.dataViewer"

gcloud projects add-iam-policy-binding YOUR_PROJECT_ID \
    --member="serviceAccount:adminer-bigquery@YOUR_PROJECT_ID.iam.gserviceaccount.com" \
    --role="roles/bigquery.user"
```

### 4.2 認証ファイルの配置

#### 方法1: 環境変数での指定（推奨）

```bash
# システム全体での設定
export GOOGLE_APPLICATION_CREDENTIALS="/path/to/credentials.json"

# Webサーバー設定（Apache例）
SetEnv GOOGLE_APPLICATION_CREDENTIALS "/path/to/credentials.json"

# Webサーバー設定（Nginx + PHP-FPM例）
fastcgi_param GOOGLE_APPLICATION_CREDENTIALS "/path/to/credentials.json";
```

#### 方法2: ログイン画面での指定

BigQuery用ログインプラグインを使用している場合、ログイン時にクレデンシャルファイルのパスを指定できます。

### 4.3 セキュリティ設定

```bash
# 認証ファイルの適切な権限設定
chmod 600 /path/to/credentials.json
chown www-data:www-data /path/to/credentials.json

# Webからアクセスできない場所に配置
mkdir /etc/adminer/
mv credentials.json /etc/adminer/
```

## 5. 使用方法

### 5.1 ログイン手順

#### 標準的なAdminerログイン画面の場合

1. Adminerにアクセス
2. **System**: `bigquery` を選択
3. **Server**: GCPプロジェクトID を入力（例: `my-project-123`）
4. **Username**: 空白のまま
5. **Password**: 空白のまま（環境変数で認証設定済みの場合）
6. **Database**: 空白のまま

#### BigQuery認証プラグイン使用時

1. Adminerにアクセス
2. **Project ID**: GCPプロジェクトID を入力
3. **Credentials File**: 認証ファイルのパス を入力（例: `/etc/adminer/credentials.json`）
4. **Login** ボタンをクリック

### 5.2 基本的な操作

#### データセット（データベース）の閲覧

```
ログイン後、左サイドバーにBigQueryのデータセット一覧が表示されます。
- データセット名をクリックしてテーブル一覧を表示
- テーブル名をクリックしてスキーマを表示
```

#### テーブル データの閲覧

```sql
-- テーブル内容の表示（Selectタブから実行）
SELECT * FROM `dataset_name.table_name` LIMIT 100;

-- 条件付きクエリ
SELECT column1, column2
FROM `dataset_name.table_name`
WHERE column1 > '2023-01-01'
ORDER BY column2 DESC
LIMIT 50;
```

#### メタデータの確認

```sql
-- データセット一覧
SELECT * FROM `region-us.INFORMATION_SCHEMA.SCHEMATA`;

-- テーブル一覧
SELECT * FROM `dataset_name.INFORMATION_SCHEMA.TABLES`;

-- カラム情報
SELECT * FROM `dataset_name.INFORMATION_SCHEMA.COLUMNS`
WHERE table_name = 'your_table';
```

### 5.3 クエリ実行時の注意点

#### サポートされる操作
- ✅ **SELECT**: データの読み取り
- ✅ **EXPLAIN**: クエリプランの表示（dryRun相当）
- ✅ **メタデータ参照**: スキーマ・テーブル情報の表示

#### サポートされない操作（読み取り専用モード）
- ❌ **INSERT, UPDATE, DELETE**: データ変更操作
- ❌ **CREATE, DROP**: DDL操作
- ❌ **GRANT, REVOKE**: 権限管理

#### BigQuery固有の制限
- **標準SQL**: Legacy SQL（レガシーSQL）は非対応
- **処理時間**: 長時間実行クエリはタイムアウトの可能性
- **データ量**: 大量データの場合はLIMIT句の使用を推奨

## 6. トラブルシューティング

### 6.1 よくある問題と解決策

#### 問題1: ログインできない

**エラー**: "Authentication failed" または "Invalid credentials"

**解決策**:
```bash
# 認証ファイルの確認
ls -la /path/to/credentials.json
cat /path/to/credentials.json | jq .type  # "service_account" であることを確認

# 環境変数の確認
echo $GOOGLE_APPLICATION_CREDENTIALS
```

#### 問題2: データセットが表示されない

**エラー**: データセット一覧が空または"Permission denied"

**解決策**:
```bash
# サービスアカウントの権限確認
gcloud projects get-iam-policy YOUR_PROJECT_ID \
    --flatten="bindings[].members" \
    --format="table(bindings.role)" \
    --filter="bindings.members:adminer-bigquery@YOUR_PROJECT_ID.iam.gserviceaccount.com"
```

#### 問題3: クエリ実行エラー

**エラー**: "Query failed" または "Access denied"

**解決策**:
```sql
-- テーブル存在確認
SELECT table_name FROM `dataset_name.INFORMATION_SCHEMA.TABLES`
WHERE table_name = 'your_table';

-- 権限確認用のシンプルクエリ実行
SELECT 1 as test;
```

### 6.2 デバッグ方法

#### PHPエラーログの確認

```bash
# Apache の場合
tail -f /var/log/apache2/error.log | grep "BigQuery"

# Nginx の場合
tail -f /var/log/nginx/error.log | grep "BigQuery"
```

#### Google Cloud ログの確認

```bash
# BigQuery ジョブログの確認
gcloud logging read "resource.type=bigquery_project" --limit=10
```

### 6.3 パフォーマンス最適化

#### 効率的なクエリの書き方

```sql
-- ❌ 非効率: 全件スキャン
SELECT * FROM `dataset.large_table` WHERE column1 = 'value';

-- ✅ 効率的: パーティション列の活用
SELECT * FROM `dataset.large_table`
WHERE _PARTITIONDATE = '2023-12-01'
  AND column1 = 'value';

-- ✅ 効率的: 必要な列のみ選択
SELECT column1, column2 FROM `dataset.large_table`
WHERE _PARTITIONDATE BETWEEN '2023-12-01' AND '2023-12-07';
```

#### EXPLAIN（dryRun）の活用

```sql
-- クエリのコスト見積もり（Adminer の EXPLAIN ボタン使用）
-- または手動でのdryRun確認
-- 実際のクエリ実行前にスキャン量を確認可能
```

## 7. セキュリティのベストプラクティス

### 7.1 認証情報の管理

```bash
# 認証ファイルの適切な配置
- ✅ /etc/adminer/ (Webルート外)
- ✅ /var/secrets/ (専用ディレクトリ)
- ❌ /var/www/html/ (Webルート内)

# ファイル権限の設定
chmod 600 credentials.json
chown www-data:www-data credentials.json
```

### 7.2 ネットワークセキュリティ

```bash
# IP制限の設定（Apache例）
<Directory "/path/to/adminer">
    Require ip 192.168.1.0/24
    Require ip 10.0.0.0/8
</Directory>

# HTTPS の強制
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 7.3 監査ログの設定

```bash
# BigQuery監査ログの有効化
gcloud logging sinks create bigquery-audit-sink \
    bigquery.googleapis.com/projects/YOUR_PROJECT_ID/datasets/audit_logs \
    --log-filter="protoPayload.serviceName=bigquery.googleapis.com"
```

## 8. FAQ

### Q1. 複数のGCPプロジェクトに接続できますか？

A1. はい。ログイン時に異なるプロジェクトIDを指定することで、複数プロジェクトへの接続が可能です。ただし、サービスアカウントが各プロジェクトで適切な権限を持っている必要があります。

### Q2. データの書き込みはできますか？

A2. 現在のMVPバージョンは読み取り専用です。INSERT、UPDATE、DELETE等のDML操作は今後のバージョンで対応予定です。

### Q3. 大量データの処理はできますか？

A3. BigQueryの特性上、大量データの処理は可能ですが、Adminerのメモリ制限やタイムアウト設定に注意が必要です。大量データの場合は適切なLIMIT句の使用を推奨します。

### Q4. コスト管理はどうすればよいですか？

A4. BigQueryは処理データ量に基づく課金のため、以下を推奨します：
- EXPLAIN機能でクエリコストを事前確認
- 適切なWHERE句やLIMIT句の使用
- パーティション列の活用

---

ご不明な点がございましたら、開発チームまでお問い合わせください。