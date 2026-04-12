<?php
namespace Navne\Storage;

use Navne\Pipeline\Entity;

class SuggestionsTable {
	private static ?self $instance = null;

	public function __construct( private \wpdb $db ) {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}
		return self::$instance;
	}

	public function table_name(): string {
		return $this->db->prefix . 'navne_suggestions';
	}

	public static function create(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = $wpdb->prefix . 'navne_suggestions';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			entity_name varchar(255) NOT NULL,
			entity_type varchar(50) NOT NULL,
			confidence float NOT NULL DEFAULT 0,
			status enum('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY post_status (post_id, status)
		) {$charset_collate};" );
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_suggestions" );
	}

	/** @param Entity[] $entities */
	public function insert_entities( int $post_id, array $entities ): void {
		foreach ( $entities as $entity ) {
			$this->db->insert(
				$this->table_name(),
				[
					'post_id'     => $post_id,
					'entity_name' => $entity->name,
					'entity_type' => $entity->type,
					'confidence'  => $entity->confidence,
					'status'      => 'pending',
				],
				[ '%d', '%s', '%s', '%f', '%s' ]
			);
		}
	}

	public function find_by_post( int $post_id ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE post_id = %d ORDER BY confidence DESC",
				$post_id
			),
			ARRAY_A
		) ?? [];
	}

	public function find_by_id( int $id ): ?array {
		return $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id = %d",
				$id
			),
			ARRAY_A
		) ?: null;
	}

	public function update_status( int $id, string $status ): void {
		$this->db->update(
			$this->table_name(),
			[ 'status' => $status ],
			[ 'id'     => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public function delete_pending_for_post( int $post_id ): void {
		$this->db->delete(
			$this->table_name(),
			[ 'post_id' => $post_id, 'status' => 'pending' ],
			[ '%d', '%s' ]
		);
	}
}
