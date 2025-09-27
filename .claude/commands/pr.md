# PR作成コマンド

**⚠️重要**: mainブランチで直接コミット禁止

## 手順
1. **ブランチ確認・切り替え**
   - `git branch --show-current`で現在ブランチ確認
   - mainの場合: 新featureブランチ作成→切り替え

2. **変更コミット**
   - `git status`→`git add`→`git commit`（日本語メッセージ）
   - 上位階層含むすべての変更をcommit

3. **プッシュ**
   - `git pull origin main`（コンフリクト時は停止しユーザに報告）
   - `git push -u origin <branch-name>`

4. **PR作成**
   - `mcp__github__create_pull_request`使用
   - タイトル・説明: 日本語、body: Summary+Test plan
      - Summaryの末尾に <!-- I want to review in Japanese. --> を入れる
   - PR作成後、github mcpまたはghでcopilotをreviewerに指定

## 命名規則
- ブランチ: 英語（例: update-config）
- github flow
- コミット・PR: 日本語
