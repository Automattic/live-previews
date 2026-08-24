<?php

namespace Automattic\LivePreviews;

/**
 * The outcome of {@see AccessPolicy::decide()}: allow or deny, plus a machine
 * reason so the request-time gate can log it and show the visitor an appropriate
 * page ("this link has expired" vs a bare 404).
 */
final class AccessDecision {
	public const REASON_ALLOWED        = 'allowed';
	public const REASON_NOT_FOUND      = 'not_found';
	public const REASON_TOKEN_MISMATCH = 'token_mismatch';
	public const REASON_EXPIRED        = 'expired';
	public const REASON_REVOKED        = 'revoked';
	public const REASON_EXHAUSTED      = 'exhausted';

	private bool $allowed;
	private string $reason;

	private function __construct( bool $allowed, string $reason ) {
		$this->allowed = $allowed;
		$this->reason  = $reason;
	}

	public static function allow(): self {
		return new self( true, self::REASON_ALLOWED );
	}

	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}

	public function is_allowed(): bool {
		return $this->allowed;
	}

	public function reason(): string {
		return $this->reason;
	}
}
