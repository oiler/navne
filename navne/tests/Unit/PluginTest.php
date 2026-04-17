<?php
namespace Navne\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Navne\BulkIndex\Dispatcher;
use Navne\Jobs\ProcessPostJob;
use Navne\Plugin;

class PluginTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Plugin::init constructs controllers whose singletons grab $GLOBALS['wpdb'].
		// Provide a stub (the bootstrap-defined wpdb class) so type-hinted constructors
		// don't receive null.
		$GLOBALS["wpdb"] = new \wpdb();
		Functions\when( "register_rest_route" )->justReturn( true );
		Functions\when( "add_filter" )->justReturn( true );
		Functions\when( "register_activation_hook" )->justReturn( true );
	}

	public function test_init_registers_process_post_hook_with_two_accepted_args(): void {
		Actions\expectAdded( "navne_process_post" )
			->once()
			->with( [ ProcessPostJob::class, "run" ], 10, 2 );

		Plugin::init();

		// Brain\Monkey verifies the expectation on tearDown; add an explicit
		// assertion so PHPUnit doesn't flag the test as risky.
		$this->addToAssertionCount( 1 );
	}

	public function test_init_registers_bulk_dispatch_hook(): void {
		Actions\expectAdded( "navne_bulk_dispatch" )
			->once()
			->with( [ Dispatcher::class, "run" ] );

		Plugin::init();

		// Brain\Monkey verifies the expectation on tearDown; add an explicit
		// assertion so PHPUnit doesn't flag the test as risky.
		$this->addToAssertionCount( 1 );
	}
}
