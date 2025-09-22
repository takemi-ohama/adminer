# BigQuery ドライバーコンテナの設定・利用方法（ユーザー向け）

## 1. はじめに

このドキュメントでは、Adminer BigQuery ドライバーコンテナを日常的に使用するエンドユーザー向けの操作方法を説明します。データアナリスト、データサイエンティスト、その他BigQueryを業務で使用される方を対象としています。

### 1.1 BigQuery ドライバーコンテナとは

Adminer BigQuery ドライバーコンテナは、Google Cloud BigQueryにWebブラウザから簡単にアクセスできるツールです。

**主な特徴**:
- 🌐 **Webベース**: ブラウザだけでBigQueryにアクセス
- 🔒 **セキュア**: サービスアカウント認証による安全な接続
- 📊 **直感的**: SQLクエリの実行とデータ閲覧
- 💡 **軽量**: Docker コンテナによる簡単デプロイ

## 2. アクセス方法

### 2.1 基本的なアクセス手順

1. **Webブラウザでアクセス**
```
http://your-server:8080
```
> サーバーアドレスとポート番号は管理者にご確認ください

2. **推奨ブラウザ**
   - Google Chrome (最新版)
   - Mozilla Firefox (最新版)
   - Microsoft Edge (最新版)
   - Safari (最新版)

### 2.2 ログイン画面

#### パターンA: 標準ログイン画面
```
System:    Google BigQuery
Server:    [プロジェクトID]
Username:  [空白]
Password:  [空白]
Database:  [空白]
```

#### パターンB: BigQuery専用ログイン画面
```
Project ID:        [GCPプロジェクトID]
Credentials File:  [認証ファイルパス]
```

> **注意**: 認証ファイルパスは管理者が設定した固定値を使用する場合があります

## 3. 基本操作

### 3.1 データセットの閲覧

1. **ログイン後の画面**
   - 左側にデータセット（データベース）一覧が表示されます
   - データセット名をクリックしてテーブル一覧を表示

2. **データセット情報**
   ```
   📁 dataset_name
   ├── 📄 table1 (テーブル)
   ├── 👁️ view1 (ビュー)
   └── 🔄 materialized_view1 (マテリアライズドビュー)
   ```

### 3.2 テーブルの探索

#### テーブル構造の確認
1. テーブル名をクリック
2. **Structure** タブでスキーマ情報を確認
   - カラム名
   - データ型
   - NULL許可
   - 説明（Description）

#### データの閲覧
1. **Select** タブをクリック
2. 自動的にサンプルデータが表示されます
```sql
SELECT * FROM `project.dataset.table` LIMIT 50;
```

### 3.3 SQLクエリの実行

#### 基本的なクエリ実行
1. **SQL command** タブをクリック
2. クエリエディタにSQLを入力
3. **Execute** ボタンをクリック

#### クエリ例

**基本的なSELECT**:
```sql
SELECT
    column1,
    column2,
    COUNT(*) as count
FROM `your-project.your-dataset.your-table`
WHERE column1 > '2023-01-01'
GROUP BY column1, column2
ORDER BY count DESC
LIMIT 100;
```

**日付フィルター**:
```sql
SELECT *
FROM `your-project.your-dataset.your-table`
WHERE DATE(timestamp_column) = '2023-12-01'
  AND status = 'active'
LIMIT 1000;
```

**集計クエリ**:
```sql
SELECT
    DATE(created_at) as date,
    COUNT(*) as daily_count,
    AVG(amount) as avg_amount
FROM `your-project.your-dataset.transactions`
WHERE created_at >= '2023-12-01'
GROUP BY DATE(created_at)
ORDER BY date;
```

## 4. 効率的な使い方

### 4.1 クエリのベストプラクティス

#### 💡 パフォーマンス最適化

**✅ 良い例**: 必要な列のみ選択
```sql
SELECT id, name, created_at
FROM `project.dataset.table`
WHERE DATE(_PARTITIONTIME) = '2023-12-01';
```

**❌ 悪い例**: 全列選択
```sql
SELECT *
FROM `project.dataset.large_table`;
```

#### 💡 コスト最適化

**✅ 良い例**: パーティション列の活用
```sql
SELECT COUNT(*)
FROM `project.dataset.table`
WHERE _PARTITIONDATE BETWEEN '2023-12-01' AND '2023-12-07';
```

