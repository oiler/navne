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
		$definitions = $this->format_definitions( (string) get_option( 'navne_org_definitions', '' ) );
		return <<<PROMPT
You are an entity extraction assistant for a news organization.

[ORG DEFINITION LIST]
{$definitions}

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

	private function format_definitions( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '(No organization-specific definitions configured.)';
		}

		$lines  = explode( "\n", $raw );
		$output = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$term        = trim( substr( $line, 0, $colon ) );
			$description = trim( substr( $line, $colon + 1 ) );
			if ( '' === $term || '' === $description ) {
				continue;
			}
			$output[] = "{$term}: {$description}";
		}

		if ( empty( $output ) ) {
			return '(No organization-specific definitions configured.)';
		}

		return implode( "\n", $output );
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
