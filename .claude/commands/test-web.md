---
description: BigQuery webコンテナをビルド・再起動してPlaywright MCPテストを実行
allowed-tools: Bash(*), mcp__playwright__*
---

BigQuery Admin用webコンテナの完全テストフローを実行します。

## 実行内容
1. **webコンテナ停止**: 既存コンテナのクリーンアップ
2. **webコンテナ再ビルド・起動**: 最新コードでのコンテナ作成
3. **コンテナ状況確認**: 起動状況の検証
4. **Playwright MCPテスト**: 実ブラウザでの機能テスト実行

## テスト対象機能
- BigQuery OAuth2認証プロセス
- データセット一覧表示
- テーブル一覧・構造表示
- SELECT文実行・データ表示
- ページング機能
- UI操作・ナビゲーション

## 実行手順

### 1. webコンテナの停止・再ビルド
```bash
cd devtools/web
docker compose down
docker compose up --build -d
```

### 2. コンテナ起動確認
```bash
docker ps | grep adminer-bigquery-test
```

* curlで接続する場合は `curl -I http://adminer-bigquery-test` でテスト。
* Docker outside of Docker環境(DooD)であるため、localhost:8080では接続できないことに注意

### 3. Playwright MCPテスト実行
- テスト対象: http://adminer-bigquery-test
- BigQuery認証画面への接続
- ログイン実行
- データセット選択（dataset_test）
- テーブルデータ表示（board_kpi）
- 基本機能の動作確認
- 各動作でFatalやError,Invalidなどの警告が画面に発生していないことを確認

このコマンドにより、BigQueryドライバープラグインの包括的な動作検証が自動実行されます。
