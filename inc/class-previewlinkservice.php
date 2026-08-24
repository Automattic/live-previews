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
	 * @param int      $post_id     Post to preview.
	 * @param int      $ttl_seconds How long the link stays valid, in seconds.
	 * @param int|null $max_uses    Maximum distinct viewers, or null for unlimited.
	 * @param int      $created_by  ID of the user issuing the link.
	 */
	public function mint( int $post_id, int $ttl_seconds, ?int $max_uses, int $created_by ): Token {
		$token = Token::generate();
		$now   = $this->clock->now();

		$this->repository->save(
			PreviewLink::issue(
				$post_id,
				$token,
				$now + $ttl_seconds,
				$max_uses,
				$created_by,
				$now
			)
		);

		return $token;
	}

	/**
	 * Decide whether a token may view a post.
	 *
	 * Pure query: it never mutates the link. Counting a viewer is the distinct
	 * {@see PreviewLinkService::record_visit()} command, run once per request by
	 * the gate, so a single page load that fires several queries cannot exhaust
	 * its own link mid-render.
	 *
	 * @param bool $viewer_already_counted Whether this viewer already holds a slot.
	 */
	public function authorize( int $post_id, Token $candidate, bool $viewer_already_counted = false ): AccessDecision {
		$link = $this->repository->find( $post_id, $candidate );

		return $this->policy->decide( $link, $this->clock->now(), $viewer_already_counted );
	}

	/**
	 * Count one new viewer against the link, if it still exists. Idempotency (not
	 * counting the same human twice) is the gate's responsibility, since that
	 * turns on request-scoped signals like cookies.
	 */
	public function record_visit( int $post_id, Token $candidate ): void {
		$link = $this->repository->find( $post_id, $candidate );

		if ( null !== $link ) {
			$this->repository->record_use( $link );
		}
	}
}
