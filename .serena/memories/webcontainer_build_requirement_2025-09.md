# Webコンテナビルド要件 (2025-09-20)

## 重要な開発プラクティス

### コード修正後のビルド必要性
- **必須**: コードを修正した後は必ずwebコンテナの再ビルドが必要
- **理由**: Dockerコンテナ内のコードは初回ビルド時にコピーされるため、ホスト側の変更は自動反映されない
- **対象ファイル**:
  - plugins/drivers/bigquery.php
  - plugins/login-bigquery.php
  - container/web/index.php
  - その他のPHPファイル

### 適切なビルド手順
```bash
# 1. コンテナ停止・削除
docker compose down

# 2. 再ビルド・起動
docker compose up --build -d

# 3. 動作確認
curl -I http://adminer-bigquery-test
```

### 修正が反映されない場合の対処
1. コンテナの完全再ビルド実行
2. Dockerキャッシュクリア（必要に応じて）
3. ブラウザキャッシュのクリア

### 今回の実例
- login-bigquery.phpでhiddenフィールドのデフォルト値を設定
- 再ビルド後、username/passwordフィールドに正しい値が設定された
- 修正前: `value=""` 修正後: `value="bigquery-service-account"`

### 開発効率のために
- コード修正 → 即座に再ビルド の習慣化
- テスト前の必須チェックポイント