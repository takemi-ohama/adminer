# Playwright MCP テスト手順とベストプラクティス

## 概要
DooD環境でのAdminer BigQueryドライバーのPlaywright MCPを使用したE2Eテスト手順

## ファイル構成（更新済み）
- 設定ファイル: `container/dev/playwright-mcp.config.json`
- テストガイド: `container/docs/playwright-mcp-testing-guide.md`  
- 記録保存: `.serena/memories/playwright_mcp_testing_workflow.md`

## 基本テストフロー

### 1. 事前確認
- Webコンテナ `adminer-bigquery-test` の稼働状況確認
- DooD環境での接続形式: `http://[コンテナ名]`

### 2. Playwright MCP テスト手順
```
1. browser_navigate - ログインページアクセス
2. browser_click - 認証ボタンクリック  
3. browser_wait_for - ページ遷移待機（重要）
4. browser_snapshot - 状態確認
5. browser_click - データセット/テーブル選択
```

### 3. 重要な技術的ポイント

#### ナビゲーション待機
- `browser_click`後に必ず`browser_wait_for`で3秒待機
- BigQueryの認証プロセスに時間がかかるため

#### セレクター戦略
- 複数マッチ回避のため具体的CSS セレクターを使用
- `#Table-member_info` のようなID指定を優先
- パンくずナビゲーション活用

#### エラーハンドリング
- タイムアウト発生時は待機してリトライ
- 権限エラー（"Unable to select the table"）の考慮

### 4. テスト対象機能
- BigQuery接続・認証
- データセット一覧表示
- テーブル一覧表示（181件）
- テーブル構造表示
- データ選択インターフェース

### 5. 実際の検証結果（2025-09-19実施）
✅ BigQuery接続・認証プロセス: 成功
✅ データセット表示: 成功（nyle-carmo-analysis プロジェクト）
✅ テーブル一覧表示: 成功（prod_carmo_db データセットで181件）
✅ テーブル構造表示: 成功（member_info テーブルの詳細構造確認）
✅ Adminer UIナビゲーション: 成功

### 6. 制限事項
- BigQueryの権限設定による一部テーブルアクセス制限
- 非同期処理によるナビゲーション遅延
- 一部テーブルで「Unable to select the table」エラー

### 7. ドキュメント参照
詳細な手順については `container/docs/playwright-mcp-testing-guide.md` を参照