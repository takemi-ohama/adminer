#!/bin/bash
# OAuth2 ログインフローの回帰テスト
#
# 検証対象の不具合:
#   1. ログイン画面の「Sign in with Google」が Adminer のログインフォームを送信せず
#      Google へ直接遷移するため、コールバック後に Adminer 未ログインのままログイン画面に戻る
#   2. Db.php の vendor/autoload.php 参照が 1 階層足りず、OAuth2 トークン取得後に Fatal error
#
# Google の実アカウントは不要。Google へリダイレクトする直前までを HTTP レベルで検証する。
# 実行: ./devtools/web/verify-oauth2-login-flow.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
IMAGE_TAG="adminer-bigquery:verify-oauth2"
CONTAINER_NAME="adminer-bigquery-verify-oauth2"
PORT="${VERIFY_PORT:-18080}"
# コンテナ外（DooD 環境等）からアクセスする場合は VERIFY_HOST でホスト名を指定する
HOST_ADDR="${VERIFY_HOST:-127.0.0.1}"
BASE_URL="http://localhost:${PORT}"
# コンテナ外から叩く場合も Cookie のドメインを localhost に揃えるため --resolve を使う
CURL="curl -sS --resolve localhost:${PORT}:${HOST_ADDR}"
PROJECT_ID="verify-project"
# VERIFY_ACCESS_TOKEN を使う検証では、トークンのプロジェクトと GOOGLE_CLOUD_PROJECT を
# 一致させる必要がある（不一致だと BigQuery API が ACCESS_TOKEN_TYPE_UNSUPPORTED を返す）
WORK_DIR="$(mktemp -d)"

FAILED=0

