# Adminer BigQuery プロダクション環境

このディレクトリには、ghcr.io/takemi-ohama/adminer-bigquery:latest イメージを使用したプロダクション環境の設定が含まれています。

## 使用方法

### 1. 環境設定

`.env.example` をコピーして `.env` ファイルを作成し、適切な値を設定してください：

```bash
cp .env.example .env
```

### 2. Google Cloud認証の準備

BigQueryアクセス用のサービスアカウントキーファイルを準備し、`compose.yml` の volumes セクションで適切なパスを設定してください。

### 3. 起動

```bash
docker compose up -d
```

### 4. アクセス

ブラウザで `http://localhost:8080` にアクセスしてください。

## 設定ファイル

- `compose.yml`: Docker Compose設定ファイル
- `.env.example`: 環境変数の例
- `README.md`: このファイル

## セキュリティ注意事項

- サービスアカウントキーファイルは適切な権限で管理してください
- プロダクション環境では必要最小限の権限を設定してください
- ネットワーク設定やポート公開について適切なセキュリティ対策を実施してください