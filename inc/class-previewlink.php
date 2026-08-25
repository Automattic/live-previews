<?php

namespace Automattic\LivePreviews;

/**
 * An issued preview link: the record that lets an unauthenticated visitor view a
 * single non-public post, subject to expiry, a cap on the number of distinct
 * viewers, and revocation.
 *
 * Holds only the token *hash*, never the plaintext. All the questions the access
 * rules need to ask are pure methods here, so {@see AccessPolicy} can be tested
 * without WordPress or a database.
 *
 * The viewer cap is modelled as a set of opaque, server-issued viewer IDs rather
 * than a counter. That matters for two reasons. A visitor cannot mint themselves
 * a slot, because the ID is 128 bits of server randomness and is only ever handed
 * out by {@see PreviewLinkService::claim_slot()}. And "add an ID to a set" is
 * idempotent, so a retried write cannot double-count where an increment would.
 */
final class PreviewLink {
	private int $post_id;
	private string $token_hash;
	private int $expires_at;

	/** @var int|null Maximum distinct viewers, or null for unlimited. */
	private ?int $max_uses;

	private int $created_by;
	private int $created_at;

	/** @var list<string> Opaque IDs of the viewers holding a slot on this link. */
	private array $viewers;

	/** @var int|null Unix timestamp of revocation, or null if still live. */
	private ?int $revoked_at;

	/** @var string Last few characters of the token, to identify a link in the UI. */
	private string $token_hint;

	/**
	 * @param list<string> $viewers Opaque IDs of viewers already holding a slot.
	 */
	public function __construct(
		int $post_id,
		string $token_hash,
		int $expires_at,
		?int $max_uses,
		int $created_by,
		int $created_at,
		array $viewers = [],
		?int $revoked_at = null,
		string $token_hint = ''
	) {
		$this->post_id    = $post_id;
		$this->token_hash = $token_hash;
		$this->expires_at = $expires_at;
		$this->max_uses   = $max_uses;
		$this->created_by = $created_by;
		$this->created_at = $created_at;
		$this->viewers    = $viewers;
		$this->revoked_at = $revoked_at;
		$this->token_hint = $token_hint;
	}

	/**
	 * Issue a brand-new link for a freshly generated token.
	 *
	 * @param int|null $max_uses Maximum distinct viewers, or null for unlimited.
	 */
	public static function issue(
		int $post_id,
		Token $token,
		int $expires_at,
		?int $max_uses,
		int $created_by,
		int $created_at
	): self {
		return new self(
			$post_id,
			$token->hash(),
			$expires_at,
			$max_uses,
			$created_by,
			$created_at,
			[],
			null,
			substr( $token->value(), -4 )
		);
	}

	public function post_id(): int {
		return $this->post_id;
	}

	public function token_hash(): string {
		return $this->token_hash;
	}

	public function expires_at(): int {
		return $this->expires_at;
	}

	public function max_uses(): ?int {
		return $this->max_uses;
	}

	public function created_by(): int {
		return $this->created_by;
	}

	public function created_at(): int {
		return $this->created_at;
	}

	/**
	 * How many distinct viewers hold a slot. Derived from the set rather than
	 * tracked separately, so it cannot drift from the slots actually issued.
	 */
	public function use_count(): int {
		return count( $this->viewers );
	}

	/**
	 * @return list<string>
	 */
	public function viewers(): array {
		return $this->viewers;
	}

	public function revoked_at(): ?int {
		return $this->revoked_at;
	}

	/**
	 * The last few characters of the token, safe to show in the editor so an
	 * author can tell one link from another and match it to a URL they shared.
	 */
	public function token_hint(): string {
		return $this->token_hint;
	}

	/**
	 * Whether a token presented by a visitor is the one this link was issued for.
	 * Constant-time to avoid leaking the hash a character at a time.
	 */
	public function matches( Token $candidate ): bool {
		return hash_equals( $this->token_hash, $candidate->hash() );
	}

	/**
	 * Whether this viewer ID is one this link handed out. An empty ID never
	 * matches, so a visitor cannot present a blank cookie and claim a slot.
	 */
	public function holds_slot( string $viewer_id ): bool {
		if ( '' === $viewer_id ) {
			return false;
		}

		foreach ( $this->viewers as $known ) {
			if ( hash_equals( $known, $viewer_id ) ) {
				return true;
			}
		}

		return false;
	}

	public function is_expired( int $now ): bool {
		return $now >= $this->expires_at;
	}

	public function is_revoked(): bool {
		return null !== $this->revoked_at;
	}

	/**
	 * Whether this link is finished with: expired, revoked, or both. Used by the
	 * garbage collector to decide what is safe to forget.
	 */
	public function is_dead( int $now ): bool {
		return $this->is_revoked() || $this->is_expired( $now );
	}

	/**
	 * The moment this link stopped being usable, or null if it is still live.
	 */
	public function dead_since( int $now ): ?int {
		if ( $this->is_revoked() ) {
			return $this->is_expired( $now )
				? min( (int) $this->revoked_at, $this->expires_at )
				: $this->revoked_at;
		}

		return $this->is_expired( $now ) ? $this->expires_at : null;
	}

	/**
	 * Whether every allowed viewer slot has been spent. Always false for an
	 * unlimited link.
	 */
	public function is_exhausted(): bool {
		return null !== $this->max_uses && $this->use_count() >= $this->max_uses;
	}

	/**
	 * A copy of this link with one more viewer holding a slot. Immutable: the
	 * caller persists the returned instance. Re-adding a known viewer is a no-op,
	 * so a retried write cannot spend two slots on one person.
	 */
	public function with_viewer( string $viewer_id ): self {
		if ( $this->holds_slot( $viewer_id ) ) {
			return $this;
		}

		return new self(
			$this->post_id,
			$this->token_hash,
			$this->expires_at,
			$this->max_uses,
			$this->created_by,
			$this->created_at,
			[ ...$this->viewers, $viewer_id ],
			$this->revoked_at,
			$this->token_hint
		);
	}

	/**
	 * A copy of this link revoked at the given moment. Immutable: the caller
	 * persists the returned instance.
	 */
	public function with_revoked( int $revoked_at ): self {
		return new self(
			$this->post_id,
			$this->token_hash,
			$this->expires_at,
			$this->max_uses,
			$this->created_by,
			$this->created_at,
			$this->viewers,
			$revoked_at,
			$this->token_hint
		);
	}
}
