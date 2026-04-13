<?php
// includes/Plugin.php
namespace Navne;

use Navne\Admin\SettingsPage;
use Navne\Api\SuggestionsController;
use Navne\Jobs\ProcessPostJob;
use Navne\Pipeline\EntityPipeline;
use Navne\Pipeline\LlmResolver;
use Navne\Pipeline\PassthroughExtractor;
use Navne\Provider\ProviderFactory;

class Plugin {
	public static function init(): void {
		Taxonomy::register_hooks();
		SettingsPage::register_hooks();
		( new SuggestionsController() )->register_routes_on_init();
		add_action( 'save_post',           [ PostSaveHook::class,   'handle' ], 10, 3 );
		add_action( 'navne_process_post',  [ ProcessPostJob::class, 'run' ] );
		add_filter( 'the_content',         [ new ContentFilter(),   'filter' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class,   'enqueue_sidebar' ] );

		// Invalidate link cache when terms change.
		add_action( 'set_object_terms', [ self::class, 'invalidate_link_cache' ], 10, 2 );
		add_action( 'delete_term',      [ self::class, 'invalidate_term_cache' ] );
		add_action( 'edit_term',        [ self::class, 'invalidate_term_cache' ] );
	}

	public static function make_pipeline(): EntityPipeline {
		return new EntityPipeline(
			new PassthroughExtractor(),
			new LlmResolver( ProviderFactory::make() )
		);
	}

	public static function enqueue_sidebar(): void {
		$asset_file = include NAVNE_PLUGIN_DIR . 'assets/js/build/index.asset.php';
		wp_enqueue_script(
			'navne-sidebar',
			NAVNE_PLUGIN_URL . 'assets/js/build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);
	}

	public static function invalidate_link_cache( int $post_id ): void {
		wp_cache_delete( 'navne_link_map_' . $post_id, 'navne' );
	}

	public static function invalidate_term_cache(): void {
		// On term delete/rename we can't cheaply know which posts are affected —
		// flush the entire navne cache group. Fine for PoC scale.
		wp_cache_flush_group( 'navne' );
	}
}
