# BigQuery Adminer E2E テスト対応チェックリスト

## 🎯 i05.md #3 実行完了確認

### ✅ i05.md #3 要求事項達成状況
- [x] **#2改善提案レポート読み込み**: COMPREHENSIVE_TEST_REPORT.md、RESPONSE_CHECKLIST.md詳細分析完了
- [x] **主要リポジトリログ・コード対応状況確認**: 最新コミット履歴と基本フローテスト（3/3成功）で機能確認完了
- [x] **未解決事項への対処**: UI セレクター問題の根本解決、成功パターン特定完了

### 📋 i05.md #2 完了済み事項（参考）
- [x] **包括的なE2Eテストスクリプト作成**: 7つの機能別テストファイル作成完了
- [x] **ページごと・機能ごとの組織化**: 認証→データセット→テーブル→SQL→データ変更→UI→I/O の順序で整理
- [x] **テストシナリオ実行シェルスクリプト**: 5種類のスクリプト作成（スモーク、個別、包括、クリティカル、回帰）
- [x] **個別テスト実行・問題発見**: 4つのテストケース実行で具体的問題特定
- [x] **改善提案レポート作成**: 包括的なレポートとチェックリスト作成完了

## 🔍 発見された問題と対応優先度

### ✅ 解決済み - Priority 1
#### 1. UI要素セレクター問題
- **問題**: `#databases`, `#tables`, `table.data` セレクターが要素を検出できない
- **影響**: データセット選択、テーブル操作、SQL結果表示が機能しない
- **対応**: [x] DOM構造調査完了、正しいセレクター特定済み
  - ✅ データセット選択: `a[href*="db="]` （成功）
  - ✅ テーブル画面構造: `h3` (Tables and views) + `a[href*="table="]`
  - ✅ 結果テーブル: `table.checkable.odds`
- **結果**: データセット一覧テストは11件検出で正常動作確認

#### 2. BigQueryドライバーUI生成問題
- **問題**: データセット・テーブル一覧がHTMLに出力されていない可能性
- **影響**: 基本的なナビゲーション機能全般
- **対応**: [x] BigQueryドライバーの`get_databases()`, `tables_list()`メソッド確認完了
- **結果**: ドライバーは正常に動作、基本フローテスト3/3成功で確認済み

### 🔄 Priority 2 - 継続対応中
#### 3. テーブル一覧ナビゲーション問題
- **問題**: データセット選択後、テーブル一覧画面での要素検出が断続的に失敗
- **影響**: テーブル操作機能の安定性
- **対応**: [ ] ナビゲーションフローの `waitForLoadState` 追加による安定化（進行中）

#### 4. SQL結果表示機能
- **問題**: クエリ実行は成功するが結果テーブルが表示されない
- **影響**: SQLクエリ結果の確認・検証ができない
- **対応**: [ ] BigQuery `select()`メソッドの結果処理ロジック確認（進行中）

### 🛠️ Priority 3 - 技術的課題
#### 5. コンテナファイル更新問題
- **問題**: Dockerコンテナ内のテストファイル更新が適切に反映されない
- **影響**: テストの修正・デバッグ効率
- **対応**: [ ] entrypoint.sh の処理最適化、または開発用マウント設定（今後対応）

### 📈 Priority 3 - 改善対応
#### 4. テスト環境最適化
- **問題**: ディレクトリ構造の複雑さとビルド手順
- **影響**: 開発効率とメンテナンス性
- **対応**: [ ] 統一されたE2E環境構築（2週間以内）

## 📋 具体的対応手順

### Step 1: 緊急調査（今すぐ実行）
```bash
# 1. 実際のUI構造を目視確認
cd /home/ubuntu/work/adminer/devtools/e2e
docker compose run --rm playwright-e2e npx playwright test basic-flow-test.spec.js --headed

# 2. BigQueryドライバーのデバッグ出力確認
# Web環境でログ確認
docker logs adminer-bigquery-test --tail 50
```

### Step 2: DOM構造分析（24時間以内）
- [ ] ブラウザ開発者ツールでデータセット一覧のHTML構造確認
- [ ] 実際のCSS class・ID名を記録
- [ ] テーブル一覧の生成タイミング確認
- [ ] SQL結果表示エリアのDOM確認

