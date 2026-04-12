<?php
namespace Navne\Tests\Unit\Pipeline;

use Navne\Exception\PipelineException;
use Navne\Pipeline\Entity;
use Navne\Pipeline\LlmResolver;
use Navne\Provider\ProviderInterface;
use Navne\Tests\Unit\TestCase;

class LlmResolverTest extends TestCase {
	private function make_post( string $title = 'Test', string $content = 'Body' ): \WP_Post {
		$post               = new \WP_Post();
		$post->post_title   = $title;
		$post->post_content = $content;
		return $post;
	}

	public function test_resolve_returns_entity_array(): void {
		$json     = json_encode( [
			[ 'name' => 'Jane Smith', 'type' => 'person', 'confidence' => 0.94 ],
			[ 'name' => 'NATO',       'type' => 'org',    'confidence' => 0.99 ],
		] );
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$resolver = new LlmResolver( $provider );
		$entities = $resolver->resolve( $this->make_post(), 'some extracted text' );

		$this->assertCount( 2, $entities );
		$this->assertInstanceOf( Entity::class, $entities[0] );
		$this->assertSame( 'Jane Smith', $entities[0]->name );
		$this->assertSame( 'person',     $entities[0]->type );
		$this->assertSame( 0.94,         $entities[0]->confidence );
	}

	public function test_resolve_strips_markdown_code_fences(): void {
		$json     = "```json\n[{\"name\":\"Ukraine\",\"type\":\"place\",\"confidence\":0.97}]\n```";
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$entities = ( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'Ukraine', $entities[0]->name );
	}

	public function test_resolve_throws_pipeline_exception_on_invalid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( 'not json at all' );

		$this->expectException( PipelineException::class );
		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
	}

	public function test_resolve_skips_malformed_entries(): void {
		$json     = json_encode( [
			[ 'name' => 'Good Entity', 'type' => 'person', 'confidence' => 0.9 ],
			[ 'name' => 'Missing type and confidence' ],
		] );
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( $json );

		$entities = ( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );

		$this->assertCount( 1, $entities );
	}

	public function test_prompt_contains_post_title_and_extracted_content(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
				 ->method( 'complete' )
				 ->with(
					 $this->logicalAnd(
						 $this->stringContains( 'NATO Summit Recap' ),
						 $this->stringContains( 'extracted content here' )
					 )
				 )
				 ->willReturn( '[]' );

		( new LlmResolver( $provider ) )->resolve(
			$this->make_post( 'NATO Summit Recap' ),
			'extracted content here'
		);
	}

	public function test_resolve_throws_on_json_object_instead_of_array(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'complete' )->willReturn( '{"name":"Jane","type":"person","confidence":0.9}' );

		$this->expectException( PipelineException::class );
		( new LlmResolver( $provider ) )->resolve( $this->make_post(), '' );
	}
}
