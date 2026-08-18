<?php

namespace Adminer;

use Google\Auth\FetchAuthTokenInterface;

/**
 * 取得済みの OAuth2 アクセストークンをそのまま供給する認証フェッチャ
 *
 * google/cloud-bigquery の credentialsFetcher オプションに渡して使う。
 * \Google\Client (google/apiclient) は依存関係に含まれないため、
 * 依存に含まれる google/auth のインターフェースで代替する。
 *
 * トークンの更新は行わない。期限切れ時は Cookie が消えるため、
 * Db::initiateOAuth2Flow() により OAuth2 フローが再実行される。
 */
class OAuth2AccessTokenFetcher implements FetchAuthTokenInterface {
	/** @var string */
	private $accessToken;

	public function __construct(string $accessToken) {
		$this->accessToken = $accessToken;
	}

	/**
	 * @param callable|null $httpHandler
	 * @return array<mixed>
	 */
	public function fetchAuthToken(?callable $httpHandler = null) {
		return array('access_token' => $this->accessToken);
	}

	/**
	 * トークンを取得し直す手段がないためキャッシュしない
	 * @return string
	 */
	public function getCacheKey() {
		return '';
	}

	/** @return array<mixed> */
	public function getLastReceivedToken() {
		return array('access_token' => $this->accessToken);
	}
}
