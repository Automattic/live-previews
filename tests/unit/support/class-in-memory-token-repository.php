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

	public function record_use( PreviewLink $link ): void {
		$this->replace( $link, $link->with_recorded_use() );
	}

	public function find_by_hash( int $post_id, string $token_hash ): ?PreviewLink {
		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			if ( hash_equals( $link->token_hash(), $token_hash ) ) {
				return $link;
			}
		}

		return null;
	}

	public function revoke( PreviewLink $link, int $revoked_at ): void {
		$this->replace( $link, $link->with_revoked( $revoked_at ) );
	}

	public function delete_all_for_post( int $post_id ): void {
		unset( $this->links[ $post_id ] );
	}

	private function replace( PreviewLink $old, PreviewLink $replacement ): void {
		$post_id = $old->post_id();

		foreach ( $this->links[ $post_id ] ?? [] as $index => $stored ) {
			if ( hash_equals( $stored->token_hash(), $old->token_hash() ) ) {
				$links           = $this->links[ $post_id ];
				$links[ $index ] = $replacement;
				// Rebuild via array_values so the property keeps its list<> shape.
				$this->links[ $post_id ] = array_values( $links );
				return;
			}
		}
	}
}
