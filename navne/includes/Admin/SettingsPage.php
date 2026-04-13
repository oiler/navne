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

		$provider             = (string) get_option( 'navne_provider', 'anthropic' );
		$api_key_via_constant = defined( 'NAVNE_ANTHROPIC_API_KEY' );
		$api_key_set          = $api_key_via_constant || '' !== (string) get_option( 'navne_anthropic_api_key', '' );
		$model            = (string) get_option( 'navne_anthropic_model', 'claude-sonnet-4-6' );
		$mode             = (string) get_option( 'navne_operating_mode', 'suggest' );
		$saved_types      = (array) get_option( 'navne_post_types', [ 'post' ] );
		$all_types        = get_post_types( [ 'public' => true ], 'objects' );
		$definitions_raw  = (string) get_option( 'navne_org_definitions', '' );
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
							<?php if ( $api_key_via_constant ) : ?>
								<p class="description">Key is configured via <code>NAVNE_ANTHROPIC_API_KEY</code> in wp-config.php.</p>
							<?php else : ?>
								<input type="password" name="navne_anthropic_api_key" id="navne_anthropic_api_key"
									class="regular-text" value="" autocomplete="off" />
								<p class="description">
									<?php echo $api_key_set ? 'Key is configured.' : 'No key set.'; ?>
									Leave blank to keep the existing key.
								</p>
								<p class="description">For better security, define <code>NAVNE_ANTHROPIC_API_KEY</code> in wp-config.php instead.</p>
							<?php endif; ?>
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
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="navne_operating_mode" value="safe"
										<?php checked( $mode, 'safe' ); ?> />
									Safe — only approved entities are linked; pipeline runs on demand
								</label>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="navne_operating_mode" value="suggest"
										<?php checked( $mode, 'suggest' ); ?> />
									Suggest — editor reviews all suggestions before anything is linked
								</label>
								<label style="display:block;">
									<input type="radio" name="navne_operating_mode" value="yolo"
										<?php checked( $mode, 'yolo' ); ?> />
									YOLO — entities above 75% confidence are linked automatically
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

				<h2>Org Definitions</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="navne_org_definitions">Definition list</label></th>
						<td>
							<p class="description" style="margin-bottom:8px;">One definition per line. Format: <code>Term: Description</code>. Lines starting with <code>#</code> are ignored.</p>
							<textarea name="navne_org_definitions" id="navne_org_definitions"
								class="large-text" rows="12"><?php echo esc_textarea( $definitions_raw ); ?></textarea>
							<p class="description" style="margin-top:6px;">
								Example:<br>
								<code>DOE: The local school district, not the federal Department of Energy</code><br>
								<code>Gov. Smith: Governor Jane Smith, incumbent since 2020</code>
							</p>
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

		// Model — must be alphanumeric + hyphens only (safe for any future Anthropic model slug).
		$model_raw = sanitize_text_field( $_POST['navne_anthropic_model'] ?? '' );
		$model     = ( 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,98}[a-z0-9]$/i', $model_raw ) )
			? $model_raw
			: 'claude-sonnet-4-6';
		update_option( 'navne_anthropic_model', $model );

		// API key — only update if non-empty and not overridden by a constant.
		if ( ! defined( 'NAVNE_ANTHROPIC_API_KEY' ) ) {
			$api_key = sanitize_text_field( $_POST['navne_anthropic_api_key'] ?? '' );
			if ( '' !== $api_key ) {
				update_option( 'navne_anthropic_api_key', $api_key );
			}
		}

		// Operating mode — validate against allowed values.
		$allowed_modes = [ 'safe', 'suggest', 'yolo' ];
		$mode_raw      = sanitize_key( $_POST['navne_operating_mode'] ?? 'suggest' );
		$mode          = in_array( $mode_raw, $allowed_modes, true ) ? $mode_raw : 'suggest';
		update_option( 'navne_operating_mode', $mode );

		// Post types — validate against registered public post types (allowlist approach).
		$all_registered = array_keys( get_post_types( [ 'public' => true ] ) );
		$raw_types      = isset( $_POST['navne_post_types'] ) ? (array) $_POST['navne_post_types'] : [];
		$clean_types    = array_values( array_intersect( array_map( 'sanitize_key', $raw_types ), $all_registered ) );
		if ( empty( $clean_types ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&error=no_post_types' ) );
			exit;
		}
		update_option( 'navne_post_types', $clean_types );

		// Org definitions.
		$definitions = sanitize_textarea_field( wp_unslash( $_POST['navne_org_definitions'] ?? '' ) );
		update_option( 'navne_org_definitions', $definitions );

		wp_safe_redirect( admin_url( 'options-general.php?page=navne-settings&updated=1' ) );
		exit;
	}
}