### Step 3: セレクター修正（48時間以内）
```javascript
// 修正例テンプレート
// 現在: page.locator('#databases')
// 修正: page.locator('#databases, .database-list, [data-test="datasets"]')
//       .or(page.locator('a[href*="db="]'))
```

### Step 4: BigQueryドライバー確認（1週間以内）
- [ ] `plugins/drivers/bigquery.php` の `get_databases()` メソッド動作確認
- [ ] `tables_list()` メソッドの戻り値確認
- [ ] `select()` メソッドの結果HTML生成確認
- [ ] Adminer UIテンプレートとの連携確認

## 🧪 検証手順

### 基本動作確認
```bash
# 1. 認証テスト（既に成功確認済み）
docker compose run --rm playwright-e2e npx playwright test 01-authentication-login.spec.js --grep "BigQuery認証とプロジェクト接続テスト"

# 2. 修正後の再テスト手順
# DOM修正後:
docker compose run --rm playwright-e2e npx playwright test 02-database-dataset-operations.spec.js --grep "データセット一覧表示テスト"

# 3. 段階的修正確認
docker compose run --rm playwright-e2e npx playwright test 03-table-schema-operations.spec.js --grep "テーブル一覧表示テスト"
```

### 包括テスト実行
```bash
# 修正完了後の全体確認
cd /home/ubuntu/work/adminer/devtools/e2e/tests-full
./run-critical-path-tests.sh  # 重要機能のみ
./run-all-tests.sh           # 全機能包括テスト
```

## 📊 成功基準

### 短期目標（1週間以内）
- [ ] データセット一覧表示テスト: PASS
- [ ] テーブル一覧表示テスト: PASS
- [ ] SQL基本実行テスト: PASS
- [ ] クリティカルパステスト成功率: 80%以上

### 中期目標（2週間以内）
- [ ] 全テストカテゴリ実行: 85%以上の成功率
- [ ] 包括テストスイート安定実行
- [ ] 回帰テスト環境確立

## 🔧 技術的推奨事項

### セレクター改善戦略
1. **多層フォールバック**: CSS ID → クラス → 属性 → テキスト内容
2. **BigQuery固有パターン**: `[data-bigquery]`, `.bigquery-*` 等の専用属性
3. **動的待機**: BigQuery API遅延を考慮した適切な待機時間

### ドライバー機能強化
1. **デバッグモード**: 開発時のHTML出力ログ強化
2. **エラーハンドリング**: UI生成失敗時の適切なフォールバック
3. **テスト支援**: テスト用の特別なCSS class付与

## 📝 ドキュメント参照

### 関連ファイル
- **実行手順**: `/home/ubuntu/work/adminer/devtools/e2e/tests-full/README.md`
- **テストスイート**: `/home/ubuntu/work/adminer/devtools/e2e/tests-full/*.spec.js`
- **実行スクリプト**: `/home/ubuntu/work/adminer/devtools/e2e/tests-full/run-*.sh`
- **問題レポート**: `/home/ubuntu/work/adminer/devtools/e2e/tests-full/COMPREHENSIVE_TEST_REPORT.md`

### 既存動作テスト
- **基本フロー**: `basic-flow-test.spec.js` （成功実績あり）
- **参照系**: `reference-system-test.spec.js`
- **CRUD**: `bigquery-crud-test.spec.js`

## ⚡ 緊急時対応

### 包括テスト失敗時
1. 既存の動作テスト（`basic-flow-test.spec.js`）で基本機能確認
2. 個別テスト（`./run-individual-test.sh [番号] --headed`）でデバッグ
3. Web環境の再起動（`docker compose restart`）

### ビルド・環境問題
1. E2Eコンテナ再ビルド（`docker compose build playwright-e2e`）
2. ディレクトリ同期確認（container/e2e ↔ devtools/e2e）
3. ネットワーク接続確認（`adminer-bigquery-test` コンテナ疎通）

---

## 📞 エスカレーション

### 技術課題
- 新しいissueファイル作成: `devtools/issues/i06.md` など
- 既存記憶参照: Serena MCP `playwright_e2e_comprehensive_restoration_2025-09-21`

### 緊急問題
- Web環境ログ確認: `docker logs adminer-bigquery-test`
- 手動動作確認: ブラウザで `http://localhost:8080` アクセス

**チェックリスト最終更新**: 2025年9月29日
**対応予定期限**: 2025年10月6日（1週間以内の主要修正完了目標）