**❌ 悪い例**: 全期間スキャン
```sql
SELECT COUNT(*)
FROM `project.dataset.table`
WHERE event_type = 'click';
```

### 4.2 便利な機能

#### EXPLAIN（クエリプラン確認）
1. クエリを入力後、**Explain** ボタンをクリック
2. スキャン予定データ量とコスト見積もりを確認
3. 実際の実行前にコストを把握できます

#### 結果のエクスポート
1. クエリ実行後、結果テーブル上で右クリック
2. **Export** を選択
3. CSV、JSON等の形式でダウンロード可能

#### クエリ履歴
- ブラウザの履歴機能で過去のクエリを再利用
- **戻る** ボタンで前のクエリに戻る

## 5. データ分析の実践例

### 5.1 基本的なデータ探索

#### ステップ1: データ概要の把握
```sql
-- テーブルの行数確認
SELECT COUNT(*) as total_rows
FROM `project.dataset.table`;

-- データ期間の確認
SELECT
    MIN(date_column) as start_date,
    MAX(date_column) as end_date
FROM `project.dataset.table`;
```

#### ステップ2: データ品質チェック
```sql
-- NULL値の確認
SELECT
    COUNT(*) as total,
    COUNT(column1) as non_null_column1,
    COUNT(column2) as non_null_column2
FROM `project.dataset.table`;

-- 重複データの確認
SELECT
    id,
    COUNT(*) as duplicates
FROM `project.dataset.table`
GROUP BY id
HAVING COUNT(*) > 1;
```

### 5.2 時系列データ分析

#### 日次トレンド分析
```sql
SELECT
    DATE(timestamp) as date,
    COUNT(*) as events,
    COUNT(DISTINCT user_id) as unique_users
FROM `project.dataset.events`
WHERE timestamp >= '2023-11-01'
GROUP BY DATE(timestamp)
ORDER BY date;
```

#### 週次・月次集計
```sql
-- 週次集計
SELECT
    DATE_TRUNC(DATE(timestamp), WEEK) as week_start,
    SUM(revenue) as weekly_revenue
FROM `project.dataset.sales`
GROUP BY DATE_TRUNC(DATE(timestamp), WEEK)
ORDER BY week_start;

-- 月次集計
SELECT
    FORMAT_DATE('%Y-%m', DATE(timestamp)) as month,
    AVG(session_duration) as avg_duration
FROM `project.dataset.sessions`
GROUP BY FORMAT_DATE('%Y-%m', DATE(timestamp))
ORDER BY month;
```

### 5.3 ユーザー行動分析

#### ユーザーセグメンテーション
```sql
SELECT
    user_segment,
    COUNT(DISTINCT user_id) as users,
    AVG(purchase_amount) as avg_purchase,
    SUM(purchase_amount) as total_revenue
FROM `project.dataset.user_transactions`
WHERE DATE(created_at) >= '2023-12-01'
GROUP BY user_segment
ORDER BY total_revenue DESC;
```

#### コホート分析（基本）
```sql
WITH first_purchase AS (
    SELECT
        user_id,
        MIN(DATE(purchase_date)) as cohort_month
    FROM `project.dataset.purchases`
    GROUP BY user_id
)
SELECT
    cohort_month,
    COUNT(DISTINCT user_id) as cohort_size
FROM first_purchase
GROUP BY cohort_month
ORDER BY cohort_month;
```

## 6. よくあるクエリパターン

### 6.1 データ集計

#### 基本統計
```sql
SELECT
    COUNT(*) as count,
    AVG(amount) as avg_amount,
    STDDEV(amount) as stddev_amount,
    MIN(amount) as min_amount,
    MAX(amount) as max_amount,
    APPROX_QUANTILES(amount, 4) as quartiles
FROM `project.dataset.transactions`;
```

#### パーセンタイル計算
```sql
SELECT
    APPROX_QUANTILES(response_time, 100)[OFFSET(50)] as median,
    APPROX_QUANTILES(response_time, 100)[OFFSET(95)] as p95,
    APPROX_QUANTILES(response_time, 100)[OFFSET(99)] as p99
FROM `project.dataset.api_logs`;
```

### 6.2 文字列操作

#### テキスト分析
```sql
SELECT
    REGEXP_EXTRACT(user_agent, r'Chrome/(\d+)') as chrome_version,
    COUNT(*) as count
FROM `project.dataset.web_logs`
WHERE user_agent CONTAINS 'Chrome'
GROUP BY chrome_version
ORDER BY count DESC;
```

