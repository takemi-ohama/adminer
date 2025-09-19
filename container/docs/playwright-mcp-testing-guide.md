# Playwright MCP テスト手順ガイド

AdminerのBigQueryドライバーをPlaywright MCPで検証する詳細手順を説明します。

## 📋 概要

Playwright MCPは、Claude Code環境からブラウザ操作を自動化してE2Eテストを実行するツールです。
DooD (Docker-outside-of-Docker) 環境で、コンテナ間通信を活用したテストが可能です。

## 🏗️ 環境構成

### DooD環境でのテスト構成
```
Claude Code環境 (adminer-dev-1)
├── Playwright MCP
└── 対象: http://adminer-bigquery-test (Webコンテナ)
    ├── Port: 80 (内部通信)
    ├── Network: adminer-net
    └── BigQuery認証: /etc/google_credentials.json
```

## 🚀 基本テスト手順

### 1. 事前準備

#### コンテナ状態確認
```bash
# Webコンテナの稼働確認
docker ps | grep adminer-bigquery-test

# コンテナが停止している場合
cd container/web
docker compose up -d
```

### 2. Playwright MCP 基本操作

#### 2.1 ナビゲーション（ページ遷移）
```javascript
// 基本的なページアクセス
browser_navigate({
  "url": "http://adminer-bigquery-test",
  "expectation": {
    "includeSnapshot": true,
    "snapshotOptions": {"format": "aria", "maxLength": 2000}
  }
})
```

#### 2.2 要素のクリック操作
```javascript
// ログインボタンのクリック
browser_click({
  "selectors": [{"css": "input[value='Login']"}],
  "expectation": {
    "includeSnapshot": true,
    "snapshotOptions": {"format": "aria", "maxLength": 2000}
  }
})
```

#### 2.3 待機処理（重要）
```javascript
// BigQuery認証処理の待機
browser_wait_for({
  "time": 3,
  "expectation": {
    "includeSnapshot": true,
    "snapshotOptions": {"format": "aria", "maxLength": 2000}
  }
})
```

#### 2.4 ページ状態の確認
```javascript
// 現在のページ状況を確認
browser_snapshot({
  "expectation": {
    "includeSnapshot": true,
    "snapshotOptions": {"format": "aria", "maxLength": 2000}
  }
})
```

### 3. 完全テストフロー例

#### Adminer BigQueryドライバー検証フロー
```bash
# 手順1: ログインページアクセス
browser_navigate → http://adminer-bigquery-test

# 手順2: 自動認証（BigQuery設定済み）
browser_click → Login button

# 手順3: 認証処理待機（必須）
browser_wait_for → 3秒

# 手順4: データセット表示確認
browser_snapshot → プロジェクト一覧

# 手順5: データベース選択
browser_click → prod_carmo_db

# 手順6: テーブル一覧確認
browser_snapshot → テーブル一覧（181件）

# 手順7: テーブル詳細表示
browser_click → member_info

# 手順8: テーブル構造確認
browser_snapshot → カラム情報

# 手順9: データ選択機能確認
browser_click → "Select data"
```

## 🎯 テスト対象機能

### 基本機能テスト
- [x] **ログイン処理**: BigQueryサービスアカウント認証
- [x] **プロジェクト表示**: GCPプロジェクト接続確認
- [x] **データセット一覧**: BigQuery Dataset表示
- [x] **テーブル一覧**: 181個のテーブル・ビュー表示
- [x] **テーブル構造**: カラム情報、データ型表示

### UI機能テスト
- [x] **パンくずナビゲーション**: Google BigQuery → Project → Dataset
- [x] **リンク機能**: Show structure, Select data, Alter table
- [x] **エラーハンドリング**: 権限制限テーブルのメッセージ表示

## ⚡ 重要な技術ポイント

### 1. セレクター戦略

#### 問題: 複数要素マッチエラー
```
Error: strict mode violation: resolved to 2 elements
```

#### 解決策: 具体的セレクターの使用
```javascript
// ❌ 曖昧なセレクター
{"text": "member_info"}

// ✅ 具体的IDセレクター
{"css": "#Table-member_info"}

// ✅ 属性指定セレクター
{"css": "a[href*='select=member_info']"}
```

### 2. 非同期処理対応

