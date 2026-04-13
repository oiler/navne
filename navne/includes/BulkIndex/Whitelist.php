<?php
// includes/BulkIndex/Whitelist.php
namespace Navne\BulkIndex;

class Whitelist {
	private static ?self $cached = null;

	private array $names; // lowercased names as hash map: [name => true]

	private function __construct( array $names ) {
		$this->names = [];
		foreach ( $names as $name ) {
			$this->names[ strtolower( (string) $name ) ] = true;
		}
	}

	public static function current(): self {
		if ( self::$cached !== null ) {
			return self::$cached;
		}
		$terms = get_terms( [
			"taxonomy"   => "navne_entity",
			"hide_empty" => false,
			"fields"     => "names",
		] );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			$terms = [];
		}
		self::$cached = new self( $terms );
		return self::$cached;
	}

	public static function reset(): void {
		self::$cached = null;
	}

	public function contains( string $name ): bool {
		return isset( $this->names[ strtolower( $name ) ] );
	}

	public function is_empty(): bool {
		return empty( $this->names );
	}
}
