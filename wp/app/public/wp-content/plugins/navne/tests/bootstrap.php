<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WP class stubs — enough for unit tests without a WP install.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID            = 0;
		public string $post_title    = '';
		public string $post_content  = '';
		public string $post_status   = 'publish';
		public string $post_type     = 'post';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private mixed  $data = null
		) {}
		public function get_error_code(): string    { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed     { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params      = [];
		private array $json_params = [];
		public function get_param( string $key ): mixed          { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, mixed $v ): void { $this->params[ $key ] = $v; }
		public function get_json_params(): array                  { return $this->json_params; }
		public function set_json_params( array $p ): void         { $this->json_params = $p; }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct(
			public readonly mixed $data   = null,
			public readonly int   $status = 200
		) {}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public function prepare( string $q, mixed ...$args ): string { return vsprintf( str_replace( [ '%d', '%f', '%s' ], '%s', $q ), $args ); }
		public function get_charset_collate(): string                 { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
		public function insert( string $t, array $d, array $f = [] ): int|false { return 1; }
		public function update( string $t, array $d, array $w, array $f = [], array $wf = [] ): int|false { return 1; }
		public function delete( string $t, array $w, array $f = [] ): int|false { return 1; }
		public function get_results( string $q, string $o = 'OBJECT' ): array   { return []; }
		public function get_row( string $q, string $o = 'OBJECT', int $y = 0 ): mixed { return null; }
		public function query( string $q ): int|bool                             { return true; }
	}
}
