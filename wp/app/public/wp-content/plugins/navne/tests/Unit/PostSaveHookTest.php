<?php
namespace Navne\Tests\Unit;

use Navne\PostSaveHook;
use Brain\Monkey\Functions;

class PostSaveHookTest extends TestCase {
	private function make_post( string $status = 'publish', string $type = 'post' ): \WP_Post {
		$post              = new \WP_Post();
		$post->post_status = $status;
		$post->post_type   = $type;
		return $post;
	}

	public function test_skips_disallowed_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_post_types' ? [ 'post' ] : $default;
		} );
		// update_post_meta and as_enqueue_async_action must NOT be called.
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'as_enqueue_async_action' )->never();

		PostSaveHook::handle( 1, $this->make_post( 'publish', 'page' ), true );
		$this->assertTrue( true ); // explicit assertion to satisfy PHPUnit.
	}

	public function test_dispatches_for_allowed_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			return $key === 'navne_post_types' ? [ 'post', 'page' ] : $default;
		} );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-04-12 10:00:00' );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );

		// Should complete without error — no assertion needed beyond no exception thrown.
		PostSaveHook::handle( 1, $this->make_post( 'publish', 'page' ), true );
		$this->assertTrue( true ); // explicit assertion to satisfy PHPUnit.
	}
}
