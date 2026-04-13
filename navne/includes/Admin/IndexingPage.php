<?php
// includes/Admin/IndexingPage.php
namespace Navne\Admin;

use Navne\BulkIndex\Config;
use Navne\BulkIndex\RunFactory;
use Navne\BulkIndex\RunItemsRepository;
use Navne\BulkIndex\RunsRepository;
use Navne\BulkIndex\ScopeQuery;

class IndexingPage {
	public static function register_hooks(): void {
		add_action( "admin_menu", [ self::class, "add_page" ] );
		add_action( "admin_post_navne_indexing_preview", [ self::class, "handle_preview" ] );
		add_action( "admin_post_navne_indexing_start",   [ self::class, "handle_start" ] );
		add_action( "admin_post_navne_indexing_cancel",  [ self::class, "handle_cancel" ] );
		add_action( "admin_post_navne_indexing_retry",   [ self::class, "handle_retry" ] );
	}

	public static function add_page(): void {
		add_management_page(
			"Navne Indexing",
			"Navne Indexing",
			"manage_options",
			"navne-indexing",
			[ self::class, "render" ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = isset( $_GET["run"] ) ? (int) $_GET["run"] : 0;

		echo '<div class="wrap"><h1>Navne Indexing</h1>';

		if ( $run_id > 0 ) {
			self::render_run_detail( $run_id );
		} else {
			self::render_form();
			self::render_history();
		}

		echo '</div>';
	}

	private static function render_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$preview          = isset( $_GET["preview"] ) ? sanitize_key( $_GET["preview"] ) : "";
		$count            = isset( $_GET["count"] )   ? (int) $_GET["count"] : 0;
		$run_type_prefill = isset( $_GET["rt"] ) ? sanitize_key( $_GET["rt"] ) : "index_new";
		$mode_prefill     = isset( $_GET["md"] ) ? sanitize_key( $_GET["md"] ) : "suggest";
		$date_from_pre    = isset( $_GET["df"] ) ? sanitize_text_field( $_GET["df"] ) : "";
		$date_to_pre      = isset( $_GET["dt"] ) ? sanitize_text_field( $_GET["dt"] ) : "";
		// phpcs:enable

		$action_url = esc_url( admin_url( "admin-post.php" ) );
		?>
		<form method="post" action="<?php echo $action_url; ?>">
			<?php wp_nonce_field( "navne_indexing" ); ?>
			<input type="hidden" name="action" value="navne_indexing_preview" />

			<h2>Run type</h2>
			<fieldset>
				<label><input type="radio" name="run_type" value="index_new" <?php checked( $run_type_prefill, "index_new" ); ?> /> Index new (only posts that have never been processed)</label><br>
				<label><input type="radio" name="run_type" value="reindex_all" <?php checked( $run_type_prefill, "reindex_all" ); ?> /> Re-index all (every matching post, even if already processed)</label>
			</fieldset>

			<h2>Mode for this run</h2>
			<fieldset>
				<label><input type="radio" name="mode" value="safe" <?php checked( $mode_prefill, "safe" ); ?> /> Safe — only entities already on the whitelist get tagged; unmatched are dropped</label><br>
				<label><input type="radio" name="mode" value="suggest" <?php checked( $mode_prefill, "suggest" ); ?> /> Suggest — every detected entity becomes a pending suggestion</label><br>
				<label><input type="radio" name="mode" value="yolo" <?php checked( $mode_prefill, "yolo" ); ?> /> YOLO — entities at ≥ 0.75 confidence are auto-approved and linked</label>
			</fieldset>

			<h2>Date range (optional)</h2>
			<p>
				<label>From <input type="date" name="date_from" value="<?php echo esc_attr( $date_from_pre ); ?>" /></label>
				&nbsp;
				<label>To <input type="date" name="date_to" value="<?php echo esc_attr( $date_to_pre ); ?>" /></label>
			</p>

			<?php submit_button( "Preview", "secondary" ); ?>
		</form>

		<?php if ( $preview === "ok" && $count >= 0 ) : ?>
			<hr>
			<h2>Preview</h2>
			<p><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> posts match your scope.</p>
			<?php if ( $count > 0 ) : ?>
				<?php
				$avg   = Config::avg_cost_per_article();
				$total = $avg * $count;
				?>
				<p>Rough cost: average article ≈ $<?php echo esc_html( number_format( $avg, 3 ) ); ?> to process. <?php echo esc_html( number_format_i18n( $count ) ); ?> posts ≈ $<?php echo esc_html( number_format( $total, 2 ) ); ?>.</p>
				<?php
				$whitelist_warning = "";
				if ( $mode_prefill === "safe" ) {
					$terms = get_terms( [ "taxonomy" => "navne_entity", "hide_empty" => false, "number" => 1 ] );
					if ( ! is_wp_error( $terms ) && empty( $terms ) ) {
						$whitelist_warning = "Your whitelist is empty. A Safe mode run will process posts but create no tags.";
					}
				}
				if ( $whitelist_warning !== "" ) : ?>
					<p class="notice notice-warning" style="padding:8px 12px;"><?php echo esc_html( $whitelist_warning ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo $action_url; ?>">
					<?php wp_nonce_field( "navne_indexing" ); ?>
					<input type="hidden" name="action" value="navne_indexing_start" />
					<input type="hidden" name="run_type"  value="<?php echo esc_attr( $run_type_prefill ); ?>" />
					<input type="hidden" name="mode"      value="<?php echo esc_attr( $mode_prefill ); ?>" />
					<input type="hidden" name="date_from" value="<?php echo esc_attr( $date_from_pre ); ?>" />
					<input type="hidden" name="date_to"   value="<?php echo esc_attr( $date_to_pre ); ?>" />
					<?php submit_button( "Run this indexing job", "primary" ); ?>
				</form>
			<?php endif; ?>
		<?php endif;
	}

	private static function render_history(): void {
		$runs = RunsRepository::instance()->find_recent( Config::history_limit() );
		if ( empty( $runs ) ) {
			return;
		}
		echo "<hr><h2>Recent runs</h2><ul>";
		foreach ( $runs as $row ) {
			$url = esc_url( admin_url( "tools.php?page=navne-indexing&run=" . (int) $row["id"] ) );
			printf(
				'<li>#%d · %s · %s · %s · %d/%d — <a href="%s">details</a></li>',
				(int) $row["id"],
				esc_html( $row["created_at"] ),
				esc_html( $row["run_type"] ),
				esc_html( $row["status"] ),
				(int) $row["processed"],
				(int) $row["total"],
				$url
			);
		}
		echo "</ul>";
	}

	private static function render_run_detail( int $run_id ): void {
		$run = RunsRepository::instance()->find_by_id( $run_id );
		if ( $run === null ) {
			echo '<p>Run not found. <a href="' . esc_url( admin_url( "tools.php?page=navne-indexing" ) ) . '">Back</a></p>';
			return;
		}
		$back = esc_url( admin_url( "tools.php?page=navne-indexing" ) );
		?>
		<p><a href="<?php echo $back; ?>">← Back to form</a></p>
		<h2>Run #<?php echo (int) $run["id"]; ?></h2>
		<p>
			Started: <?php echo esc_html( $run["created_at"] ); ?> ·
			Type: <?php echo esc_html( $run["run_type"] ); ?> ·
			Mode: <?php echo esc_html( $run["mode"] ); ?>
		</p>
		<div id="navne-run-detail"
			 data-run-id="<?php echo (int) $run["id"]; ?>"
			 data-rest-url="<?php echo esc_url( rest_url( "navne/v1/bulk-runs/" . (int) $run["id"] ) ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( "wp_rest" ) ); ?>">
			<p>
				<span class="navne-run-status"><?php echo esc_html( $run["status"] ); ?></span> ·
				<span class="navne-run-counts"><?php echo (int) $run["processed"]; ?> / <?php echo (int) $run["total"]; ?> processed · <?php echo (int) $run["failed"]; ?> failed</span>
			</p>
			<?php if ( in_array( $run["status"], [ "pending", "running" ], true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="navne-run-cancel">
					<?php wp_nonce_field( "navne_indexing_cancel" ); ?>
					<input type="hidden" name="action"  value="navne_indexing_cancel" />
					<input type="hidden" name="run_id"  value="<?php echo (int) $run["id"]; ?>" />
					<?php submit_button( "Cancel this run", "delete" ); ?>
				</form>
			<?php endif; ?>
			<?php if ( $run["status"] === "complete" && (int) $run["failed"] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
					<?php wp_nonce_field( "navne_indexing" ); ?>
					<input type="hidden" name="action"         value="navne_indexing_retry" />
					<input type="hidden" name="parent_run_id"  value="<?php echo (int) $run["id"]; ?>" />
					<input type="hidden" name="mode"           value="<?php echo esc_attr( $run["mode"] ); ?>" />
					<?php submit_button( "Retry failed posts from this run", "secondary" ); ?>
				</form>
			<?php endif; ?>
			<h3>Failed posts</h3>
			<ul class="navne-run-failed"></ul>
		</div>
		<?php
		wp_enqueue_script(
			"navne-indexing",
			NAVNE_PLUGIN_URL . "assets/js/indexing.js",
			[],
			NAVNE_VERSION,
			true
		);
	}

	public static function handle_preview(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$run_type  = sanitize_key( $_POST["run_type"] ?? "" );
		$mode      = sanitize_key( $_POST["mode"] ?? "" );
		$date_from = self::normalize_date_input( $_POST["date_from"] ?? "" );
		$date_to   = self::normalize_date_input( $_POST["date_to"] ?? "" );

		$scope = new ScopeQuery( RunItemsRepository::instance() );
		$ids   = $scope->matching_post_ids( $run_type, $date_from, $date_to, null );

		$redirect = add_query_arg(
			[
				"page"    => "navne-indexing",
				"preview" => "ok",
				"count"   => count( $ids ),
				"rt"      => $run_type,
				"md"      => $mode,
				"df"      => $date_from,
				"dt"      => $date_to,
			],
			admin_url( "tools.php" )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_start(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$factory = new RunFactory(
			RunsRepository::instance(),
			RunItemsRepository::instance(),
			new ScopeQuery( RunItemsRepository::instance() )
		);

		try {
			$run_id = $factory->create( [
				"run_type"      => sanitize_key( $_POST["run_type"] ?? "" ),
				"mode"          => sanitize_key( $_POST["mode"] ?? "" ),
				"date_from"     => self::normalize_date_input( $_POST["date_from"] ?? "" ),
				"date_to"       => self::normalize_date_input( $_POST["date_to"] ?? "" ),
				"parent_run_id" => null,
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&error=invalid" ) );
			exit;
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	public static function handle_cancel(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing_cancel" );

		$run_id = (int) ( $_POST["run_id"] ?? 0 );
		if ( $run_id > 0 ) {
			$repo = RunsRepository::instance();
			$run  = $repo->find_by_id( $run_id );
			if ( $run && in_array( $run["status"], [ "pending", "running" ], true ) ) {
				$repo->update_status( $run_id, "cancelled" );
			}
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	public static function handle_retry(): void {
		if ( ! current_user_can( "manage_options" ) ) {
			wp_die( "Unauthorized" );
		}
		check_admin_referer( "navne_indexing" );

		$parent_run_id = (int) ( $_POST["parent_run_id"] ?? 0 );
		$mode          = sanitize_key( $_POST["mode"] ?? "suggest" );

		$factory = new RunFactory(
			RunsRepository::instance(),
			RunItemsRepository::instance(),
			new ScopeQuery( RunItemsRepository::instance() )
		);

		try {
			$run_id = $factory->create( [
				"run_type"      => "retry_failed",
				"mode"          => $mode,
				"date_from"     => null,
				"date_to"       => null,
				"parent_run_id" => $parent_run_id,
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&error=invalid" ) );
			exit;
		}

		wp_safe_redirect( admin_url( "tools.php?page=navne-indexing&run=" . $run_id ) );
		exit;
	}

	private static function normalize_date_input( $raw ): ?string {
		$raw = is_string( $raw ) ? sanitize_text_field( $raw ) : "";
		if ( $raw === "" ) {
			return null;
		}
		$dt = \DateTime::createFromFormat( "Y-m-d", $raw );
		return ( $dt && $dt->format( "Y-m-d" ) === $raw ) ? $raw : null;
	}
}