cleanup() {
    # VERIFY_KEEP=1 で調査用にコンテナと作業ファイルを残す
    if [[ -n "${VERIFY_KEEP:-}" ]]; then
        echo "🔍 コンテナ $CONTAINER_NAME と $WORK_DIR を残しました"
        return
    fi
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

pass() { echo "✅ PASS: $1"; }
fail() { echo "❌ FAIL: $1"; echo "   $2"; FAILED=1; }

echo "🏗️  イメージビルド中 ($IMAGE_TAG)..."
docker build -q -t "$IMAGE_TAG" -f "$REPO_ROOT/devtools/web/Dockerfile" "$REPO_ROOT" || {
    echo "❌ ビルド失敗"
    exit 1
}

echo "🚀 コンテナ起動中 (port $PORT)..."
docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1
docker run -d --name "$CONTAINER_NAME" -p "${PORT}:80" \
    -e GOOGLE_CLOUD_PROJECT="${VERIFY_REAL_PROJECT:-$PROJECT_ID}" \
    -e GOOGLE_OAUTH2_ENABLE=true \
    -e GOOGLE_OAUTH2_CLIENT_ID=verify-client-id.apps.googleusercontent.com \
    -e GOOGLE_OAUTH2_CLIENT_SECRET=verify-client-secret \
    -e GOOGLE_OAUTH2_REDIRECT_URL="https://verify.example.com/?oauth2=callback" \
    -e GOOGLE_OAUTH2_COOKIE_NAME=oauth2_token \
    -e GOOGLE_OAUTH2_COOKIE_EXPIRE=3600 \
    "$IMAGE_TAG" >/dev/null || exit 1

echo -n "⏳ 起動待ち"
for _ in $(seq 1 30); do
    if $CURL -o /dev/null -w '' "$BASE_URL/" 2>/dev/null; then break; fi
    echo -n "."
    sleep 1
done
echo

# ---------------------------------------------------------------------------
# T1: ログイン画面の Google ボタンはフォームを送信する（Google への直リンクではない）
# ---------------------------------------------------------------------------
$CURL -c "$WORK_DIR/cookie.txt" -o "$WORK_DIR/login.html" "$BASE_URL/"
# 上流 Adminer はログイン POST に CSRF トークンを要求する
CSRF_TOKEN=$(sed -n "s/.*name='token' value='\([^']*\)'.*/\1/p" "$WORK_DIR/login.html" | head -1)

if ! grep -q 'oauth2-signin-button' "$WORK_DIR/login.html"; then
    fail "T1 Google ログインボタンが描画される" "oauth2-signin-button が見つからない"
elif grep -q 'accounts\.google\.com' "$WORK_DIR/login.html"; then
    fail "T1 Google ボタンはフォームを送信する" \
        "ログイン画面に accounts.google.com への直リンクが残っている（Adminer のログインを経由しない）"
else
    pass "T1 Google ボタンはフォームを送信する（Google への直リンクなし）"
fi

# ---------------------------------------------------------------------------
# T2: ログインフォーム送信で Adminer のセッションが確立し、サーバ付き URL へ遷移する
# ---------------------------------------------------------------------------
LOGIN_LOCATION=$($CURL -b "$WORK_DIR/cookie.txt" -c "$WORK_DIR/cookie.txt" -D - -o /dev/null \
    --data-urlencode "token=${CSRF_TOKEN}" \
    --data-urlencode 'auth[driver]=bigquery' \
    --data-urlencode "auth[server]=${PROJECT_ID}" \
    --data-urlencode 'auth[username]=bigquery-service-account' \
    --data-urlencode 'auth[password]=service-account-auth' \
    --data-urlencode 'auth[db]=' \
    "$BASE_URL/" | tr -d '\r' | awk 'tolower($1)=="location:" {print $2}')

if [[ "$LOGIN_LOCATION" == *"bigquery=${PROJECT_ID}"* && "$LOGIN_LOCATION" == *"username="* ]]; then
    pass "T2 ログインフォーム送信でサーバ付き URL へ遷移する"
else
    fail "T2 ログインフォーム送信でサーバ付き URL へ遷移する" "Location: ${LOGIN_LOCATION:-なし}"
fi

# ---------------------------------------------------------------------------
# T3: 未認証状態では Google へリダイレクトし、state に戻り先（サーバ付き URL）を保持する
# ---------------------------------------------------------------------------
AUTH_PATH="/?bigquery=${PROJECT_ID}&username=bigquery-service-account"
GOOGLE_LOCATION=$($CURL -b "$WORK_DIR/cookie.txt" -D - -o /dev/null "${BASE_URL}${AUTH_PATH}" \
    | tr -d '\r' | awk 'tolower($1)=="location:" {print $2}')

STATE_PARAM=$(printf '%s' "$GOOGLE_LOCATION" | sed -n 's/.*[?&]state=\([^&]*\).*/\1/p')
REDIRECT_TO=$(printf '%s' "$STATE_PARAM" | python3 -c '
import base64, json, sys, urllib.parse
s = urllib.parse.unquote(sys.stdin.read().strip())
if not s:
    print("")
    sys.exit()
try:
    print(json.loads(base64.b64decode(s + "=" * (-len(s) % 4)).decode()).get("redirect_to", ""))
except Exception:
    print("")
')

if [[ "$GOOGLE_LOCATION" != *"accounts.google.com"* ]]; then
    fail "T3 未認証時は Google へリダイレクトする" "Location: ${GOOGLE_LOCATION:-なし}"
elif [[ "$REDIRECT_TO" == *"bigquery=${PROJECT_ID}"* ]]; then
    pass "T3 state の戻り先がサーバ付き URL である ($REDIRECT_TO)"
else
    fail "T3 state の戻り先がサーバ付き URL である" \
        "redirect_to=${REDIRECT_TO:-なし} — ログイン画面に戻るためループする"
fi

# ---------------------------------------------------------------------------
# T4: トークン保持時に vendor/autoload.php の読み込みで Fatal error にならない
# ---------------------------------------------------------------------------
$CURL -b "$WORK_DIR/cookie.txt" -b "oauth2_token=verify-dummy-token" \
    -o "$WORK_DIR/authed.html" "${BASE_URL}${AUTH_PATH}"

if grep -q 'Failed opening required' "$WORK_DIR/authed.html"; then
    fail "T4 トークン保持時に autoload が解決できる" \
        "$(grep -o "Failed opening required '[^']*'" "$WORK_DIR/authed.html" | head -1)"
else
    pass "T4 トークン保持時に autoload が解決できる"
fi

# ---------------------------------------------------------------------------
# T5: BigQuery クライアントの生成が依存関係の欠落で失敗しない
#     （\Google\Client は google/apiclient のクラスで依存に含まれない）
# ---------------------------------------------------------------------------
if grep -qE 'Class (&quot;|")Google' "$WORK_DIR/authed.html"; then
    fail "T5 OAuth2 トークンで BigQuery クライアントを生成できる" \
        "$(grep -oE 'Class (&quot;|")[^&"]*(&quot;|") not found' "$WORK_DIR/authed.html" | head -1)"
else
    pass "T5 OAuth2 トークンで BigQuery クライアントを生成できる"
fi

