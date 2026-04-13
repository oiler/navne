<?php
// tests/Unit/Pipeline/EntityPipelineTest.php
namespace Navne\Tests\Unit\Pipeline;

use Navne\Pipeline\Entity;
use Navne\Pipeline\EntityPipeline;
use Navne\Pipeline\ExtractorInterface;
use Navne\Pipeline\ResolverInterface;
use Navne\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

class EntityPipelineTest extends TestCase {
	public function test_run_returns_entities_from_resolver(): void {
		$post        = new \WP_Post();
		$post->ID    = 42;
		$expected    = [ new Entity( 'Jane Smith', 'person', 0.95 ) ];
		$extractor   = $this->createMock( ExtractorInterface::class );
		$resolver    = $this->createMock( ResolverInterface::class );

		Functions\when( 'get_post' )->justReturn( $post );
		$extractor->expects( $this->once() )->method( 'extract' )->with( $post )->willReturn( 'extracted text' );
		$resolver->expects( $this->once() )->method( 'resolve' )->with( $post, 'extracted text' )->willReturn( $expected );

		$pipeline = new EntityPipeline( $extractor, $resolver );
		$result   = $pipeline->run( 42 );

		$this->assertSame( $expected, $result );
	}

	public function test_run_returns_empty_array_when_post_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$pipeline = new EntityPipeline(
			$this->createMock( ExtractorInterface::class ),
			$this->createMock( ResolverInterface::class )
		);

		$this->assertSame( [], $pipeline->run( 999 ) );
	}
}
