<?php

declare(strict_types = 1);

namespace Automattic\LivePreviews;

use WP_UnitTestCase;

/**
 * The revoke actions behind the site-wide admin table: a nonce-checked row
 * action and a nonce-checked bulk action, both writing revocation to the link.
 *
 * @covers \Automattic\LivePreviews\PreviewLinksAdminPage
 */
class PreviewLinksAdminPageTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private PreviewLinksAdminPage $page;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService( $this->repository, new AccessPolicy(), new SystemClock() );
		$this->page       = new PreviewLinksAdminPage( $this->service, new SystemClock() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		unset(
			$_GET['action'],
			$_GET['post'],
			$_GET['token'],
			$_GET['_wpnonce'],
			$_REQUEST['action'],
			$_REQUEST['_wpnonce'],
			$_POST['action'],
			$_POST['links'],
			$_POST['_wpnonce']
		);

		parent::tear_down();
	}

	public function test_an_ordinary_view_carries_no_revoke(): void {
		static::assertNull( $this->page->process_request() );
	}

	public function test_a_row_action_revokes_one_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, get_current_user_id() );
		$hash = $this->repository->all_for_post( $post_id )[0]->token_hash();

		$nonce                = wp_create_nonce( 'live_previews_revoke_' . $post_id . '_' . $hash );
		$_GET['action']       = 'revoke';
		$_GET['post']         = (string) $post_id;
		$_GET['token']        = $hash;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 1, $this->page->process_request() );

		$link = $this->repository->find_by_hash( $post_id, $hash );
		static::assertNotNull( $link );
		static::assertTrue( $link->is_revoked() );
	}

	public function test_a_bulk_action_revokes_every_selected_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$selected = [];
		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			$selected[] = $post_id . ':' . $link->token_hash();
		}

		$nonce                = wp_create_nonce( 'bulk-' . PreviewLinksListTable::PLURAL );
		$_REQUEST['action']   = 'revoke';
		$_POST['action']      = 'revoke';
		$_POST['links']       = $selected;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 2, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}
}
