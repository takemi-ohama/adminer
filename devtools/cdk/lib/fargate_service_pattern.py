from aws_cdk import (
    Stack,
    Duration,
    aws_ecs as ecs,
    aws_elasticloadbalancingv2 as elb,
    aws_iam as iam,
    aws_route53 as route53,
    aws_route53_targets as r53_targets,
    aws_ssm as ssm,
)
from constructs import Construct
from lib.base_resource import IResource
import boto3


class FargateServicePattern(Stack):

    rs: IResource
    """環境ごとのリソース"""

    zone: route53.IHostedZone
    """Route53のゾーン名"""

    arecord: str = None
    """A Record名"""

    fqdn: str
    """接続URL"""

    alb_priority: int
    """albルールの優先順位(重複不可)"""

    cpu: int
    """taskのcpu"""

    memory_limit_mib: int
    """taskのmemory"""

    port: int
    """コンテナ-ELBの接続port"""

    health_check_path: str
    """ヘルスチェック対象のパス名"""

    health_check_grace_period: int = 240
    """初回起動時のヘルスチェック開始遅延時間(分)
    起動に時間が掛かるタイプのコンテナはここの値を大きくする
    """

    deregistration_delay: int = 60
    """削除する際の待ち時間"""

    desired_count: int = 1
    """並列起動するコンテナの数"""

    host_headers: list[str]
    """alb listernerの振り分けルール"""

    priority: int = None
    """listenerの優先順位"""

    task_role: iam.Role = None
    """タスクに紐づけるIAMロール"""

    def __init__(self, scope: Construct, id: str, **kwargs):
        """
        ルールベースのALBに紐づくFargate Serviceを構築するStack

        Args:
            scope (Construct): CdkApp。親クラスに伝播
            id (str): 識別名。CloudFormationや生成されたAWSリソースの名前に使われる。

        """
        super().__init__(scope, id, **kwargs)

    def create_ecs_task_def(self, id):
        """TaskDefinitionの構築

        Args:
            id (_type_): cdk上で一意のID
        """

        task_def = ecs.FargateTaskDefinition(
            self,
            f"{id}-def",
            cpu=self.cpu,
            memory_limit_mib=self.memory_limit_mib,
            execution_role=self.rs.execution_role,
            task_role=self.task_role if self.task_role is not None else self.rs.task_role,
            runtime_platform=ecs.RuntimePlatform(
                operating_system_family=ecs.OperatingSystemFamily.LINUX,
                cpu_architecture=self.rs.cpu_architecture,
            ),
        )
        return task_def

    def create_ecs_service_elb(self, id: str, task_def: ecs.FargateTaskDefinition, service_container_name: str):
        """ECSサービスと対応するターゲットグループを構築。
        ホスト名でルーティングするルールベースのALB Listenerに紐づけます。

        Attributes:
            self.rs (IResource): 既存リソース
            self.desired_count (int): task 起動するタスク数
            self.health_check_grace_period (int): 初回起動時の監視待機時間(秒)
            self.port (int): HTTPポート
            self.health_check_path (str): helth check対象のパス
            self.deregistration_delay (int): targetを登録解除する前に Elastic Load Balancing が待機する時間。
            self.alb_priority (int): target ruleの優先順位
            self.fqdn (str): Route53に登録するホスト名

        Args:
            id (str): Stak固有のID
            task_def (ecs.FargateTaskDefinition): タスク定義
            service_container_name (str):HTTPポートを開放しているコンテナの名前
        """
        service = ecs.FargateService(
            self,
            f"{id}-service",
            cluster=self.rs.cluster,
            task_definition=task_def,
            security_groups=[self.rs.sg_default],
            desired_count=self.desired_count,
            service_name=f"{id}-service",
            vpc_subnets=self.rs.private_subnets,
            enable_execute_command=True,
            health_check_grace_period=Duration.seconds(self.health_check_grace_period),
        )

        targets = service.load_balancer_target(
            container_name=service_container_name,
            container_port=self.port,
        )

        target_group = elb.ApplicationTargetGroup(
            self,
            f"{id}-tg",
            target_group_name=f"{id}-target",
            targets=[targets],
            health_check=elb.HealthCheck(
                path=self.health_check_path,
                healthy_http_codes="200,302",
            ),
            port=self.port,
            protocol=elb.ApplicationProtocol.HTTP,
            vpc=self.rs.vpc,
            deregistration_delay=Duration.seconds(self.deregistration_delay),
        )

        listener_arn = ssm.StringParameter.value_from_lookup(self, self.rs.listener_name)
        listerner = elb.ApplicationListener.from_application_listener_attributes(
            self,
            f"{id}-listener",
            listener_arn=listener_arn,
            security_group=self.rs.sg_alb,
        )
        listerner.add_target_groups(
            f"{id}-tg-list",
            target_groups=[target_group],
            conditions=[elb.ListenerCondition.host_headers(self.host_headers)],
            priority=self.listener_priority(listener_arn, self.host_headers),
        )
        return service

    def create_route53_record(self, id):
        """albをaliasとするroute53レコードを作成します。

        Attributes:
            self.rs (IResource): 既存リソース
            self.fqdn (str): Aレコードの値

        Args:
            id (_type_): Stack固有のID
        """
        load_balancer_arn = ssm.StringParameter.value_from_lookup(self, self.rs.load_balancer_name)
        load_balancer = elb.ApplicationLoadBalancer.from_lookup(
            self,
            f"{id}-alb",
            load_balancer_arn=load_balancer_arn,
        )

        arecord = self.arecord if self.arecord is not None else self.fqdn

        route53.ARecord(
            self,
            f"{id}-arecord",
            zone=self.zone,
            record_name=arecord,
            ttl=Duration.seconds(60),
            target=route53.RecordTarget.from_alias(r53_targes.LoadBalancerTarget(load_balancer)),
        )

    def listener_priority(self, listener_arn, host_headers):
        """alb listernerの番号を自動採番する

        Args:
            listener_arn (str):
            host_headers (str): ホスト名の配列
        """
        if listener_arn.startswith("dummy"):
            return 1

        client = boto3.client("elbv2")
        response = client.describe_rules(
            ListenerArn=listener_arn,
        )
        p = {
            x["Conditions"][0]["HostHeaderConfig"]["Values"][0]: int(x["Priority"])
            for x in response["Rules"]
            if x["Priority"] != "default"
        }
        if len(p.values()) == 0:
            return 1
        ret = p[host_headers[0]] if host_headers[0] in p else max(p.values()) + 1
        # print(host_headers[0],':',ret)
        return ret