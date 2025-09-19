# Adminer BigQuery ディレクトリ構造大幅更新 (2025-09)

## 変更概要
プロジェクトの保守性と開発効率向上のため、containerディレクトリ構造を役割別に分離・整理しました。

## 新しいディレクトリ構造

```
adminer/
├── container/
│   ├── dev/              # 開発環境関連
│   │   ├── compose.yml
│   │   ├── Dockerfile
│   │   ├── playwright-mcp.config.json  # Playwright MCP設定
│   │   └── 開発用スクリプト
│   │
│   ├── web/              # Webアプリケーション関連（旧tests）
│   │   ├── compose.yml   # Adminerサービス定義
│   │   ├── Dockerfile    # Webコンテナ設定
│   │   ├── index.php     # Adminer設定
│   │   └── plugins/      # Webアプリケーション用プラグイン
│   │
│   ├── e2e/             # E2Eテスト関連（新規分離）
│   │   ├── compose.yml  # Playwrightテストサービス定義
│   │   ├── Dockerfile   # E2Eテストコンテナ設定
│   │   ├── tests/       # Playwrightテストスクリプト
│   │   ├── run-e2e-tests.sh     # E2Eテスト実行
│   │   ├── run-monkey-test.sh   # モンキーテスト実行
│   │   ├── playwright-report/   # テストレポート
│   │   └── test-results/        # テスト結果
│   │
│   ├── docs/            # プロジェクトドキュメント（移動済み）
│   │   ├── testing-guide.md              # テスト方法（更新済み）
│   │   ├── development-workflow.md       # 開発ワークフロー
│   │   ├── playwright-mcp-testing-guide.md # Playwright MCPテスト手順
│   │   └── その他技術文書
│   │
│   └── issues/          # プロジェクト管理（移動済み）
│       ├── i01.md       # 開発指示書
│       ├── i02.md       # 追加指示書
│       ├── plan*.md     # 実装計画
│       └── report*.md   # 実装報告
│
└── その他既存ディレクトリ
    ├── adminer/         # Adminerコア
    ├── plugins/         # プラグイン群
    └── composer.json    # 依存関係管理
```

## 主な変更点

### 1. ディレクトリ名変更
- `container/tests` → `container/web`（役割の明確化）

### 2. E2E環境の分離
- E2E関連ファイルを`container/e2e`に独立
- Playwright設定、テストスクリプト、実行スクリプトを集約

### 3. ドキュメント・管理ファイルの統合（2025-09-19更新）
- `docs/` → `container/docs/` へ移動
- `issues/` → `container/issues/` へ移動
- プロジェクト関連ファイルを`container/`配下で統一

### 4. 設定ファイル更新
- `container/web/compose.yml`: Webサービスのみ定義
- `container/e2e/compose.yml`: E2Eテストサービス定義
- `container/dev/playwright-mcp.config.json`: Playwright MCP設定
- 各Dockerfileのパス参照を新構造に対応

### 5. スクリプト修正
- `run-e2e-tests.sh`: 新ディレクトリ構造対応
- `run-monkey-test.sh`: 新ディレクトリ構造対応
- Adminerコンテナ起動を`../web`から実行するよう修正

## 新しい開発フロー

### Webアプリケーション開発
```bash
cd container/web
docker compose up --build -d    # Webアプリケーション起動
```

### E2Eテスト実行
```bash
cd container/e2e
./run-e2e-tests.sh            # 包括的E2Eテスト
./run-monkey-test.sh          # モンキーテスト
```

### Playwright MCPテスト
```bash
# DooD環境でのPlaywright MCPテスト
# .mcp.jsonの設定パス: ./container/dev/playwright-mcp.config.json
# 接続形式: http://[コンテナ名]
```

### 統合開発フロー
1. `container/web`でWebアプリケーション開発・起動
2. `container/e2e`でテスト実行・検証
3. Playwright MCPで詳細なE2E検証
4. 各環境が独立して管理可能

## 技術的改善点

### 1. 保守性向上
- 各コンポーネントの責任範囲が明確
- ファイル配置が直感的で理解しやすい
- プロジェクト管理ファイルの一元化

### 2. 開発効率向上
- 開発・テストの環境が分離され、並行作業可能
- テストのみの実行が簡単
- ドキュメントの参照が容易

### 3. CI/CD最適化
- 各段階で必要なコンテナのみビルド・実行
- テスト結果の管理が容易

## ドキュメント更新内容

### testing-guide.md更新
- 新ディレクトリ構造の説明追加
- 実行コマンドを新構造に対応
- モンキーテスト手順を追加

### development-workflow.md新規作成
- 包括的な開発ワークフローガイド
- ディレクトリ別詳細説明
- CI/CD統合例
- トラブルシューティング

### playwright-mcp-testing-guide.md新規作成
- Playwright MCPを使用した詳細テスト手順
- DooD環境でのセレクター戦略
- エラーハンドリングとデバッグ手法

## 実装・検証済み事項

### Playwright E2E テスト
- 新構造での正常動作確認済み
- BigQueryドライバーの利用可能性確認
- 基本的なワークフローテスト成功

### Playwright MCP テスト（2025-09-19追加）
- BigQuery接続・認証プロセス検証
- データセット一覧表示確認（181テーブル）
- テーブル構造表示確認
- DooD環境でのブラウザ操作検証成功

### モンキーテスト
- ランダムな操作による安定性検証
- Fatal Errorの検出機能
- アプリケーション安定性の確認

## 今後の開発における注意点

1. **パス参照**: 新しいディレクトリ構造を前提とした開発
2. **コンテナ起動順序**: Webコンテナ → E2Eテストの順序
3. **ドキュメント**: 変更時は`container/docs/`内を更新
4. **プロジェクト管理**: 新規課題は`container/issues/`に記録

この構造変更により、BigQuery Adminerプロジェクトの開発・テスト・運用が大幅に効率化され、管理ファイルの一元化により保守性が向上しました。