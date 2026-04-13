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
		$this->addToAssertionCount( 1 );
	}

	public function test_dispatches_for_allowed_post_type(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			if ( 'navne_post_types' === $key ) return [ 'post', 'page' ];
			if ( 'navne_operating_mode' === $key ) return 'suggest';
			return $default;
		} );
		Functions\expect( 'update_post_meta' )->twice();
		Functions\when( 'current_time' )->justReturn( '2026-04-12 10:00:00' );
		Functions\expect( 'as_enqueue_async_action' )->once()->andReturn( 1 );

		PostSaveHook::handle( 1, $this->make_post( 'publish', 'page' ), true );
		$this->addToAssertionCount( 1 );
	}

	public function test_skips_dispatch_in_safe_mode(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( function ( string $key, mixed $default = null ) {
			if ( 'navne_post_types' === $key ) return [ 'post' ];
			if ( 'navne_operating_mode' === $key ) return 'safe';
			return $default;
		} );
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'as_enqueue_async_action' )->never();

		PostSaveHook::handle( 1, $this->make_post( 'publish', 'post' ), true );
		$this->addToAssertionCount( 1 );
	}
}
