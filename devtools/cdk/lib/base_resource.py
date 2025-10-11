from aws_cdk import (
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_s3 as s3,
    aws_elasticloadbalancingv2 as elb,
    aws_route53 as route53,
)
from constructs import Construct


class IResource:
    """環境別のリソース定義のInterface"""

    scope: Construct

    site: str
    "dev/staging/production"

    account: str

    region: str

    vpc: ec2.IVpc

    private_subnets: ec2.SubnetSelection
    "private subnet"

    private_subnet_a: ec2.SubnetSelection
    """private subnetのうち、1aのみ"""

    public_subnets: ec2.SubnetSelection
    "public subnet"

    vpn_cert_arn: str
    """
    client vpn用の証明書arn

    carmo-cdk/bin/create_vpnkey.shで作成
    """

    nameserers: list[str]
    """
    route53resolverインバウンドのdnsサーバーのIPアドレスリスト

    route53resolver自体は手動で作成しておく
    """

    sg_default: ec2.ISecurityGroup
    """デフォルトセキュリティグループ"""

    sg_public_web: str
    "https/httpを開放したセキュリティグループ"

    sg_alb: ec2.ISecurityGroup
    """alb用のセキュリティグループ"""

    cluster: ecs.ICluster

    execution_role: iam.IRole

    task_role: iam.IRole

    s3bucket_role = iam.Role

    ecr_registry: str
    """name of private registry"""

    efs_volumes: dict[str, str | dict]

    elb_log_bucket: s3.IBucket
    "elbのログ置き場"

    elb_certs: elb.ListenerCertificate
    "elbのhttps通信用証明書"

    connection_arn: str
    "CodePipeline起動用のcodestar-connection arn"

    zone_car_mo: route53.IHostedZone
    "car-mo.jpのRoute53ゾーン"

    zone_carmo_kun: route53.IHostedZone
    "caromo-kun.jpのRoute53ゾーン"

    zone_app_carmo_kun: route53.IHostedZone
    "app.carmo-kun.co.jpのRoute53ゾーン"

    zone_system_carmo_kun: route53.IHostedZone
    "system.carmo-kun.co.jpのRoute53ゾーン"

    listener_name: str
    "共通albのlisterer arn"

    load_balancer_name: str
    "共通albのload balancer arn"

    aurora_mysql: str
    "Aurora-mysqlの汎用DBへの書き込みエンドポイント"

    aurora_mysql_delta: str
    "Aurora-mysqlのdeltaブランチ用DBへの書き込みエンドポイント"

    aurora_mysql_epsilon: str
    "Aurora-mysqlのepsilonブランチ用DBへの書き込みエンドポイント"

    aurora_mysql_kaikei: str
    "Aurora-mysqlのkaikeiブランチ用DBへの書き込みエンドポイント"

    aurora_mysql_ro: str
    "Aurora-mysqlの汎用DBへの読み込みエンドポイント"

    aurora_mysql_user: str
    "Aurora-mysqlのユーザー"

    multipurpose_redis_url: str
    "汎用redisサーバへのエンドポイント"

    cpu_small: int
    "dev/stagingでは512, productionでは1024"

    cpu_medium: int
    "dev/stagingでは512, productionでは2048"

    cpu_large: int
    "dev/stagingでは512, productionでは4096"

    memory_small: int
    "dev/stagingでは1024, productionでは2048"

    memory_medium: int
    "dev/stagingでは1024, productionでは4096"

    memory_large: int
    "dev/stagingでは1024, productionでは8192"

    cpu_architecture: ecs.CpuArchitecture = ecs.CpuArchitecture.X86_64

    slack_webhook_url: str
    "slackのwebhook_url"

    slack_webhook_url_kaikei: str
    "kaikeiブランチ用slackのwebhook_url"

    subnet_ids: list[str]
    "サブネットIDのリスト"

    security_group_ids: list[str]
    "セキュリティグループIDのリスト"

    s3_bucket_arn_list: list[str]
    "s3 bucketのarnのリスト"

    aurora_dms_connection: str
    "DMS用Aurora完全接続情報のSecret Manager パス"

    cloudsql_dms_connection: str
    "DMS用CloudSQL完全接続情報のSecret Manager パス"

    certificate_arn: str
    "DMS用SSL証明書のARN"

    def __init__(self, scope):
        self.scope = scope