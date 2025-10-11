from types import ModuleType
from aws_cdk import (
    aws_ecs as ecs,
)
from constructs import Construct
from lib.default_patterns import DefaultPatterns
from lib.fargate_service_pattern import FargateServicePattern


class AdminerGbqStack(FargateServicePattern):
    """
    adminerサービスを構築するStack
    """

    def __init__(self, scope: Construct, id: str, site_module: ModuleType, **kwargs):
        """
        adminerサービスを構築する

        Args:
            scope (Construct): CdkApp。親クラスに伝播
            id (str): 識別名。CloudFormationや生成されたAWSリソースの名前に使われる。
            site_module (ModuleType): 動的importされた環境別Resource
        """
        super().__init__(scope, id, **kwargs)

        patterns = DefaultPatterns(self)
        self.rs = patterns.newResource(site_module)
        """環境別の既存リソース定義"""

        # 固有の設定
        self.cpu = 1024
        self.memory_limit_mib = 2048
        self.health_check_grace_period = 60
        self.deregistration_delay = 60
        self.zone = self.rs.zone_car_mo
        self.hostname = "adminer-g"
        self.fqdn = f"{self.hostname}.{self.zone.zone_name}"
        self.host_headers = [self.fqdn]
        self.port = 80
        self.health_check_path = "/"

        image = "ghcr.io/takemi-ohama/adminer-bigquery:master-3431413"
        image_adminer = ecs.ContainerImage.from_registry(image)

        port_mappings = [ecs.PortMapping(container_port=80, host_port=80)]

        environment_app = {
            "ADMINER_DESIGN": "nette",
            "ADMINER_PLUGINS": "tables-filter dump-zip",
            "GOOGLE_CLOUD_PROJECT": "nyle-carmo-analysis",
            "GOOGLE_APPLICATION_CREDENTIALS": "/tmp/service-account.json",
            "GOOGLE_OAUTH2_ENABLE": "true",
            "GOOGLE_OAUTH2_CLIENT_ID": "128266455669-qsqdnpuifgrek683fjhgd1abi9qfkdca.apps.googleusercontent.com",
            "GOOGLE_OAUTH2_REDIRECT_URL": f"https://{self.fqdn}/?oauth2=callback",
            "GOOGLE_OAUTH2_COOKIE_DOMAIN": f".{self.zone.zone_name}",  # ドメイン全体でクッキー共有
            "GOOGLE_OAUTH2_COOKIE_NAME": "oauth2_token",
            "GOOGLE_OAUTH2_COOKIE_EXPIRE": "86400",  # 24時間に延長
            "GOOGLE_OAUTH2_COOKIE_SECRET": "adminer-oauth2-secret-dev-2024",  # より複雑なシークレット
            "GOOGLE_OAUTH2_COOKIE_SECURE": "true",  # HTTPS環境でSecure flag
            "GOOGLE_OAUTH2_COOKIE_SAMESITE": "Lax",  # SameSite設定
        }

        # 構築定義
        task_def = self.create_ecs_task_def(id)

        task_def.add_container(
            f"{id}-app",
            container_name="app",
            image=image_adminer,
            logging=ecs.LogDriver.aws_logs(stream_prefix=f"{id}-container-app"),
            environment=environment_app,
            port_mappings=port_mappings,
        )
        self.create_ecs_service_elb(id, task_def, service_container_name=f"app")

        self.create_route53_record(id)
