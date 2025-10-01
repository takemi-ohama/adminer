# Directory Structure Rename: container/ → devtools/ (2025-10-01)

## 変更概要
- **実施日時**: 2025年10月1日
- **変更内容**: プロジェクトディレクトリ `container/` を `devtools/` にリネーム
- **理由**: 開発・テスト環境の統一的管理とより明確な役割表現

## 更新されたディレクトリ構造
```
adminer/
├── adminer/                    # Adminerコア
├── plugins/                   # プラグイン群
├── devtools/                  # 開発・テスト環境（旧container/）
│   ├── dev/                  # 開発環境関連
│   ├── web/                  # Webアプリケーション関連（旧tests）
│   ├── docs/                 # ドキュメント
│   ├── issues/               # プロジェクト管理
│   ├── prod/                 # 本番環境関連
│   └── e2e/                  # E2Eテスト環境
└── composer.json
```

## 影響を受けるパス
- `container/web/` → `devtools/web/`
- `container/docs/` → `devtools/docs/`
- `container/issues/` → `devtools/issues/`
- `container/e2e/` → `devtools/e2e/`

## 更新完了済み
- ✅ CLAUDE.md: 全パス参照を更新完了
- ✅ ワークスペース構造図を更新
- ✅ 開発・テスト手順のパスを修正

## 今後の注意点
- 既存のSerena記憶内で`container/`参照があるものは順次更新
- 新しい作業では必ず`devtools/`パスを使用
- Docker compose実行時は`cd devtools/web`を使用

## Webコンテナ起動コマンド（更新後）
```bash
cd devtools/web
docker compose up -d
```