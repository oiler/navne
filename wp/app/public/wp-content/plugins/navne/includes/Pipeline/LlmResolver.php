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

	private function build_prompt( \WP_Post $post, string $extracted, string $definition_list = '' ): string {
		$def_section = $definition_list ?: '(No organization-specific definitions configured.)';
		return <<<PROMPT
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
{$def_section}

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
		// Strip markdown code fences (Claude sometimes wraps JSON even when asked not to).
		$json = preg_replace( '/^```(?:json)?\s*/m', '', $response );
		$json = preg_replace( '/^```\s*$/m', '', $json );
		$json = trim( $json );

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new PipelineException( "LLM returned invalid JSON: {$json}" );
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
