<?php
// includes/ContentFilter.php
namespace Navne;

class ContentFilter {
	public function filter( string $content ): string {
		if ( ! in_the_loop() || ! is_singular() ) {
			return $content;
		}
		$post_id  = get_the_ID();
		$link_map = wp_cache_get( 'navne_link_map_' . $post_id, 'navne' );
		if ( false === $link_map ) {
			$link_map = $this->build_link_map( $post_id );
			wp_cache_set( 'navne_link_map_' . $post_id, $link_map, 'navne' );
		}
		if ( empty( $link_map ) ) {
			return $content;
		}
		return $this->apply_links( $content, $link_map );
	}

	private function build_link_map( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, 'navne_entity' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}
		$map = [];
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( ! is_wp_error( $url ) ) {
				$map[ $term->name ] = $url;
			}
		}
		return $map;
	}

	private function apply_links( string $content, array $link_map ): string {
		foreach ( $link_map as $name => $url ) {
			// Split by existing <a> tags so we never replace inside an existing link.
			$parts    = preg_split( '/(<a[^>]*>.*?<\/a>)/si', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$replaced = false;
			foreach ( $parts as $i => $part ) {
				if ( $replaced || $i % 2 !== 0 ) {
					continue; // Skip odd indices (captured existing links) and after first replacement.
				}
				$escaped  = preg_quote( $name, '/' );
				$new_part = preg_replace(
					'/\b' . $escaped . '\b/u',
					'<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>',
					$part,
					1,
					$count
				);
				if ( $count > 0 ) {
					$parts[ $i ] = $new_part;
					$replaced    = true;
				}
			}
			$content = implode( '', $parts );
		}
		return $content;
	}
}
