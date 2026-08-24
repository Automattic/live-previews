<?php

namespace Automattic\LivePreviews;

/**
 * An issued preview link: the record that lets an unauthenticated visitor view a
 * single non-public post, subject to expiry (and, later, one-time use and
 * revocation).
 *
 * Holds only the token *hash*, never the plaintext. All the questions the access
 * rules need to ask are pure methods here, so {@see AccessPolicy} can be tested
 * without WordPress or a database.
 */
final class PreviewLink {
	private int $post_id;
	private string $token_hash;
	private int $expires_at;
	private bool $one_time_use;
	private int $created_by;
	private int $created_at;

	/** @var int|null Unix timestamp of first use, or null if never visited. */
	private ?int $used_at;

	/** @var int|null Unix timestamp of revocation, or null if still live. */
	private ?int $revoked_at;

	public function __construct(
		int $post_id,
		string $token_hash,
		int $expires_at,
		bool $one_time_use,
		int $created_by,
		int $created_at,
		?int $used_at = null,
		?int $revoked_at = null
	) {
		$this->post_id      = $post_id;
		$this->token_hash   = $token_hash;
		$this->expires_at   = $expires_at;
		$this->one_time_use = $one_time_use;
		$this->created_by   = $created_by;
		$this->created_at   = $created_at;
		$this->used_at      = $used_at;
		$this->revoked_at   = $revoked_at;
	}

	/**
	 * Issue a brand-new link for a freshly generated token.
	 */
	public static function issue(
		int $post_id,
		Token $token,
		int $expires_at,
		bool $one_time_use,
		int $created_by,
		int $created_at
	): self {
		return new self(
			$post_id,
			$token->hash(),
			$expires_at,
			$one_time_use,
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

	public function is_one_time_use(): bool {
		return $this->one_time_use;
	}

	public function created_by(): int {
		return $this->created_by;
	}

	public function created_at(): int {
		return $this->created_at;
	}

	public function used_at(): ?int {
		return $this->used_at;
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

	public function is_used(): bool {
		return null !== $this->used_at;
	}
}
