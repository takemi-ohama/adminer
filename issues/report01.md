
# 目的（MVP）

* Adminer の「ドライバプラグイン」として **BigQuery に接続**し、最低限の操作（クエリ実行・データセット/テーブル/スキーマ閲覧・結果ページング）を提供する
* まずは **読み取り中心（READ-ONLY）** で安定化 → その後、INSERT/UPDATE/DELETE などの DML とインポート/エクスポートを段階追加

根拠：

* Adminer は公式に「新しいドライバの追加/改善手順」と「拡張/プラグイン API」を提供しています。([Adminer][1])
* 既存の非RDB（Elasticsearch、MongoDB、ClickHouse、Firebird、SimpleDB など）は **Driver Plugin** として配布されています（同等のやり方で BigQuery も可能）。([Adminer][2])

---

# 全体像（技術選択）

* **言語/ランタイム**：PHP 8.x（Adminer ソースでの開発要件を満たす）
* **接続ライブラリ**：Google 公式の PHP クライアント（`google/cloud-bigquery` または軽量の `google/cloud-bigquery-connection`）を Composer で導入

  * `composer require google/cloud-bigquery` もしくは `composer require google/cloud-bigquery-connection`（後者はより薄い接続層）。([Google Cloud][3])
* **認証**：サービスアカウント JSON（`GOOGLE_APPLICATION_CREDENTIALS`）、もしくは JSON 文字列を安全に配置
* **SQL 方言**：標準 SQL（Legacy は非対応でOK）
* **実行モデル**：BigQuery の **Job** による非同期実行＋結果ページング（Adminer の UI は同期見えで良いが、内部はポーリング/即時取得）([Google Cloud][4])

---

# Adminer 側の拡張ポイントと開発手順

## 1) Driver の骨格

* Adminer 公式の「Drivers」手順に従い、**不足メソッドを洗い出して埋める**：

  1. Git からソース取得
  2. `php compile.php $driver`（例：`php compile.php bigquery`）で未実装関数を確認
  3. 参照実装は `adminer/drivers/mysql.inc.php` のシグネチャを踏襲
  4. 新機能は `support()` に宣言
  5. `adminer/index.php` で開発版を確認
  6. 完成したら PR できる構成（GPL+Apache）
     ※ 我々はまず **プラグインとして配布**（`plugins/`）し、成熟後に本体 Driver への取り込みを目指す二段構え。([Adminer][1])

## 2) 「拡張」or「プラグイン」どちらで始める？

* **最初は Driver Plugin** で実装（既存の `elastic` / `mongo` / `clickhouse` などと同様）。Adminer の **拡張 API**（`adminer_object()`）は主に UI/動作上書き用。**DB 実装は Driver Plugin で行うのが適切**。([Adminer][5])

## 3) ログイン画面への統合

* 既存の「login-servers」プラグインは、**driver 値**を隠しフィールドに設定可能（MySQL は `'server'`、他は `'pgsql'|'sqlite'|...` のようにドライバ名文字列をセット）。BigQuery でも `'bigquery'` を割り当てられるようにする。([GitHub][6])

---

# BigQuery Driver Plugin 設計（MVP仕様）

## ドライバ名・配置

* ファイル：`plugins/drivers/bigquery.php`（便宜上。実プロジェクトでは `plugins/` 直下の既定位置・命名に合わせる）
* ドライバ識別子：`'bigquery'`（login 画面の driver セレクトで使用）

## Adminer Driver インターフェイスへのマッピング（代表）

> 実メソッド名は `mysql.inc.php` のシグネチャを踏襲。ここでは概念で記述。([Adminer][1])

* `connect($server, $username, $password)`

  * **\$server**＝GCP プロジェクトID もしくは `"projectId[:location]"`
  * 認証は `GOOGLE_APPLICATION_CREDENTIALS`（推奨）。**認証失敗**は Adminer 標準のエラーUIへ伝播。([Google Cloud][3])
* `support($feature)`

  * `view`, `materializedview`, `schema`, `partitioning`, `sql` → **true**
  * `foreignkeys`, `indexes`, `processlist`, `kill` → **false**（BigQuery 非該当）
  * `limit`, `offset` → **true**（SQL 変換で対応）
