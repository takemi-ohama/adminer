from aws_cdk import (
    aws_ecs as ecs,
    aws_ecr as ecr,
    aws_ssm as ssm,
    aws_secretsmanager as secretsmanager,
)

from types import ModuleType
from lib.base_resource import IResource


class DefaultPatterns:
    """
    Constractにするほどでもない、定型な処理フローをまとめたもの。
    """

    def __init__(self, scope):
        self.scope = scope

    def newResource(self, module: ModuleType) -> IResource:
        """
        環境別に動的importされたResourceクラスを初期化します。
        """
        return module.Resource(self.scope)

    def ecr_image(self, repository_name: str, tag: str) -> ecs.EcrImage:
        """ECRイメージの取得

        Args:
            repository_name (str): リポジトリ名
            tag (str): タグ名

        Returns:
            ecs.EcrImage: EcrImage
        """
        repos = ecr.Repository.from_repository_name(self.scope, repository_name, repository_name)
        image = ecs.ContainerImage.from_ecr_repository(repos, tag)
        return image

    def ssm_param(self, param_name, construct_id=None) -> ecs.Secret:
        """SSMのセキュアパラメータの値を取得する

        Args:
            param_name (_type_): SSMのパラメータ名
            construct_id (str, optional): Construct id

        Returns:
            ecs.Secret: ecs.Secret
        """
        id = param_name if not construct_id else construct_id
        return ecs.Secret.from_ssm_parameter(
            ssm.StringParameter.from_secure_string_parameter_attributes(self.scope, id, parameter_name=param_name)
        )

    def ssm_sec_string(self, param_name) -> str:
        """SSMのセキュアパラメータの値を取得する

        Args:
            param_name (_type_): SSMのパラメータ名

        Returns:
            str: パラメータの値
        """
        return ssm.StringParameter.from_secure_string_parameter_attributes(
            self.scope, param_name, parameter_name=param_name
        ).string_value

    def secret_manager_value(self, secret_name) -> str:
        """Secrets Managerのシークレット値を取得する

        Args:
            secret_name (str): Secrets Managerのシークレット名

        Returns:
            str: シークレットの値
        """
        return secretsmanager.Secret.from_secret_name_v2(
            self.scope, secret_name.replace("/", "-"), secret_name=secret_name
        ).secret_value.unsafe_unwrap()