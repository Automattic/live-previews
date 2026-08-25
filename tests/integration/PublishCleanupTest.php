<?php
declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_UnitTestCase;

/**
 * @covers \Automattic\LivePreviews\PublishCleanup
 */
class PublishCleanupTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		( new PublishCleanup( $this->service ) )->register();
	}

	public function test_publishing_a_post_discards_its_preview_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		static::assertCount( 2, $this->repository->all_for_post( $post_id ) );

		wp_publish_post( $post_id );

		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_a_draft_saved_as_draft_keeps_its_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited while still a draft',
			]
		);

		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}
}