* `databases()`

  * **BigQuery の「データセット」を Adminer の database として見せる**
  * API: listDatasets
* `schemas()`

  * BigQuery は **schema = なし** だが、Adminer 的には **データセット ≒ スキーマ** として整合（`databases()`と二重になるため、UI整合は実際の Driver 実装に合わせて調整）
* `tables($database)` / `tableStatus($database)`

  * listTables（views/ materialized views 含む）
* `fields($table)`

  * テーブルスキーマ（カラム名/型/NULL許可/モード/説明）を返却
* `select($query, $limit, $offset)`

  * `SELECT ... LIMIT {limit} OFFSET {offset}`（標準SQL）で **query job** を実行
  * 実行は同期 `runQuery` し、結果ページング
* `query($sql)`

  * 任意 SQL を Job 化して実行。**DML/DQL 混在**を許容（MVPでは DML ガードを入れて READ-ONLY 化も可）
* `begin`, `commit`, `rollback`

  * BigQuery はトランザクションの概念が限定的。**全て非対応**で良い（`support('transaction') = false`）
* `error()` / `lastInsertId()`

  * BigQuery には AUTO\_INCREMENT が無いため `lastInsertId()` は **未対応**
* `explain($query)`

  * **dryRun** で統計を返すだけでも有用（費用見積り/スキャン量）([Google Cloud][4])

> 実際には Adminer の各 Driver に準じた **Result ラッパ** 実装（`Result` クラス相当）や、型/エスケープ処理、ID 文字列（`project.dataset.table`）の **エスケープ/アンエスケープ** も必要です。`mysql.inc.php` の関数群の“署名”を流用し、内部を BigQuery API 呼び出しに差し替えます。([Adminer][1])

---

# 認証と接続 UX

## 優先：サービスアカウント JSON（推奨）

* サーバに `GOOGLE_APPLICATION_CREDENTIALS=/path/to/key.json` を設定
* Adminer のログインフォームは：

  * **System/Driver**: `bigquery`
  * **Server**: `project-id`（必要なら `project-id:location`）
  * **Username/Password**: 空でOK（UIは埋めつぶし）
* BigQuery 権限は通常 **`roles/bigquery.user`** 以上（閲覧主体なら `viewer` 相当でも足りる）。インサート/DDL まで想定するなら `bigquery.dataEditor` or `bigquery.admin`。([Google Cloud][7])

## 代替：JSON 文字列/環境変数

* 管理都合により **Base64 化した JSON** を環境変数で渡し、起動時に一時ファイル化する戦略も可（セキュリティに注意）

> JDBC/ODBC を PHP から叩く選択肢もあるが、Adminer Driver 直実装では **公式 PHP クライアントの利用が素直**。JDBC/ODBC は他ツール連携向け。([Google Cloud][8])

---

# データモデル対応表（Adminer ⇄ BigQuery）

| Adminer 概念   | BigQuery 対応                      | 備考                                                                                            |
| ------------ | -------------------------------- | --------------------------------------------------------------------------------------------- |
| Server       | GCP Project（+ 任意 location）       | `Server` フィールドを projectId と見なす                                                                |
| Database     | **Dataset**                      | Adminer の DB 一覧＝BigQuery datasets                                                             |
| Schema       | （なし）/Dataset                     | Adminer API 要件に合わせ、dataset を schema とも扱う                                                      |
| Table        | Table / View / Materialized View | 種別は `tableStatus()` で区別                                                                       |
| Column Types | BigQuery 型                       | `STRING/INT64/FLOAT64/BOOL/NUMERIC/BIGNUMERIC/DATETIME/DATE/TIME/TIMESTAMP/JSON/GEOGRAPHY` など |
| Index/PK/FK  | 概念非推奨                            | `support()` で `false` 返却                                                                      |
| Transaction  | なし                               | `support('transaction') = false`                                                              |
| Explain      | `dryRun`                         | スキャンバイトや推定情報を返す ([Google Cloud][4])                                                           |

---

# 実装タスク分割（Claude Code 向け）

## 0. 開発セットアップ