# ---------------------------------------------------------------------------
# T6: 実アクセストークンがある場合はログイン後の画面まで検証する
#     VERIFY_ACCESS_TOKEN（Google OAuth2 アクセストークン）指定時のみ実行
# ---------------------------------------------------------------------------
if [[ -n "${VERIFY_ACCESS_TOKEN:-}" ]]; then
    REAL_PATH="/?bigquery=${VERIFY_REAL_PROJECT:-$PROJECT_ID}&username=bigquery-service-account"
    rm -f "$WORK_DIR/real_cookie.txt"
    $CURL -c "$WORK_DIR/real_cookie.txt" -o "$WORK_DIR/real_login.html" "$BASE_URL/"
    REAL_CSRF=$(sed -n "s/.*name='token' value='\([^']*\)'.*/\1/p" "$WORK_DIR/real_login.html" | head -1)
    $CURL -b "$WORK_DIR/real_cookie.txt" -c "$WORK_DIR/real_cookie.txt" -o /dev/null \
        --data-urlencode "token=${REAL_CSRF}" \
        --data-urlencode 'auth[driver]=bigquery' \
        --data-urlencode "auth[server]=${VERIFY_REAL_PROJECT:-$PROJECT_ID}" \
        --data-urlencode 'auth[username]=bigquery-service-account' \
        --data-urlencode 'auth[password]=service-account-auth' \
        --data-urlencode 'auth[db]=' \
        "$BASE_URL/"
    $CURL -b "$WORK_DIR/real_cookie.txt" -b "oauth2_token=${VERIFY_ACCESS_TOKEN}" \
        -o "$WORK_DIR/real_authed.html" "${BASE_URL}${REAL_PATH}"

    if ! grep -q 'Select database' "$WORK_DIR/real_authed.html"; then
        fail "T6 実トークンでログイン後の画面が表示される" \
            "データベース選択画面が表示されない（トークン期限切れの可能性あり）"
    elif grep -q 'Fatal error' "$WORK_DIR/real_authed.html"; then
        fail "T6 ログイン後の画面が Fatal error なく描画される" \
            "$(sed 's/<[^>]*>//g' "$WORK_DIR/real_authed.html" | grep -A1 'Fatal error' | head -2 | tr '\n' ' ')"
    else
        pass "T6 実トークンでログイン後の画面が Fatal error なく描画される"
    fi

    # -----------------------------------------------------------------------
    # T8: OAuth2認証でもデータセット一覧が取得できる
    #     （OAuth2経路のBigQueryClientが他のコードと同じプロパティに入っていること）
    # -----------------------------------------------------------------------
    # リンクは HTML エスケープされる（&amp;db=...）ため接頭辞は見ない
    if grep -qE 'db=[A-Za-z0-9_]' "$WORK_DIR/real_authed.html"; then
        pass "T8 実トークンでデータセット一覧が表示される"
    else
        fail "T8 実トークンでデータセット一覧が表示される" \
            "データベース選択画面にデータセットが1件も無い"
    fi

    # -----------------------------------------------------------------------
    # T7: ログイン後の主要画面が Fatal error なく描画される
    #     上流 Adminer のドライバAPI変更（SqlDriver）への追従漏れを検出する
    # -----------------------------------------------------------------------
    DATASET=$(grep -oE 'db=[A-Za-z0-9_]+' "$WORK_DIR/real_authed.html" | head -1 | cut -d= -f2)
    SCREENS="${REAL_PATH}&sql="
    if [[ -n "$DATASET" ]]; then
        SCREENS="$SCREENS ${REAL_PATH}&db=${DATASET} ${REAL_PATH}&db=${DATASET}&sql="
    fi

    SCREEN_NG=0
    for SCREEN in $SCREENS; do
        $CURL -b "$WORK_DIR/real_cookie.txt" -b "oauth2_token=${VERIFY_ACCESS_TOKEN}" \
            -o "$WORK_DIR/screen.html" "${BASE_URL}${SCREEN}"
        if grep -q 'Fatal error' "$WORK_DIR/screen.html"; then
            fail "T7 主要画面が Fatal error なく描画される (${SCREEN})" \
                "$(sed 's/<[^>]*>//g' "$WORK_DIR/screen.html" | grep -A1 'Fatal error' | head -2 | tr '\n' ' ')"
            SCREEN_NG=1
        fi
    done
    if [[ $SCREEN_NG -eq 0 ]]; then
        pass "T7 主要画面が Fatal error なく描画される (${SCREENS})"
    fi
else
    echo "⏭️  SKIP: T6（VERIFY_ACCESS_TOKEN 未指定のためログイン後の画面は未検証）"
fi

echo
if [[ $FAILED -eq 0 ]]; then
    echo "🎉 全テスト成功"
else
    echo "💥 失敗したテストがあります"
fi
exit $FAILED
