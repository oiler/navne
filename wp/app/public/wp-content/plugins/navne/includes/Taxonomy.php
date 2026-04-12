<?php
// includes/Taxonomy.php
namespace Navne;

class Taxonomy {
	public static function register_hooks(): void {
		add_action( 'init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_taxonomy( 'navne_entity', [ 'post' ], [
			'labels'            => [
				'name'          => 'Entities',
				'singular_name' => 'Entity',
			],
			'public'            => true,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => 'entity' ],
		] );
	}
}