* リポジトリ準備：Adminer のサブモジュール/ソース取得
* Composer：`google/cloud-bigquery[-connection]` を追加
* `plugins/drivers/bigquery.php` の **骨格** ファイル生成
* ローカル起動：`php -S localhost:8080 -t adminer/` で `adminer/index.php` を開発運用
  根拠（ソース構成・開発手順）：([GitHub][9])

## 1. コア：接続・サポート宣言

* `connect()`, `support()` を先に実装
* ログインフォームの driver に `bigquery` を選べる導線（`login-servers` プラグイン併用で固定化も可）([GitHub][6])

## 2. メタデータ取得

* `databases()` → listDatasets
* `tables()` / `tableStatus()` → dataset 内の tables/views
* `fields()` → schema 取得

## 3. クエリ実行（SELECT）

* `select()` / `query()`：標準SQLで `LIMIT/OFFSET` 対応
* ジョブ完了待ち・エラー整形・結果を Adminer の `Result` 互換で返す

## 4. UI 体験の最適化

* 行数ページング・カラム型表示・TIMESTAMP/DATE の表示整形
* `explain()`＝`dryRun`（スキャン見積りを表示）

## 5. 安全ガード

* MVP は **READ-ONLY モード**（`INSERT/UPDATE/DELETE/DDL` は拒否 or Warning）
* 後続で DML を段階解禁（`support('insert')` 等）

## 6. 配布形態

* **プラグイン単体配布**：`adminer-plugins.php` 経由でロードできるように
* あるいは **Docker Hub の adminer 公式イメージ**は「全プラグイン同梱」なので、`ADMINER_PLUGINS` 経由ロードも選択肢（実運用時の導線）([ECR パブリックギャラリー][10])

---

# サンプル：`adminer-plugins.php`（MVP想定）

```php
<?php
// adminer-plugins.php
return [
  // BigQuery ドライバ（自作）
  new AdminerBigQueryDriver([
    'defaultProject' => getenv('BQ_PROJECT') ?: 'my-project',
    'location' => getenv('BQ_LOCATION') ?: 'US',
    'readOnly' => true, // MVPは読み取り専用
  ]),

  // 任意：ログイン先固定化（driver='bigquery' をセット）
  new AdminerLoginServers([
    'BigQuery (prod)' => ['server' => getenv('BQ_PROJECT'), 'driver' => 'bigquery'],
  ]),
];
```

> `AdminerBigQueryDriver` は `plugins/drivers/bigquery.php` 内で定義。`AdminerLoginServers` の driver 値に `'bigquery'` を指定する点が肝。([GitHub][6])

---

# セキュリティと権限

* **最小権限**で開始：閲覧主体なら `roles/bigquery.dataViewer`、クエリ実行に `roles/bigquery.user`。編集系を解禁する場合のみ `dataEditor` 以上を検討。([Google Cloud][7])
* 認証ファイルの所在は **アプリ外**（`/etc/…` 等）に置き、**権限600**、Web 経由で配布しない

---

# 非対応/制約（MVP）

* **外部キー/インデックス/プロセス一覧/KILL**：非対応（BigQuery の性質上）
* **トランザクション**：非対応
* **IMPORT/EXPORT**：後続（CSV→GCS→LOAD JOB / EXPORT JOB などのUI導線を別タブで用意）

---

# 将来拡張（段階リリース）

1. **DML 対応**：`INSERT/UPDATE/DELETE` と `MERGE`（クォータ注意）
2. **LOAD/EXPORT ウィザード**：GCS パス・schema 推定・ジョブ監視
3. **ジョブ履歴/課金見積り可視化**：`INFORMATION_SCHEMA.JOBS*` 参照
4. **スキャン削減アドバイス**：`EXPLAIN`/dryRun 結果からのヒント
5. **パーティション/クラスタ列 UI**：テーブル作成/変更画面のサポート

---

# 動作確認チェックリスト

* [ ] ログイン画面に `bigquery` driver が現れ、プロジェクトIDのみで接続できる
* [ ] データセット一覧→テーブル一覧→カラム定義が表示される
* [ ] 任意の SELECT が実行でき、`LIMIT/OFFSET` でページングできる
* [ ] `EXPLAIN`（dryRun）のスキャンバイトを表示できる
* [ ] DML はブロック（MVP方針）

