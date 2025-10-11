#!/usr/bin/env python3
"""
Adminer BigQuery CDK Application
元のスタック: https://github.com/volareinc/carmo-cdk/blob/main/py-infra/stacks/adminer_gbq.py

このアプリケーションは元のcarmo-cdkリポジトリからAdminer BigQueryスタックを移植したものです。
dev環境設定を含む完全なデプロイ設定が含まれています。
"""

import aws_cdk as cdk
from adminer_gbq import AdminerGbqStack
import config.env.dev as dev_env
import os

# CDKアプリケーションの作成
app = cdk.App()

# 環境変数または引数から環境を取得（デフォルトはdev）
environment = app.node.try_get_context("environment") or os.environ.get("CDK_ENV", "dev")

# 開発環境でのデプロイ設定
if environment == "dev":
    AdminerGbqStack(
        app,
        "AdminerGbqDevStack",
        site_module=dev_env,
        env=cdk.Environment(
            account="422746423551",  # carmo-dev アカウント
            region="ap-northeast-1"  # ap-northeast-1 リージョン
        ),
        description="Adminer BigQuery service for development environment"
    )
    print("開発環境 (dev) のAdminer BigQuery スタックを設定しました。")
    print("デプロイコマンド: cdk deploy AdminerGbqDevStack")

else:
    # その他の環境では設定例を表示
    print(f"環境 '{environment}' は未設定です。")
    print("利用可能な環境: dev")
    print("")
    print("使用方法:")
    print("  開発環境: cdk deploy AdminerGbqDevStack")
    print("  または: CDK_ENV=dev cdk deploy")
    print("  または: cdk deploy -c environment=dev")
    print("")
    print("他の環境を追加する場合は config/env/ に設定ファイルを作成してください。")

app.synth()
