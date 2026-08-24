<?php

namespace Automattic\LivePreviews;

/**
 * An issued preview link: the record that lets an unauthenticated visitor view a
 * single non-public post, subject to expiry, a cap on the number of distinct
 * viewers, and (later) revocation.
 *
 * Holds only the token *hash*, never the plaintext. All the questions the access
 * rules need to ask are pure methods here, so {@see AccessPolicy} can be tested
 * without WordPress or a database.
 */
final class PreviewLink {
	private int $post_id;
	private string $token_hash;
	private int $expires_at;

	/** @var int|null Maximum distinct viewers, or null for unlimited. */
	private ?int $max_uses;

	private int $created_by;
	private int $created_at;

	/** @var int Distinct viewers counted so far. */
	private int $use_count;

	/** @var int|null Unix timestamp of revocation, or null if still live. */
	private ?int $revoked_at;

	public function __construct(
		int $post_id,
		string $token_hash,
		int $expires_at,
		?int $max_uses,
		int $created_by,
		int $created_at,
		int $use_count = 0,
		?int $revoked_at = null
	) {
		$this->post_id    = $post_id;
		$this->token_hash = $token_hash;
		$this->expires_at = $expires_at;
		$this->max_uses   = $max_uses;
		$this->created_by = $created_by;
		$this->created_at = $created_at;
		$this->use_count  = $use_count;
		$this->revoked_at = $revoked_at;
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
			$created_at
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

	public function use_count(): int {
		return $this->use_count;
	}

	public function revoked_at(): ?int {
		return $this->revoked_at;
	}

	/**
	 * Whether a token presented by a visitor is the one this link was issued for.
	 * Constant-time to avoid leaking the hash a character at a time.
	 */
	public function matches( Token $candidate ): bool {
		return hash_equals( $this->token_hash, $candidate->hash() );
	}

	public function is_expired( int $now ): bool {
		return $now >= $this->expires_at;
	}

	public function is_revoked(): bool {
		return null !== $this->revoked_at;
	}

	/**
	 * Whether every allowed viewer slot has been spent. Always false for an
	 * unlimited link.
	 */
	public function is_exhausted(): bool {
		return null !== $this->max_uses && $this->use_count >= $this->max_uses;
	}

	/**
	 * A copy of this link with one more viewer counted. Immutable: the caller
	 * persists the returned instance.
	 */
	public function with_recorded_use(): self {
		return new self(
			$this->post_id,
			$this->token_hash,
			$this->expires_at,
			$this->max_uses,
			$this->created_by,
			$this->created_at,
			$this->use_count + 1,
			$this->revoked_at
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
			$this->use_count,
			$revoked_at
		);
	}
}