#### URLパース
```sql
SELECT
    REGEXP_EXTRACT(url, r'https?://([^/]+)') as domain,
    REGEXP_EXTRACT(url, r'/([^?]+)') as path,
    COUNT(*) as visits
FROM `project.dataset.page_views`
GROUP BY domain, path;
```

### 6.3 配列・JSON操作

#### ARRAY操作
```sql
SELECT
    user_id,
    tags,
    ARRAY_LENGTH(tags) as tag_count
FROM `project.dataset.users`
WHERE ARRAY_LENGTH(tags) > 0;
```

#### JSON抽出
```sql
SELECT
    JSON_EXTRACT_SCALAR(metadata, '$.category') as category,
    JSON_EXTRACT_SCALAR(metadata, '$.subcategory') as subcategory,
    COUNT(*) as count
FROM `project.dataset.products`
GROUP BY category, subcategory;
```

## 7. トラブルシューティング

### 7.1 よくあるエラーと対処法

#### エラー1: "Access Denied"
**原因**: テーブルへのアクセス権限不足
**対処法**:
1. テーブル名のスペルを確認
2. 管理者にアクセス権限を依頼

#### エラー2: "Table not found"
**原因**: テーブル名の誤記またはデータセット指定ミス
**対処法**:
```sql
-- 正しい形式で指定
SELECT * FROM `project-id.dataset_name.table_name`;
```

#### エラー3: "Query exceeded limit"
**原因**: 処理データ量が制限を超過
**対処法**:
1. LIMIT句を追加
```sql
SELECT * FROM large_table LIMIT 1000;
```

2. WHERE句で絞り込み
```sql
SELECT * FROM large_table
WHERE DATE(timestamp) = '2023-12-01';
```

### 7.2 パフォーマンス問題

#### 遅いクエリの対策

**✅ パーティション列の活用**:
```sql
-- パーティション列を使用
WHERE _PARTITIONDATE = '2023-12-01'
-- または
WHERE DATE(timestamp_column) = '2023-12-01'
```

**✅ 適切なJOIN**:
```sql
-- 小さいテーブルを左に配置
SELECT *
FROM small_table a
JOIN large_table b ON a.id = b.id
WHERE a.status = 'active';
```

## 8. セキュリティとベストプラクティス

### 8.1 データアクセスの注意事項

#### 🔒 機密データの取り扱い
- 個人情報を含むクエリ結果の共有は禁止
- 画面キャプチャ時は機密データを含まないよう注意
- クエリ結果のダウンロードは必要最小限に

#### 🔒 アカウント管理
- ブラウザを共有PCで使用する場合は必ずログアウト
- 認証情報を他者と共有しない

### 8.2 効率的なデータ利用

#### 💡 リソース使用の最適化
- 大量データの処理は営業時間外に実行
- 不要な `SELECT *` は避ける
- 適切なLIMIT句を使用

#### 💡 チーム連携
- よく使用するクエリは文書化して共有
- データ定義や計算ロジックをチーム内で統一

## 9. FAQ

### Q1: クエリの実行時間が長すぎる場合はどうすれば良いですか？

A1: 以下の手順で対応してください：
1. **Explain**機能でスキャン量を確認
2. WHERE句でデータ範囲を絞り込み
3. パーティション列（日付等）を条件に追加
4. 必要な列のみSELECT

### Q2: エラーでクエリが実行できません

A2: エラーメッセージを確認し、以下をチェック：
1. SQLの構文エラー（括弧、引用符の対応等）
2. テーブル名の正確性（プロジェクト.データセット.テーブル）
3. アクセス権限の有無

### Q3: 結果をExcelで使いたい

A3: クエリ実行後の手順：
1. 結果テーブルの右クリック
2. **Export** → **CSV** を選択
3. ダウンロードしたCSVファイルをExcelで開く

### Q4: 定期的に実行したいクエリがある

A4: 手動実行の場合：
1. ブラウザのブックマーク機能でクエリURLを保存
2. クエリをテキストファイルで保存して再利用

自動化が必要な場合は管理者に相談してください。

### Q5: データが更新されているか確認したい

A5: 以下のクエリで最新データの更新時刻を確認：
```sql
SELECT MAX(updated_at) as last_update
FROM `project.dataset.table`;
```

---

このガイドを参考に、効率的にBigQueryをご活用ください。ご不明な点がございましたら、システム管理者までお問い合わせください。