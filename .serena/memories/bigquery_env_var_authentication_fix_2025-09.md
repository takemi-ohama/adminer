# BigQuery環境変数認証修正記録 (2025年9月20日)

## 修正の背景と問題

### 発生していた問題
- BigQueryログイン時に「Invalid credentials」エラーが発生
- 環境変数`GOOGLE_CLOUD_PROJECT`が正しく読み込まれていない
- `$_ENV['GOOGLE_CLOUD_PROJECT']`が空の値を返していた

### 根本原因の解明
- **PHPの`variables_order`設定**: デフォルトで`GPCS`（GET, POST, COOKIE, SERVER）
- **`$_ENV`配列の制約**: `variables_order`に`E`(Environment)が含まれていないため、環境変数がロードされない
- **`getenv()`の動作**: `variables_order`設定に関係なく、常にシステム環境変数にアクセス可能

## 技術的解決策

### 1. 環境変数取得方法の修正

**修正前 (動作しない)**:
```php
// index.php
'project_id' => $_ENV['GOOGLE_CLOUD_PROJECT']
```

**修正後 (正常動作)**:
```php
// index.php  
'project_id' => getenv('GOOGLE_CLOUD_PROJECT')
```

### 2. 設計の最適化

**問題のあった冗長な実装**:
```php
// login-bigquery.php (修正前)
private function getProjectId()
{
    return $_GET["server"] ??
        $_POST["auth"]["server"] ??
        getenv('GOOGLE_CLOUD_PROJECT') ??  // 冗長な環境変数参照
        $this->config['project_id'];
}
```

**最適化された設計**:
```php
// login-bigquery.php (修正後)
private function getProjectId()
{
    return $_GET["server"] ??           // URL parameter優先
        $_POST["auth"]["server"] ??     // フォーム入力次位
        $this->config['project_id'];    // 設定値フォールバック（index.phpから渡される）
}
```

### 3. Google Cloud標準環境変数の採用

**採用した変数名**: `GOOGLE_CLOUD_PROJECT`

**理由**:
- Google Cloud公式推奨の標準環境変数名
- BigQueryClientが自動的にフォールバック参照する変数
- GCP環境(Cloud Run, Compute Engine等)で自動設定される
- 他のGoogle Cloudクライアントライブラリとの一貫性

## ファイル変更履歴

### 主要修正ファイル
1. **`container/web/index.php`**
   - `$_ENV['GOOGLE_CLOUD_PROJECT']` → `getenv('GOOGLE_CLOUD_PROJECT')`
   - 確実な環境変数取得を実現

2. **`plugins/login-bigquery.php`**
   - 冗長な`getenv('GOOGLE_CLOUD_PROJECT')`参照を削除
   - 元の階層的取得設計に復旧

3. **`container/web/compose.yml`**
   - プロジェクトID: `nyle-carmo-analysis` → `adminer-test-472623`
   - 認証ファイル: `/home/hammer/google_credential.json` → `/home/hammer/adminer-test-owner.json`
   - 環境変数: `GOOGLE_CLOUD_PROJECT=adminer-test-472623`

## 動作検証結果

### テスト環境
- **旧プロジェクト**: `nyle-carmo-analysis` (20個のデータセット)
- **新プロジェクト**: `adminer-test-472623` (1個のデータセット: `dataset_test`)

### 検証項目
✅ **環境変数連携**: `GOOGLE_CLOUD_PROJECT`が正しくログイン画面に表示  
✅ **認証成功**: 「Invalid credentials」エラーが解消  
✅ **データセット表示**: BigQueryデータセット一覧が正常に表示  
✅ **プロジェクト切り替え**: 異なるプロジェクトでの動作確認  
✅ **E2Eテスト**: Playwright MCPによるブラウザ操作テスト完了  

## 環境変数使用状況の調査結果

### プラグインコア（重要）
- ✅ `plugins/` ディレクトリ: 環境変数の直接参照なし
- ✅ 環境変数はindex.phpを経由して`$config['project_id']`として渡される設計

### その他のファイル（影響軽微）
- `container/web/Dockerfile`: ENV設定で使用
- `container/e2e/`: E2Eテストスクリプトで使用  
- `container/docs/`: ドキュメントで使用
- `.serena/memories/`: 過去の記録で言及

## 最終的な標準化完了

### 1. 全ファイルでGOOGLE_CLOUD_PROJECT統一
✅ **E2Eテストスクリプト**: 変数名とprocess.env参照を完全統一
✅ **Docker設定**: compose.ymlで`GOOGLE_CLOUD_PROJECT`使用
✅ **ドキュメント**: 環境変数ガイドを更新

### 2. BigQueryClientの`projectId`パラメータについて
現在の実装では明示的に`projectId`を渡しているため問題ないが、以下の条件で省略も可能:
- サービスアカウントキーファイル内にproject_idが含まれている
- `GOOGLE_CLOUD_PROJECT`環境変数が設定されている
- Application Default Credentials (ADC)環境

### 3. 設計の堅牢性
今回確立した階層的取得パターンは以下の利点がある:
1. URL parameter優先（テスト時の柔軟性）
2. フォーム入力次位（ユーザー操作の尊重）
3. 設定値フォールバック（デフォルト動作の保証）

## PR記録
- **PR #20**: `fix-env-var-authentication`ブランチ
- **コミット**: 環境変数参照修正 + 未コミットファイル同期
- **検証**: 新旧プロジェクトでの動作確認完了

この修正により、BigQuery AdminerプラグインがGoogle Cloud標準に準拠した環境変数設定で確実に動作し、全てのファイルで`GOOGLE_CLOUD_PROJECT`に統一されました。