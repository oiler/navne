<?php
namespace Navne\Pipeline;

use Navne\Exception\PipelineException;
use Navne\Provider\ProviderInterface;

class LlmResolver implements ResolverInterface {
	public function __construct( private ProviderInterface $provider ) {}

	/** @return Entity[] */
	public function resolve( \WP_Post $post, string $extracted ): array {
		$prompt   = $this->build_prompt( $post, $extracted );
		$response = $this->provider->complete( $prompt );
		return $this->parse_response( $response );
	}

	private function build_prompt( \WP_Post $post, string $extracted ): string {
		return <<<PROMPT
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
(No organization-specific definitions configured.)

Analyze the following article and return a JSON array of named entities.
For each entity include:
  - name (string): the canonical proper noun
  - type (string): person | org | place | other
  - confidence (float): 0.0–1.0

Only include proper nouns that are meaningful subjects or sources of the story.
Exclude passing historical references.

Article:
{$post->post_title}

{$extracted}

Respond with only a JSON array. No explanation.
PROMPT;
	}

	/** @return Entity[] */
	private function parse_response( string $response ): array {
		// Strip markdown code fences if the entire response is wrapped in one.
		$stripped = preg_replace( '/^```[a-zA-Z]*\r?\n([\s\S]*?)\n```\s*$/s', '$1', trim( $response ) );
		$json     = $stripped !== null ? $stripped : trim( $response );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new PipelineException( "LLM returned invalid JSON: {$json}" );
		}
		// Guard against LLM returning a JSON object instead of an array.
		if ( $data !== array_values( $data ) ) {
			throw new PipelineException( "LLM returned a JSON object instead of an array" );
		}

		$entities = [];
		foreach ( $data as $item ) {
			if ( ! isset( $item['name'], $item['type'], $item['confidence'] ) ) {
				continue;
			}
			$entities[] = new Entity(
				(string) $item['name'],
				(string) $item['type'],
				(float)  $item['confidence']
			);
		}
		return $entities;
	}
}