---

# 参考ドキュメント（実装に直結する部分）

* **Adminer ドライバ実装手順（compile / support / mysql.inc.php を参照）**。([Adminer][1])
* **Adminer の拡張/プラグイン API**（`adminer_object`、各オーバーライド可能メソッド一覧）。([Adminer][5])
* **公式 Driver Plugins 一覧**（BigQuery もこの方式に倣う）。([Adminer][2])
* **BigQuery PHP クライアント（Composer 導入）**。([Google Cloud][3])
* **BigQuery の Job/クエリ実行・dryRun の概念**。([Google Cloud][4])
* **BigQuery 権限ロール（最小権限設計の参考）**。([Google Cloud][7])
* **Adminer 公式サイト（対象バージョン/対応 DB/ダウンロード）**。([Adminer][11])

---

# Claude Code への発注テンプレ（コピペ用）

**タイトル**：Adminer BigQuery Driver プラグインのMVP実装

**ゴール**：
Adminer で `driver=bigquery` を選ぶと、GCP プロジェクトID＋サービスアカウント認証で BigQuery に接続し、

* データセット/テーブル/カラムの閲覧
* SELECT クエリ実行（LIMIT/OFFSET ページング）
* EXPLAIN 相当（dryRun のスキャンバイト表示）
  ができる **READ-ONLY** ドライバプラグインを完成させる。

**前提/依存**：

* PHP 8.x、Composer 利用可能
* `google/cloud-bigquery[-connection]` を使用
* 認証：`GOOGLE_APPLICATION_CREDENTIALS` で JSON キーファイルを指定

**タスク**：

1. 開発環境準備：Adminer ソース取得、ローカルサーバ起動、Composer 依存追加
2. `plugins/drivers/bigquery.php` を新規作成：

   * `connect() / support()` と必要 Result ラッパ
   * `databases()/tables()/fields()` の実装
   * `select()/query()` の SELECT 実行（LIMIT/OFFSET対応）
   * `explain()` の dryRun 対応
   * `support()` のフラグを BigQuery 実態に合わせて定義
3. `adminer-plugins.php` で Driver をロードし、`login-servers` プラグインで driver='bigquery' をセット
4. 動作確認（チェックリストに従う）
5. README（使い方・必要権限・既知の制約）を作成

**完了条件（MVP）**：

* ログイン→データセット/テーブル/カラムのブラウズ→SELECT 実行/ページング→dryRun 表示が手元で再現できる
* DML は拒否（READ-ONLY）

---

必要なら、`plugins/drivers/bigquery.php` の\*\*最小骨格（クラス定義＋メソッド空実装）\*\*までこちらで用意します。どう進めるか言ってください。

[1]: https://www.adminer.org/en/drivers/ "Adminer - Drivers"
[2]: https://www.adminer.org/en/plugins/?utm_source=chatgpt.com "Adminer - Plugins"
[3]: https://cloud.google.com/php/docs/reference/cloud-bigquery-connection/latest?utm_source=chatgpt.com "PHP Client Libraries"
[4]: https://cloud.google.com/bigquery/docs/admin-intro?utm_source=chatgpt.com "Introduction to BigQuery administration"
[5]: https://www.adminer.org/en/extension/ "Adminer - Extensions"
[6]: https://raw.githubusercontent.com/vrana/adminer/master/plugins/login-servers.php?utm_source=chatgpt.com "login-servers - GitHub"
[7]: https://cloud.google.com/bigquery/docs/access-control?utm_source=chatgpt.com "BigQuery IAM roles and permissions"
[8]: https://cloud.google.com/bigquery/docs/reference/odbc-jdbc-drivers?utm_source=chatgpt.com "ODBC and JDBC drivers for BigQuery"
[9]: https://github.com/vrana/adminer "GitHub - vrana/adminer: Database management in a single PHP file"
[10]: https://gallery.ecr.aws/docker/library/adminer?utm_source=chatgpt.com "Docker/library/adminer - Amazon ECR Public Gallery"
[11]: https://www.adminer.org/en/?utm_source=chatgpt.com "Adminer - Database management in a single PHP file"
