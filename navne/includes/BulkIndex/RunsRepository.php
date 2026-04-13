<?php
// includes/BulkIndex/RunsRepository.php
namespace Navne\BulkIndex;

class RunsRepository {
	private static ?self $instance = null;

	public function __construct( private $db ) {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			global $wpdb;
			self::$instance = new self( $wpdb );
		}
		return self::$instance;
	}

	public function table_name(): string {
		return $this->db->prefix . "navne_bulk_runs";
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$table           = $wpdb->prefix . "navne_bulk_runs";
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_type varchar(20) NOT NULL,
			mode varchar(20) NOT NULL,
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			parent_run_id bigint(20) unsigned DEFAULT NULL,
			total int unsigned NOT NULL DEFAULT 0,
			processed int unsigned NOT NULL DEFAULT 0,
			failed int unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};" );
	}

	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_bulk_runs" );
	}

	public function create( array $data ): int {
		$result = $this->db->insert(
			$this->table_name(),
			[
				"run_type"      => $data["run_type"],
				"mode"          => $data["mode"],
				"date_from"     => $data["date_from"],
				"date_to"       => $data["date_to"],
				"parent_run_id" => $data["parent_run_id"],
				"total"         => (int) $data["total"],
				"processed"     => 0,
				"failed"        => 0,
				"status"        => $data["total"] > 0 ? "pending" : "complete",
				"created_by"    => (int) $data["created_by"],
			],
			[ "%s", "%s", "%s", "%s", "%d", "%d", "%d", "%d", "%s", "%d" ]
		);
		if ( false === $result ) {
			return 0;
		}
		return (int) $this->db->insert_id;
	}

	public function find_by_id( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function update_status( int $id, string $status ): void {
		$this->db->update(
			$this->table_name(),
			[ "status" => $status ],
			[ "id"     => $id ],
			[ "%s" ],
			[ "%d" ]
		);
	}

	/** Sets processed and failed to the given absolute values (not deltas). */
	public function update_counts( int $id, int $processed, int $failed ): void {
		$this->db->update(
			$this->table_name(),
			[ "processed" => $processed, "failed" => $failed ],
			[ "id"        => $id ],
			[ "%d", "%d" ],
			[ "%d" ]
		);
	}

	public function find_recent( int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_name()} ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return $rows ?: [];
	}
}
