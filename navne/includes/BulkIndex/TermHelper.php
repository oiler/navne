<?php
// includes/BulkIndex/TermHelper.php
namespace Navne\BulkIndex;

class TermHelper {
	public static function ensure_term( string $name ): int {
		$result = wp_insert_term( $name, "navne_entity" );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === "term_exists" ) {
				return (int) $result->get_error_data();
			}
			error_log( 'Navne bulk: failed to create term "' . $name . '": ' . $result->get_error_message() );
			return 0;
		}

		return (int) ( $result["term_id"] ?? 0 );
	}
}
