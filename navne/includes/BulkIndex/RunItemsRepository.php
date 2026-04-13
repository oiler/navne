<?php
// includes/BulkIndex/RunItemsRepository.php
namespace Navne\BulkIndex;

class RunItemsRepository {
	private const INSERT_CHUNK = 500;

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
		return $this->db->prefix . "navne_bulk_run_items";
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . "wp-admin/includes/upgrade.php";
		$table           = $wpdb->prefix . "navne_bulk_run_items";
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			error_message varchar(500) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY run_status (run_id, status),
			KEY post_id (post_id)
		) {$charset_collate};" );
	}

	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navne_bulk_run_items" );
	}

	public function bulk_insert( int $run_id, array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}
		$table  = $this->table_name();
		$chunks = array_chunk( $post_ids, self::INSERT_CHUNK );
		foreach ( $chunks as $chunk ) {
			$placeholders = [];
			$values       = [];
			foreach ( $chunk as $post_id ) {
				$placeholders[] = "(%d, %d, 'queued')";
				$values[]       = $run_id;
				$values[]       = (int) $post_id;
			}
			$sql = "INSERT INTO {$table} (run_id, post_id, status) VALUES " . implode( ", ", $placeholders );
			$this->db->query( $this->db->prepare( $sql, ...$values ) );
		}
	}

	public function claim_queued( int $run_id, int $limit ): array {
		$table = $this->table_name();

		// Capture MySQL's clock so the SELECT below can filter to rows this
		// invocation just claimed. Using the DB clock avoids PHP/MySQL clock
		// skew. Rows left in 'dispatching' by a prior crashed wave will have
		// an earlier updated_at and be excluded.
		$tick_start = (int) $this->db->get_var( "SELECT UNIX_TIMESTAMP()" );

		// Atomic claim: UPDATE ... WHERE id IN (SELECT ... LIMIT). The nested
		// SELECT wraps the inner LIMIT in a subquery alias to avoid MySQL's
		// "can't SELECT and UPDATE the same table in one statement" error.
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$table}
				 SET status = 'dispatching', updated_at = NOW()
				 WHERE id IN (
					SELECT id FROM (
						SELECT id FROM {$table}
						WHERE run_id = %d AND status = 'queued'
						ORDER BY created_at ASC, id ASC
						LIMIT %d
					) AS claim
				 )",
				$run_id,
				$limit
			)
		);

		$post_ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT post_id FROM {$table}
				 WHERE run_id = %d
				   AND status = 'dispatching'
				   AND UNIX_TIMESTAMP(updated_at) >= %d
				 ORDER BY id ASC",
				$run_id,
				$tick_start
			)
		);

		return array_map( "intval", $post_ids ?: [] );
	}

	public function update_status( int $run_id, int $post_id, string $status, ?string $error = null ): void {
		$data = [ "status" => $status ];
		if ( $error !== null ) {
			$data["error_message"] = $error;
		}
		$this->db->update(
			$this->table_name(),
			$data,
			[ "run_id" => $run_id, "post_id" => $post_id ]
		);
	}

	public function count_terminal_for_run( int $run_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT status, COUNT(*) AS c FROM {$this->table_name()} WHERE run_id = %d GROUP BY status",
				$run_id
			),
			ARRAY_A
		);
		$processed = 0;
		$failed    = 0;
		foreach ( $rows ?: [] as $row ) {
			$c = (int) $row["c"];
			if ( in_array( $row["status"], [ "complete", "failed", "skipped" ], true ) ) {
				$processed += $c;
			}
			if ( $row["status"] === "failed" ) {
				$failed += $c;
			}
		}
		return [ "processed" => $processed, "failed" => $failed ];
	}

	public function failed_post_ids_for_run( int $run_id ): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT post_id FROM {$this->table_name()} WHERE run_id = %d AND status = 'failed' ORDER BY id ASC",
				$run_id
			)
		);
		return array_map( "intval", $rows ?: [] );
	}

	public function find_failed_for_run( int $run_id, int $limit ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT post_id, error_message FROM {$this->table_name()}
				 WHERE run_id = %d AND status = 'failed'
				 ORDER BY id ASC LIMIT %d",
				$run_id,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: [];
	}
}
