from lib.base_resource import IResource
from aws_cdk import (
    aws_ec2 as ec2,
    aws_ecs as ecs,
    aws_iam as iam,
    aws_s3 as s3,
    aws_elasticloadbalancingv2 as elb,
    aws_route53 as route53,
)
from constructs import Construct


class Resource(IResource):
    """開発環境の既存リソース定義"""

    def __init__(self, scope: Construct):
        """
            開発環境(carmo-dev)の既存リソースを定義する
        Args:
            scope (Construct): 呼び出し元のStack
        """
        super().__init__(scope)

        self.site = "dev"

        self.account = "422746423551"

        self.region = "ap-northeast-1"

        self.vpc = ec2.Vpc.from_vpc_attributes(
            scope,
            "vpc",
            region=self.region,
            vpc_id="vpc-03365ffdf742e6bbb",
            availability_zones=["ap-northeast-1a", "ap-northeast-1c"],
            private_subnet_ids=["subnet-01475de3064a44ca9", "subnet-0ab6f6bcfac7c33e2"],
            vpc_cidr_block="10.21.0.0/16",
        )

        self.private_subnets = ec2.SubnetSelection(
            subnets=[
                ec2.Subnet.from_subnet_attributes(
                    scope,
                    "carmo_system_private12-a",
                    subnet_id="subnet-01475de3064a44ca9",
                ),
                ec2.Subnet.from_subnet_attributes(
                    scope,
                    "carmo_system_private22-c",
                    subnet_id="subnet-0ab6f6bcfac7c33e2",
                ),
            ]
        )

        self.private_subnet_a = ec2.SubnetSelection(
            subnets=[
                ec2.Subnet.from_subnet_attributes(
                    scope,
                    "carmo_system_private12-a_only",
                    subnet_id="subnet-01475de3064a44ca9",
                ),
            ]
        )

        self.public_subnets = ec2.SubnetSelection(
            subnets=[
                ec2.Subnet.from_subnet_attributes(
                    scope,
                    "carmo_system_public1-a",
                    subnet_id="subnet-09ad251edb3478cf9",
                ),
                ec2.Subnet.from_subnet_attributes(
                    scope,
                    "carmo_system_public2-c",
                    subnet_id="subnet-083d8b04ca08ef2ff",
                ),
            ]
        )

        self.vpn_cert_arn = "arn:aws:acm:ap-northeast-1:422746423551:certificate/6a66488a-4370-4fc5-9d8e-ec7490006121"

        self.nameserers = ["10.21.1.254", "10.21.2.254"]

        self.sg_default = ec2.SecurityGroup.from_security_group_id(
            scope, "sg_default", security_group_id="sg-0ab24e2d8fe967682"
        )

        self.sg_alb = ec2.SecurityGroup.from_security_group_id(
            scope, "sg_alb", security_group_id="sg-02efcc5424dac8ca0"
        )

        self.execution_role = iam.Role.from_role_arn(
            scope,
            "execution_role",
            "arn:aws:iam::422746423551:role/ecsTaskExecutionRole",
            mutable=False,
        )

        self.task_role = iam.Role.from_role_arn(
            scope,
            "task_role",
            "arn:aws:iam::422746423551:role/ecsTaskRole",
            mutable=False,
        )

        self.s3bucket_role = iam.Role.from_role_arn(
            scope,
            "s3bucket_role",
            "arn:aws:iam::422746423551:role/RoleForForCsvS3Bucket",
        )

        self.ecr_registry = "422746423551.dkr.ecr.ap-northeast-1.amazonaws.com"

        self.efs_volumes = {
            "name": "development-efs",
            "efs_volume_configuration": {"file_system_id": "fs-07a557fc15ec15307"},
        }

        self.cluster = ecs.Cluster.from_cluster_attributes(
            scope,
            "ecs_cluster",
            vpc=self.vpc,
            security_groups=[],
            cluster_name="development-ecs",
        )

        self.elb_log_bucket = s3.Bucket.from_bucket_name(scope, "log", bucket_name="carmo-dev-elb-logs")

        self.elb_certs = elb.ListenerCertificate.from_arn(
            "arn:aws:acm:ap-northeast-1:422746423551:certificate/33560377-964a-41f2-af40-4138a29d738f"
        )

        self.connection_arn = (
            "arn:aws:codestar-connections:ap-northeast-1:422746423551:connection/1d2d87a9-dabf-4d66-a6e2-7808098101da"
        )

        self.zone_car_mo = route53.HostedZone.from_lookup(
            scope,
            "zone_car_mo",
            domain_name="dev.car-mo.jp",
            private_zone=False,
        )

        self.zone_carmo_kun = route53.HostedZone.from_lookup(
            scope,
            "zone_carmo_kun_jp",
            domain_name="carmo-kun.net",
            private_zone=False,
        )

        self.zone_app_carmo_kun = route53.HostedZone.from_lookup(
            scope,
            "zone_app_carmo_kun",
            domain_name="app.carmo-kun.net",
            private_zone=False,
        )

        self.zone_system_carmo_kun = route53.HostedZone.from_lookup(
            scope,
            "zone_system_carmo_kun",
            domain_name="system.carmo-kun.net",
            private_zone=False,
        )

        self.listener_name = f"{self.site}-common-listener-arn"

        self.load_balancer_name = f"{self.site}-common-lb-arn"

        self.aurora_mysql = "carmo-cluster.cluster-cgglzsqgnixi.ap-northeast-1.rds.amazonaws.com"
        self.aurora_mysql_delta = "carmo-delta-cluster.cluster-cgglzsqgnixi.ap-northeast-1.rds.amazonaws.com"
        self.aurora_mysql_epsilon = "carmo-epsilon-cluster.cluster-cgglzsqgnixi.ap-northeast-1.rds.amazonaws.com"
        self.aurora_mysql_kaikei = "carmo-kaikei-cluster.cluster-cgglzsqgnixi.ap-northeast-1.rds.amazonaws.com"
        self.aurora_mysql_user = "carmo"

        self.multipurpose_redis_url = "redis://multipurpose-redis-vduj6j.serverless.apne1.cache.amazonaws.com:6379"

        self.cpu_small = 512
        self.cpu_medium = 512
        self.cpu_large = 512
        self.memory_small = 1024
        self.memory_medium = 1024
        self.memory_large = 1024

        self.slack_webhook_url = "https://hooks.slack.com/services/[REDACTED]"
        self.slack_webhook_url_kaikei = (
            "https://hooks.slack.com/services/[REDACTED]"
        )

        self.subnet_ids = ["subnet-0cf8885bc016cc60f", "subnet-0fcebc29b11e50891"]
        self.security_group_ids = ["sg-0517a317845993594"]

        self.s3_bucket_arn_list = [
            "arn:aws:s3:::message-bucket-dev/*",
        ]

        # DMS用完全接続情報シークレット
        self.aurora_dms_connection = "/carmo/db/aurora/cluster/root/dms-connection"
        self.cloudsql_dms_connection = "/carmo/db/cloudsql/cluster/root/dms-connection"

        # DMS SSL証明書ARN
        self.certificate_arn = "arn:aws:dms:ap-northeast-1:422746423551:cert:4IOTVX42KBHI7HIHNJ22HYVCIA"