<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews\Tests\Support;

use Automattic\LivePreviews\PreviewLink;
use Automattic\LivePreviews\Token;
use Automattic\LivePreviews\TokenRepository;

/**
 * In-memory {@see TokenRepository} for unit tests. Mirrors the postmeta adapter's
 * contract (multiple links per post, matched by token hash) without a database.
 */
final class InMemoryTokenRepository implements TokenRepository {
	/** @var array<int, list<PreviewLink>> Links keyed by post ID. */
	private array $links = [];

	public function save( PreviewLink $link ): void {
		$this->links[ $link->post_id() ][] = $link;
	}

	public function find( int $post_id, Token $candidate ): ?PreviewLink {
		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			if ( $link->matches( $candidate ) ) {
				return $link;
			}
		}

		return null;
	}

	public function all_for_post( int $post_id ): array {
		return $this->links[ $post_id ] ?? [];
	}
}
