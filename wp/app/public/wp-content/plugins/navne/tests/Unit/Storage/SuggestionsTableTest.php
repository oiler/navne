<?php
namespace Navne\Tests\Unit\Storage;

use Navne\Pipeline\Entity;
use Navne\Storage\SuggestionsTable;
use Navne\Tests\Unit\TestCase;

class SuggestionsTableTest extends TestCase {
	private function make_db( array $expectations = [] ): \wpdb {
		$db         = $this->createMock( \wpdb::class );
		$db->prefix = 'wp_';
		return $db;
	}

	public function test_table_name_uses_prefix(): void {
		$db = $this->make_db();
		$this->assertSame( 'wp_navne_suggestions', ( new SuggestionsTable( $db ) )->table_name() );
	}

	public function test_insert_entities_calls_db_insert_once_per_entity(): void {
		$db = $this->make_db();
		$db->expects( $this->exactly( 2 ) )->method( 'insert' );

		$table = new SuggestionsTable( $db );
		$table->insert_entities( 1, [
			new Entity( 'Jane Smith', 'person', 0.94 ),
			new Entity( 'NATO',       'org',    0.99 ),
		] );
	}

	public function test_update_status_calls_db_update(): void {
		$db = $this->make_db();
		$db->expects( $this->once() )
		   ->method( 'update' )
		   ->with(
				$this->anything(),
				[ 'status' => 'approved' ],
				[ 'id' => 7 ]
		   );

		( new SuggestionsTable( $db ) )->update_status( 7, 'approved' );
	}

	public function test_delete_pending_for_post_calls_db_delete(): void {
		$db = $this->make_db();
		$db->expects( $this->once() )
		   ->method( 'delete' )
		   ->with(
				$this->anything(),
				[ 'post_id' => 5, 'status' => 'pending' ]
		   );

		( new SuggestionsTable( $db ) )->delete_pending_for_post( 5 );
	}

	public function test_find_approved_names_for_post_returns_lowercase_names(): void {
		$db = $this->make_db();
		$db->method( 'prepare' )->willReturn( 'prepared_sql' );
		$db->method( 'get_col' )->willReturn( [ 'Jane Smith', 'NATO' ] );

		$result = ( new SuggestionsTable( $db ) )->find_approved_names_for_post( 1 );

		$this->assertSame( [ 'jane smith', 'nato' ], $result );
	}

	public function test_insert_entities_uses_provided_status(): void {
		$db = $this->make_db();
		$db->expects( $this->once() )
		   ->method( 'insert' )
		   ->with(
				$this->anything(),
				$this->callback( fn( array $data ) => $data['status'] === 'approved' )
		   );

		( new SuggestionsTable( $db ) )->insert_entities(
			1,
			[ new Entity( 'NATO', 'org', 0.99 ) ],
			'approved'
		);
	}
}
