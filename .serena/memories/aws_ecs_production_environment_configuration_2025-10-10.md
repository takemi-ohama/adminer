# AWS ECS本番環境構成情報

## 基本情報
- **リージョン**: ap-northeast-1
- **クラスター**: development-ecs
- **サービス**: dev-adminer-gbq-service
- **稼働状況**: ACTIVE (desired: 1, running: 1)

## サービス構成
### ECSサービス詳細
- **サービスARN**: `arn:aws:ecs:ap-northeast-1:422746423551:service/development-ecs/dev-adminer-gbq-service`
- **起動タイプ**: FARGATE
- **プラットフォームバージョン**: LATEST (1.4.0)
- **CPU**: 1024, **メモリ**: 2048MB
- **ネットワーク**: awsvpc, プライベートサブネット

### コンテナ構成
- **コンテナ名**: app
- **イメージ**: `ghcr.io/takemi-ohama/adminer-bigquery:master-80ffe67`
- **ポート**: 80 (コンテナ) → 80 (ホスト)

### ロードバランサー
- **ターゲットグループARN**: `arn:aws:elasticloadbalancing:ap-northeast-1:422746423551:targetgroup/dev-adminer-gbq-target/50f202f64a487258`
- **ヘルスチェック猶予期間**: 60秒

## 環境変数
### OAuth2設定
- `GOOGLE_OAUTH2_ENABLE`: true
- `GOOGLE_OAUTH2_CLIENT_ID`: 128266455669-qsqdnpuifgrek683fjhgd1abi9qfkdca.apps.googleusercontent.com
- `GOOGLE_OAUTH2_REDIRECT_URL`: https://adminer-g.dev.car-mo.jp/?oauth2=callback
- `GOOGLE_OAUTH2_COOKIE_DOMAIN`: adminer-g.dev.car-mo.jp
- `GOOGLE_OAUTH2_COOKIE_NAME`: oauth2_token
- `GOOGLE_OAUTH2_COOKIE_EXPIRE`: 3600
- `GOOGLE_OAUTH2_COOKIE_SECRET`: adminer-secret-g

### BigQuery設定
- `GOOGLE_CLOUD_PROJECT`: nyle-carmo-analysis
- `GOOGLE_APPLICATION_CREDENTIALS`: /tmp/service-account.json

### Adminer設定
- `ADMINER_DESIGN`: nette
- `ADMINER_PLUGINS`: tables-filter dump-zip

## ログ設定
### CloudWatch Logs
- **ログドライバー**: awslogs
- **ログググループ**: `dev-adminer-gbq-devadminergbqdefdevadminergbqappLogGroup4878EE12-8XTEaUGEujfZ`
- **ログリージョン**: ap-northeast-1
- **ログストリームプレフィックス**: dev-adminer-gbq-container-app

### 最新ログストリーム
- `dev-adminer-gbq-container-app/app/4f95ee9341214514b3c0dc585a8386aa` (現在稼働中)

## AWS CLI操作コマンド

### サービス状況確認
```bash
aws ecs describe-services --cluster development-ecs --services dev-adminer-gbq-service --region ap-northeast-1
```

### ログ取得
```bash
# 最新ログストリーム確認
aws logs describe-log-streams --log-group-name "dev-adminer-gbq-devadminergbqdefdevadminergbqappLogGroup4878EE12-8XTEaUGEujfZ" --region ap-northeast-1 --order-by LastEventTime --descending --max-items 5

# ログ内容取得
aws logs get-log-events --log-group-name "dev-adminer-gbq-devadminergbqdefdevadminergbqappLogGroup4878EE12-8XTEaUGEujfZ" --log-stream-name "dev-adminer-gbq-container-app/app/4f95ee9341214514b3c0dc585a8386aa" --region ap-northeast-1
```

### タスク確認
```bash
# 稼働中タスク一覧
aws ecs list-tasks --cluster development-ecs --service-name dev-adminer-gbq-service --region ap-northeast-1

# タスク詳細
aws ecs describe-tasks --cluster development-ecs --tasks [TASK-ARN] --region ap-northeast-1
```

### サービス更新（デプロイ）
```bash
# 新しいタスク定義でサービス更新
aws ecs update-service --cluster development-ecs --service dev-adminer-gbq-service --task-definition [NEW-TASK-DEFINITION-ARN] --region ap-northeast-1

# 強制デプロイ（同じタスク定義でコンテナ再起動）
aws ecs update-service --cluster development-ecs --service dev-adminer-gbq-service --force-new-deployment --region ap-northeast-1
```

## ネットワーク構成
- **セキュリティグループ**: sg-0ab24e2d8fe967682
- **サブネット**:
  - subnet-01475de3064a44ca9
  - subnet-0ab6f6bcfac7c33e2
- **パブリックIP割り当て**: DISABLED（プライベートサブネット）

## アクセス情報
- **本番URL**: https://adminer-g.dev.car-mo.jp/
- **OAuth2コールバックURL**: https://adminer-g.dev.car-mo.jp/?oauth2=callback

## デプロイ状況（2025-10-10時点）
- **最新デプロイ**: 2025-10-10T09:58:14.031000+00:00
- **タスク定義リビジョン**: 17
- **デプロイ状態**: COMPLETED
- **ロールアウト状態**: COMPLETED

## 重要な注意事項
1. **OAuth2設定**: 本番環境では`?oauth2=callback`形式のクエリパラメータを使用
2. **ログ監視**: CloudWatch Logsで複数のログググループが存在（世代管理）
3. **デプロイ**: 新しいイメージはGitHub Container Registryから自動デプロイ
4. **ネットワーク**: プライベートサブネットで稼働、ALB経由でアクセス