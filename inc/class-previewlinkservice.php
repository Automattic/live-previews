<?php

namespace Automattic\LivePreviews;

/**
 * Application service: the one entry point the WordPress adapters call.
 *
 * The REST endpoint calls {@see PreviewLinkService::mint()} to issue a link; the
 * request-time gate calls {@see PreviewLinkService::authorize()} to decide
 * whether a visitor may see a draft. All the collaborators are injected, so the
 * service is exercised in unit tests against an in-memory repository and a frozen
 * clock, with no WordPress and no database.
 */
final class PreviewLinkService {
	private TokenRepository $repository;
	private AccessPolicy $policy;
	private Clock $clock;

	public function __construct(
		TokenRepository $repository,
		AccessPolicy $policy,
		Clock $clock
	) {
		$this->repository = $repository;
		$this->policy     = $policy;
		$this->clock      = $clock;
	}

	/**
	 * Issue a preview link for a post and return the plaintext token.
	 *
	 * The returned token is the only moment the secret exists outside the URL;
	 * the persisted record keeps only its hash. The caller (the REST adapter)
	 * builds the shareable URL from the post ID and this token.
	 *
	 * @param int  $post_id      Post to preview.
	 * @param int  $ttl_seconds  How long the link stays valid, in seconds.
	 * @param bool $one_time_use Whether the link expires after its first visit.
	 * @param int  $created_by   ID of the user issuing the link.
	 */
	public function mint( int $post_id, int $ttl_seconds, bool $one_time_use, int $created_by ): Token {
		$token = Token::generate();
		$now   = $this->clock->now();

		$this->repository->save(
			PreviewLink::issue(
				$post_id,
				$token,
				$now + $ttl_seconds,
				$one_time_use,
				$created_by,
				$now
			)
		);

		return $token;
	}

	/**
	 * Decide whether a token may view a post.
	 *
	 * Pure query: it never mutates the link. Consuming a one-time link is a
	 * distinct command run once per request by the gate, so a single page load
	 * that fires several queries cannot exhaust its own link mid-render.
	 */
	public function authorize( int $post_id, Token $candidate ): AccessDecision {
		$link = $this->repository->find( $post_id, $candidate );

		return $this->policy->decide( $link, $this->clock->now() );
	}
}