#### 問題: ナビゲーションタイムアウト
```
TimeoutError: Timeout 5000ms exceeded
```

#### 解決策: 適切な待機処理
```javascript
// BigQuery認証後は必ず待機
browser_wait_for({"time": 3})

// ページ遷移後の状態確認
browser_snapshot()
```

### 3. DooD環境での接続

#### 接続形式
```bash
# ❌ localhost使用
http://localhost:8080

# ✅ コンテナ名使用
http://adminer-bigquery-test
```

## 🔍 エラーパターンと対処法

### 1. 認証関連エラー

#### 症状
```
Unable to select the table.
```

#### 原因と対処
- **原因**: BigQueryテーブルへのアクセス権限不足
- **対処**: 別のテーブルで検証、権限確認

### 2. ネットワークエラー

#### 症状
```
Failed to connect to adminer-bigquery-test
```

#### 対処手順
```bash
# 1. コンテナ稼働確認
docker ps | grep adminer-bigquery-test

# 2. ネットワーク確認
docker network ls | grep adminer-net

# 3. コンテナ再起動
cd container/web
docker compose restart
```

### 3. セレクターエラー

#### 症状
```
locator.click: Error: strict mode violation
```

#### 対処手順
```javascript
// 1. より具体的なセレクター使用
{"css": "#specific-id"}

// 2. 属性指定でユニーク化
{"css": "a[title='Show structure']"}

// 3. 階層指定で絞り込み
{"css": "table tbody tr td a"}
```

## 📊 テスト結果の評価

### 成功判定基準
- [ ] ログインページが正常表示される
- [ ] BigQuery認証が自動完了する
- [ ] プロジェクト/データセット一覧が表示される
- [ ] テーブル一覧（181件）が表示される
- [ ] テーブル構造詳細が表示される
- [ ] エラーメッセージが適切に表示される

### パフォーマンス指標
- **ログイン応答時間**: < 5秒
- **データセット読み込み**: < 3秒
- **テーブル一覧表示**: < 5秒
- **テーブル構造表示**: < 3秒

## 🛠️ デバッグ手法

### 1. 詳細ログ確認
```javascript
// 詳細なスナップショット取得
browser_snapshot({
  "expectation": {
    "includeSnapshot": true,
    "snapshotOptions": {
      "format": "aria",
      "maxLength": 5000  // より詳細な情報
    }
  }
})
```

### 2. 段階的検証
```javascript
// 各ステップでの状態確認
browser_navigate → browser_snapshot  // ページ表示確認
browser_click → browser_wait_for → browser_snapshot  // クリック結果確認
```

### 3. HTML構造の確認
```javascript
// HTMLソース確認（必要に応じて）
browser_inspect_html({
  "selectors": [{"css": "body"}],
  "expectation": {"includeSnapshot": false}
})
```

## 📝 テストレポート生成

### テスト実行記録
```markdown
## テスト実行日時
2025-09-19 実行

## 検証環境
- Claude Code環境: adminer-dev-1
- テスト対象: adminer-bigquery-test
- BigQueryプロジェクト: nyle-carmo-analysis

## テスト結果
✅ ログイン処理: 成功
✅ データセット表示: 成功
✅ テーブル一覧表示: 成功（181件）
✅ テーブル構造表示: 成功
⚠️ データ選択機能: 権限制限により一部制限

## 発見事項
- BigQueryの権限設定による一部テーブルアクセス制限
- 認証処理の非同期性による適切な待機時間の必要性
```

## 🔄 継続的テスト実行

### 開発ワークフローへの統合
```bash
# 1. コード修正後の迅速検証
cd container/web && docker compose restart

# 2. Playwright MCPでのE2Eテスト
# Claude Code環境でPlaywright MCP実行

# 3. 結果確認と次のイテレーション
```

### 自動化の可能性
```bash
# 将来的なスクリプト化案
./test-playwright-mcp.sh
├── Pre-check: コンテナ稼働確認
├── Execute: Playwright MCP テスト実行
├── Report: 結果レポート生成
└── Cleanup: 必要に応じてクリーンアップ
```

---

このガイドに従って、Playwright MCPを活用した効果的なE2Eテストを実行し、Adminer BigQueryドライバーの品質を継続的に保証してください。