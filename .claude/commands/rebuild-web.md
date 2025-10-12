---
description: webコンテナを停止・再ビルド・起動する（テスト実行なし）
allowed-tools: Bash(*)
---

BigQuery Admin用webコンテナの再ビルドを実行します。

## 実行内容
コード変更後の反映に必要な一連のコンテナ操作を実行します：

1. **既存コンテナ停止**: 現在実行中のwebコンテナを停止・削除
2. **イメージ再ビルド**: 最新のコードでDockerイメージを再構築
3. **コンテナ起動**: 新しいイメージでコンテナを起動
4. **起動確認**: コンテナの正常起動を確認

## 使用場面
- BigQueryドライバーコード修正後
- 設定ファイル（compose.yml、Dockerfile等）変更後
- プラグインファイル更新後
- 依存関係更新後

## 実行コマンド
```bash
cd devtools/web
docker compose down
docker compose up --build -d
docker ps | grep adminer-bigquery-test
```

⚠️ **重要**: Dockerコンテナ内のコードは初回ビルド時にコピーされるため、ホスト側のコード変更は再ビルドなしでは反映されません。