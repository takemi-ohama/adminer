# BigQuery Driver Docker Testing Workflow

## 概要
Adminer BigQueryドライバーの実装・テスト・修正の完全なワークフロー。Dockerコンテナを使用したテスト手順と効率的なデバッグ方法を記録。

## Docker環境構築

### 1. コンテナのビルド・起動
```bash
# コンテナの停止・削除・再ビルド・起動
docker compose down && docker compose up --build -d
```

### 2. コンテナ状態確認
```bash
# コンテナ稼働状況確認
docker ps -a | grep adminer

# コンテナログ確認
docker logs adminer-bigquery-test 2>&1 | tail -10
```

## テスト手順

### 1. 基本的なHTTPテスト
```bash
# ステータスコード確認
docker exec adminer-bigquery-test curl -I "http://localhost/?bigquery=nyle-carmo-analysis&username="

# レスポンス内容確認
docker exec adminer-bigquery-test curl -s "http://localhost/?bigquery=nyle-carmo-analysis&username=" | head -20
```

### 2. 認証テスト（セッション管理）
```bash
# ログイン実行（POSTリクエスト + Cookieファイル保存）
docker exec adminer-bigquery-test bash -c 'curl -s -c /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=" -d "auth[driver]=bigquery&auth[server]=nyle-carmo-analysis&auth[username]=&auth[password]=&auth[db]=" -X POST'

# 認証後のアクセス（Cookieファイル使用）
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db"'
```

### 3. エラー検出テスト
```bash
# Fatal Error / TypeError 検出
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&table=member_info" | grep -E "(Fatal error|TypeError|Error|Exception|Warning)" | head -3'

# 特定のエラーパターン検索
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "URL" | grep "Call to undefined"'
```

### 4. 機能別テストURL

#### データベース（データセット）一覧
```bash
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username="'
```

#### テーブル一覧
```bash
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db"'
```

#### テーブル構造表示
```bash
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&table=member_info"'
```

#### データ選択画面
```bash
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "http://localhost/?bigquery=nyle-carmo-analysis&username=&db=prod_carmo_db&select=member_info"'
```

## 修正・デバッグサイクル

### 1. エラー特定パターン
1. エラー発生時の典型的な検出コマンド:
```bash
curl ... | grep -E "(Fatal error|TypeError|Error|Exception|Warning)" | head -3
```

2. 特定メソッド不足エラーの場合:
```
Fatal error: Uncaught Error: Call to undefined method Adminer\Driver::methodName()
```

3. 特定関数不足エラーの場合:
```
Fatal error: Uncaught Error: Call to undefined function Adminer\functionName()
```

### 2. 修正手順
1. エラーメッセージから不足している関数/メソッドを特定
2. Serena MCPでコードに追加:
   - Driverクラスメソッド: `mcp__serena__insert_after_symbol`
   - グローバル関数: `mcp__serena__insert_after_symbol`
3. コンテナ再ビルド: `docker compose down && docker compose up --build -d`
4. 再テスト実行

### 3. 実装したメソッド・関数一覧

#### Driverクラスメソッド
- `tableHelp($name, $is_view = false)` - ヘルプURL (BigQueryでは null)
- `structuredTypes()` - 構造化データ型 (BigQueryでは [])
- `inheritsFrom($table)` - テーブル継承関係 (BigQueryでは [])
- `inheritedTables($table)` - 継承テーブル一覧 (BigQueryでは [])

#### グローバル関数
- `fk_support($table_status)` - 外部キーサポート (BigQueryでは false)
- `indexes($table, $connection2 = null)` - インデックス一覧 (BigQueryでは [])
- `foreign_keys($table)` - 外部キー定義 (BigQueryでは [])

### 4. 特殊な修正事例

#### Array + null TypeError対応
**問題**: `Unsupported operand types: array + null in table.inc.php:16`

**原因**: fields()関数でprivilegesフィールドが未定義

**修正**: fields()関数の戻り値に`'privileges' => []`を追加

## 成功確認方法

### 1. エラー無し確認
```bash
# エラーが無い場合は何も出力されない
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "URL" | grep -E "(Fatal error|TypeError)" | head -3'
# 出力なし = 成功
```

### 2. コンテンツ確認
```bash
# 期待されるコンテンツが含まれているか確認
docker exec adminer-bigquery-test bash -c 'curl -s -b /tmp/cookies.txt "URL" | grep -i "member_info\|table"'
```

### 3. 最終確認項目
- ✅ ログイン成功
- ✅ データベース（データセット）一覧表示
- ✅ テーブル一覧表示  
- ✅ テーブル構造表示
- ✅ データ選択画面表示
- ✅ Fatal Error なし

## 効率化のポイント

1. **セッション管理**: 毎回ログインし直さず、cookieファイルを再利用
2. **エラー検出**: 特定のエラーパターンをgrepで素早く検出
3. **段階的テスト**: 基本機能から順番にテストして問題箇所を特定
4. **コンテナ内実行**: `docker exec`でネットワーク問題を回避

この手順により、AdminerのBigQueryドライバー開発において効率的なテスト・デバッグが可能になる。