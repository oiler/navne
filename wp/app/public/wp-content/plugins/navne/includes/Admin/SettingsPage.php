<?php
// includes/Admin/SettingsPage.php
namespace Navne\Admin;

class SettingsPage {
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_navne_save_settings', [ self::class, 'handle_save' ] );
	}

	public static function add_page(): void {
		add_options_page(
			'Navne Settings',
			'Navne',
			'manage_options',
			'navne-settings',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'no_post_types' === sanitize_key( $_GET['error'] ?? '' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>At least one post type must be selected.</p></div>';
		}

		$provider      = (string) get_option( 'navne_provider', 'anthropic' );
		$api_key_set   = '' !== (string) get_option( 'navne_anthropic_api_key', '' );
		$model         = (string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' );
		$mode          = (string) get_option( 'navne_operating_mode', 'suggest' );
		$saved_types   = (array) get_option( 'navne_post_types', [ 'post' ] );
		$all_types     = get_post_types( [ 'public' => true ], 'objects' );
		?>
		<div class="wrap">
			<h1>Navne Settings</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'navne_settings' ); ?>
				<input type="hidden" name="action" value="navne_save_settings" />

				<h2>LLM Provider</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="navne_provider">Provider</label></th>
						<td>
							<select name="navne_provider" id="navne_provider">
								<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="navne_anthropic_api_key">API Key</label></th>
						<td>
							<input type="password" name="navne_anthropic_api_key" id="navne_anthropic_api_key"
								class="regular-text" value="" autocomplete="off" />
							<p class="description">
								<?php echo $api_key_set ? 'Key is configured.' : 'No key set.'; ?>
								Leave blank to keep the existing key.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="navne_anthropic_model">Model</label></th>
						<td>
							<input type="text" name="navne_anthropic_model" id="navne_anthropic_model"
								class="regular-text" value="<?php echo esc_attr( $model ); ?>" />
						</td>
					</tr>
				</table>

				<h2>Operating Mode</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Mode</th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px;color:#999;">
									<input type="radio" name="navne_operating_mode" value="safe" disabled />
									Safe <span style="font-style:italic">(coming soon)</span>
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="navne_operating_mode" value="suggest"
										<?php checked( $mode, 'suggest' ); ?> />
									Suggest — editor reviews all suggestions before anything is linked
								</label>
								<label style="display:block;color:#999;">
									<input type="radio" name="navne_operating_mode" value="yolo" disabled />
									YOLO <span style="font-style:italic">(coming soon)</span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2>Post Types</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Process entities for</th>
						<td>
							<fieldset>
								<?php foreach ( $all_types as $type ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="navne_post_types[]"
											value="<?php echo esc_attr( $type->name ); ?>"
											<?php checked( in_array( $type->name, $saved_types, true ) ); ?> />
										<?php echo esc_html( $type->labels->singular_name ); ?>
										<span style="color:#999">(<?php echo esc_html( $type->name ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'navne' ) );
		}
		check_admin_referer( 'navne_settings' );

		// Provider + model.
		$provider = sanitize_key( $_POST['navne_provider'] ?? 'anthropic' );
		update_option( 'navne_provider', $provider );

		$model = sanitize_text_field( $_POST['navne_anthropic_model'] ?? '' );
		update_option( 'navne_anthropic_model', $model );

		// API key — only update if non-empty.
		$api_key = sanitize_text_field( $_POST['navne_anthropic_api_key'] ?? '' );
		if ( '' !== $api_key ) {
			update_option( 'navne_anthropic_api_key', $api_key );
		}

		// Only 'suggest' is active — safe and yolo are coming soon. Enforce server-side.
		update_option( 'navne_operating_mode', 'suggest' );

		// Post types — validate against registered public post types (allowlist approach).
		$all_registered = array_keys( get_post_types( [ 'public' => true ] ) );
		$raw_types      = isset( $_POST['navne_post_types'] ) ? (array) $_POST['navne_post_types'] : [];
		$clean_types    = array_values( array_intersect( array_map( 'sanitize_key', $raw_types ), $all_registered ) );
		if ( empty( $clean_types ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&error=no_post_types' ) );
			exit;
		}
		update_option( 'navne_post_types', $clean_types );

		wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&updated=1' ) );
		exit;
	}
}
