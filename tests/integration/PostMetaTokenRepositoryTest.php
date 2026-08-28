<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_UnitTestCase;

/**
 * The cross-post listing queries that back the site-wide admin table.
 *
 * @covers \Automattic\LivePreviews\PostMetaTokenRepository
 */
class PostMetaTokenRepositoryTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
	}

	public function test_counts_links_across_every_post(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $first, 'aaaa' );
		$this->save_link( $first, 'bbbb' );
		$this->save_link( $second, 'cccc' );

		static::assertSame( 3, $this->repository->count_links() );
	}

	public function test_pages_links_newest_first(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// Saved oldest to newest; the table shows newest first (meta_id descending).
		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );
		$this->save_link( $post, 'cccc' );

		$first_page = $this->repository->page_of_links( 0, 2 );

		static::assertCount( 2, $first_page );
		static::assertSame( 'cccc', $first_page[0]->token_hint() );
		static::assertSame( 'bbbb', $first_page[1]->token_hint() );

		$second_page = $this->repository->page_of_links( 2, 2 );

		static::assertCount( 1, $second_page );
		static::assertSame( 'aaaa', $second_page[0]->token_hint() );
	}

	public function test_hydrates_the_stored_fields(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save(
			new PreviewLink( $post, 'a-hash', 2000, 5, 7, 1000, [], null, 'ab12' )
		);

		$links = $this->repository->page_of_links( 0, 10 );

		static::assertCount( 1, $links );
		static::assertSame( $post, $links[0]->post_id() );
		static::assertSame( 'a-hash', $links[0]->token_hash() );
		static::assertSame( 5, $links[0]->max_uses() );
		static::assertSame( 7, $links[0]->created_by() );
		static::assertSame( 'ab12', $links[0]->token_hint() );
	}

	public function test_is_empty_without_links(): void {
		static::assertSame( 0, $this->repository->count_links() );
		static::assertSame( [], $this->repository->page_of_links( 0, 10 ) );
	}

	private function save_link( int $post_id, string $hint ): void {
		$this->repository->save(
			new PreviewLink(
				$post_id,
				Token::generate()->hash(),
				time() + HOUR_IN_SECONDS,
				null,
				1,
				time(),
				[],
				null,
				$hint
			)
		);
	}
}
