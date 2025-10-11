# Adminer BigQuery CDK Stack

このディレクトリには、Adminer BigQueryサービスのAWS CDKインフラストラクチャコードが含まれています。

## ファイル構成

```
devtools/cdk/
├── adminer_gbq.py          # メインのCDKスタック定義
├── app.py                  # CDKアプリケーションエントリーポイント
├── requirements.txt        # Python依存関係
├── cdk.json               # CDK設定ファイル
├── lib/                   # ライブラリディレクトリ
│   ├── __init__.py
│   ├── base_resource.py   # リソース定義インターフェース
│   ├── default_patterns.py # 共通パターン
│   └── fargate_service_pattern.py # Fargateサービスパターン
└── README.md              # このファイル
```

## 元のスタックからの移植内容

- **元のリポジトリ**: `https://github.com/volareinc/carmo-cdk/blob/main/py-infra/stacks/adminer_gbq.py`
- **移植したライブラリ**:
  - `lib.base_resource.py` - リソース定義インターフェース
  - `lib.default_patterns.py` - ECR、SSM、Secrets Manager等の共通パターン
  - `lib.fargate_service_pattern.py` - Fargateサービス構築パターン

## スタック構成

### 主要コンポーネント
- **ECS Fargate サービス**: Adminer BigQueryコンテナの実行環境
- **Application Load Balancer**: ホストベースルーティング
- **Route53 レコード**: DNS設定
- **Security Groups**: ネットワークセキュリティ

### 環境変数
```bash
ADMINER_DESIGN=nette
ADMINER_PLUGINS=tables-filter dump-zip
GOOGLE_CLOUD_PROJECT=nyle-carmo-analysis
GOOGLE_APPLICATION_CREDENTIALS=/tmp/service-account.json
GOOGLE_OAUTH2_ENABLE=true
GOOGLE_OAUTH2_CLIENT_ID=128266455669-qsqdnpuifgrek683fjhgd1abi9qfkdca.apps.googleusercontent.com
GOOGLE_OAUTH2_REDIRECT_URL=https://{fqdn}/oauth2/callback
GOOGLE_OAUTH2_COOKIE_DOMAIN={fqdn}
GOOGLE_OAUTH2_COOKIE_NAME=oauth2_token
GOOGLE_OAUTH2_COOKIE_EXPIRE=3600
GOOGLE_OAUTH2_COOKIE_SECRET=adminer-secret-g
```

## 使用方法

### 1. 環境準備

```bash
cd devtools/cdk
python -m venv .venv
source .venv/bin/activate  # Linux/macOS
# Windows: .venv\Scripts\activate
pip install -r requirements.txt
```

### 2. 開発環境（dev）の使用（すぐに利用可能）

開発環境の設定は既に移植済みで、すぐにデプロイ可能です：

```bash
# 開発環境へのデプロイ
cdk deploy AdminerGbqDevStack

# または環境変数で指定
CDK_ENV=dev cdk deploy

# またはコンテキスト引数で指定
cdk deploy -c environment=dev
```

**開発環境の設定詳細:**
- **アカウント**: 422746423551 (carmo-dev)
- **リージョン**: ap-northeast-1
- **ドメイン**: dev.car-mo.jp
- **VPC**: vpc-03365ffdf742e6bbb
- **ECS Cluster**: development-ecs

### 3. 他の環境設定の追加

他の環境（staging、production等）を追加する場合：

```python
# config/env/production.py (例)
from lib.base_resource import IResource
from aws_cdk import aws_ec2 as ec2, aws_ecs as ecs, aws_iam as iam

class Resource(IResource):
    def __init__(self, scope):
        super().__init__(scope)
        self.site = "production"
        self.account = "123456789012"
        self.region = "ap-northeast-1"

        # 既存リソースの参照設定
        self.vpc = ec2.Vpc.from_lookup(scope, "VPC", vpc_id="vpc-xxxxx")
        self.cluster = ecs.Cluster.from_cluster_name(scope, "Cluster", "your-cluster")
        # ... その他の設定
```

そしてapp.pyに追加：

```python
import config.env.production as prod_env

if environment == "production":
    AdminerGbqStack(
        app,
        "AdminerGbqProdStack",
        site_module=prod_env,
        env=cdk.Environment(account="123456789012", region="ap-northeast-1")
    )
```

### 4. CDKデプロイ

```bash
# 初回のみ
cdk bootstrap

# 構文チェック
cdk synth

# デプロイ
cdk deploy
```

## 必要な既存リソース

このスタックは以下の既存リソースを参照します：

- **VPC**: プライベート/パブリックサブネット
- **ECS Cluster**: Fargateタスク実行環境
- **Application Load Balancer**: 共通ALB
- **Route53 Hosted Zone**: DNS管理
- **IAM Roles**: ECS実行ロール、タスクロール
- **Security Groups**: デフォルト、ALB用

## 注意事項

1. **依存関係**: 元のcarmo-cdkプロジェクトの環境固有設定に依存
2. **リソース参照**: 既存のAWSリソースが適切に設定されている必要がある
3. **権限**: CDKデプロイに必要なAWS権限が必要
4. **コスト**: Fargate、ALB、Route53等のAWSサービス利用料金が発生

## トラブルシューティング

### よくあるエラー

1. **ImportError**: `site_module`が見つからない
   - 環境固有のリソース定義モジュールを作成してください

2. **ResourceNotFound**: 既存リソースが見つからない
   - VPC ID、Cluster名等の設定を確認してください

3. **権限エラー**: CDKデプロイ権限不足
   - 適切なIAMポリシーが設定されているか確認してください

## 関連リンク

- [元のリポジトリ](https://github.com/volareinc/carmo-cdk)
- [AWS CDK Documentation](https://docs.aws.amazon.com/cdk/)
- [Adminer BigQuery プロジェクト](../../README.md)