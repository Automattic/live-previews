<?php

namespace Automattic\LivePreviews;

/**
 * Application service: the one entry point the WordPress adapters call.
 *
 * The REST endpoint calls {@see PreviewLinkService::mint()} to issue a link; the
 * request-time gate calls {@see PreviewLinkService::authorize()} to decide
 * whether a visitor may see a draft, and {@see PreviewLinkService::claim_slot()}
 * to spend one of its viewer slots. All the collaborators are injected, so the
 * service is exercised in unit tests against an in-memory repository and a frozen
 * clock, with no WordPress and no database.
 */
final class PreviewLinkService {
	/**
	 * How many times to re-read and retry a slot claim that lost a write race.
	 * Each attempt only loses to a *different* visitor genuinely claiming a slot,
	 * so a handful is ample; the cap stops a pathological loop.
	 */
	private const CLAIM_ATTEMPTS = 5;

	/** Bytes of randomness in a viewer slot ID. 16 bytes = 128 bits. */
	private const VIEWER_ID_BYTES = 16;

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
	 * Every link issued for a post, for listing in the editor.
	 *
	 * @return list<PreviewLink>
	 */
	public function list_for_post( int $post_id ): array {
		return $this->repository->all_for_post( $post_id );
	}

	/**
	 * Revoke a link by its token hash. Returns false if no live link matched, so
	 * the caller can distinguish "revoked" from "nothing to revoke".
	 */
	public function revoke( int $post_id, string $token_hash ): bool {
		$link = $this->repository->find_by_hash( $post_id, $token_hash );

		if ( null === $link || $link->is_revoked() ) {
			return false;
		}

		$this->repository->revoke( $link, $this->clock->now() );

		return true;
	}

	/**
	 * Forget every link for a post. Called when a post is published or trashed
	 * and its preview links no longer mean anything.
	 */
	public function discard_all( int $post_id ): void {
		$this->repository->delete_all_for_post( $post_id );
	}

	/**
	 * Decide whether a token may view a post.
	 *
	 * Pure query: it never mutates the link. Spending a slot is the distinct
	 * {@see PreviewLinkService::claim_slot()} command, run once per request by the
	 * gate, so a single page load that fires several queries cannot exhaust its
	 * own link mid-render.
	 *
	 * @param string|null $viewer_id Slot ID this visitor presented, if any. It is
	 *                               only honoured when the link actually issued
	 *                               it, so a made-up value grants nothing.
	 */
	public function authorize( int $post_id, Token $candidate, ?string $viewer_id = null ): AccessDecision {
		$link = $this->repository->find( $post_id, $candidate );

		$holds_slot = null !== $link
			&& null !== $viewer_id
			&& $link->holds_slot( $viewer_id );

		return $this->policy->decide( $link, $this->clock->now(), $holds_slot );
	}

	/**
	 * Whether this visitor's slot ID is one the link actually issued.
	 *
	 * Asked separately from {@see PreviewLinkService::authorize()} because the
	 * gate needs the fact on its own: a visitor whose cookie is merely well-formed
	 * must still claim a slot, or a forged value would buy free, uncounted views.
	 */
	public function holds_slot( int $post_id, Token $candidate, ?string $viewer_id ): bool {
		if ( null === $viewer_id ) {
			return false;
		}

		$link = $this->repository->find( $post_id, $candidate );

		return null !== $link && $link->holds_slot( $viewer_id );
	}

	/**
	 * Spend one of the link's viewer slots and return the ID that now holds it,
	 * or null if there was nothing left to spend.
	 *
	 * The whole policy is re-evaluated here rather than trusting the gate's
	 * earlier decision, and the write is a compare-and-swap: between reading the
	 * link and writing it back, another visitor may have taken the last slot, or
	 * the author may have revoked the link entirely. A caller that loses the race
	 * gets null and must deny, which is what closes the check-then-act window.
	 */
	public function claim_slot( int $post_id, Token $candidate ): ?string {
		$viewer_id = bin2hex( random_bytes( self::VIEWER_ID_BYTES ) );

		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++ ) {
			$link = $this->repository->find( $post_id, $candidate );

			if ( null === $link ) {
				return null;
			}

			// No viewer ID passed: this is a brand-new slot, so the exhaustion
			// rule must apply in full.
			if ( ! $this->policy->decide( $link, $this->clock->now() )->is_allowed() ) {
				return null;
			}

			if ( $this->repository->add_viewer( $link, $viewer_id ) ) {
				return $viewer_id;
			}

			// Lost the write race to a concurrent visitor: re-read and re-decide.
		}

		return null;
	}

	/**
	 * Delete this post's links that died before the cutoff, returning how many
	 * went. Dead links are deliberately kept for a while so the gate can explain
	 * itself; this is what stops them accumulating forever.
	 */
	public function prune_dead( int $post_id, int $grace_seconds ): int {
		$now = $this->clock->now();

		return $this->repository->delete_dead_for_post( $post_id, $now - $grace_seconds, $now );
	}

	/**
	 * A batch of post IDs still carrying links, for the garbage collector.
	 *
	 * @return list<int>
	 */
	public function post_ids_with_links( int $after_post_id, int $limit ): array {
		return $this->repository->post_ids_with_links( $after_post_id, $limit );
	}

	/**
	 * A page of every issued link across the site, newest first, for the admin
	 * table.
	 *
	 * @return list<PreviewLink>
	 */
	public function page_of_links( int $offset, int $limit ): array {
		return $this->repository->page_of_links( $offset, $limit );
	}

	/**
	 * How many links exist across the site, for paginating the admin table.
	 */
	public function count_links(): int {
		return $this->repository->count_links();
	}
}
