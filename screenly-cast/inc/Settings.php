<?php
/**
 * The plugin's settings screen.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

namespace ScreenlyCast;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the one setting the plugin has: the brand logo.
 *
 * The plugin previously carried three overlapping settings implementations, none
 * of which worked. One registered options nothing read; another was a procedural
 * file the class autoloader never loaded; and its form called
 * settings_fields( 'screenly-cast-settings' ) against a group registered as
 * 'screenly_cast_settings', so saving could not succeed. The logo option it wrote
 * was also spelled differently from the one the template read.
 *
 * Hence the constants below: the group, the page and the option names each have
 * exactly one spelling.
 */
final class Settings {

	public const PAGE_SLUG          = 'screenly-cast';
	public const OPTION_GROUP       = 'screenly_cast';
	public const SECTION_ID         = 'screenly_cast_branding';
	public const BEHAVIOUR_SECTION  = 'screenly_cast_behaviour';
	public const LOGO_ID_OPTION     = 'screenly_cast_logo_id';
	public const LOGO_URL_OPTION    = 'screenly_cast_logo_url';
	public const AUTO_DETECT_OPTION = 'screenly_cast_auto_detect';

	/**
	 * Attach the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the setting, its section and its field.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::LOGO_ID_OPTION,
			array(
				'type'              => 'integer',
				'description'       => __( 'Attachment ID of the logo shown on signage renders.', 'screenly-cast' ),
				'sanitize_callback' => array( $this, 'sanitize_attachment_id' ),
				'default'           => 0,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::AUTO_DETECT_OPTION,
			array(
				'type'              => 'boolean',
				'description'       => __( 'Send recognised signage players to the signage view automatically.', 'screenly-cast' ),
				'sanitize_callback' => static fn( mixed $value ): bool => (bool) $value,
				'default'           => true,
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			self::BEHAVIOUR_SECTION,
			__( 'Signage players', 'screenly-cast' ),
			array( $this, 'render_behaviour_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::AUTO_DETECT_OPTION,
			__( 'Detect players', 'screenly-cast' ),
			array( $this, 'render_auto_detect_field' ),
			self::PAGE_SLUG,
			self::BEHAVIOUR_SECTION,
			array( 'label_for' => self::AUTO_DETECT_OPTION )
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Branding', 'screenly-cast' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::LOGO_ID_OPTION,
			__( 'Logo', 'screenly-cast' ),
			array( $this, 'render_logo_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::LOGO_ID_OPTION )
		);
	}

	/**
	 * Add the settings page under Settings.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Screenly Cast Settings', 'screenly-cast' ),
			__( 'Screenly Cast', 'screenly-cast' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the media picker on our settings page only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		/*
		 * Depends on media-editor, which is what provides wp.media — not on jquery,
		 * which this script does not use. Declaring jquery loaded it on this screen
		 * for nothing, and would have made the plugin look affected by WordPress
		 * 7.1's jQuery UI upgrade when it is not.
		 */
		wp_enqueue_script(
			'screenly-cast-admin',
			SRLY_PLUGIN_URL . 'assets/js/admin.js',
			array( 'media-editor' ),
			SRLY_VERSION,
			true
		);

		wp_localize_script(
			'screenly-cast-admin',
			'screenlyCastAdmin',
			array(
				'frameTitle'  => __( 'Choose a logo', 'screenly-cast' ),
				'frameButton' => __( 'Use this logo', 'screenly-cast' ),
			)
		);
	}

	/**
	 * Describe the player-detection section.
	 */
	public function render_behaviour_intro(): void {
		echo '<p>' . esc_html__(
			'With this on, you can point a screen at an ordinary page URL and it will show the signage view, with no need to add ?srly yourself. Adding ?srly still works, and always wins.',
			'screenly-cast'
		) . '</p>';
	}

	/**
	 * Render the player-detection checkbox.
	 */
	public function render_auto_detect_field(): void {
		?>
		<label for="<?php echo esc_attr( self::AUTO_DETECT_OPTION ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( self::AUTO_DETECT_OPTION ); ?>"
				name="<?php echo esc_attr( self::AUTO_DETECT_OPTION ); ?>"
				value="1"
				<?php checked( self::auto_detect_enabled() ); ?>
			/>
			<?php esc_html_e( 'Send recognised signage players to the signage view', 'screenly-cast' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'Players are recognised from their request and redirected to the same page with ?srly added. The redirect keeps the two versions of a page cached separately, so a visitor is never served a signage render. Signed-in users and search engines are never redirected.',
				'screenly-cast'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Whether recognised signage players should be redirected automatically.
	 *
	 * @return bool
	 */
	public static function auto_detect_enabled(): bool {
		return (bool) get_option( self::AUTO_DETECT_OPTION, true );
	}

	/**
	 * Describe what the section is for.
	 */
	public function render_section_intro(): void {
		echo '<p>' . esc_html__(
			'Shown in the corner of every signage render. Leave empty for no logo.',
			'screenly-cast'
		) . '</p>';
	}

	/**
	 * Render the logo picker.
	 */
	public function render_logo_field(): void {
		$logo_id = Options::get_int( self::LOGO_ID_OPTION );
		$preview = $logo_id > 0 && wp_attachment_is_image( $logo_id )
			? wp_get_attachment_image( $logo_id, 'medium', false, array( 'style' => 'max-width:220px;height:auto;' ) )
			: '';

		?>
		<div class="screenly-cast-logo-field">
			<input
				type="hidden"
				id="<?php echo esc_attr( self::LOGO_ID_OPTION ); ?>"
				name="<?php echo esc_attr( self::LOGO_ID_OPTION ); ?>"
				value="<?php echo esc_attr( (string) $logo_id ); ?>"
			/>
			<p class="screenly-cast-logo-field__preview">
				<?php
				// Output of wp_get_attachment_image(), already escaped by core.
				echo wp_kses_post( $preview );
				?>
			</p>
			<button type="button" class="button screenly-cast-logo-field__choose">
				<?php esc_html_e( 'Choose logo', 'screenly-cast' ); ?>
			</button>
			<button
				type="button"
				class="button-link screenly-cast-logo-field__remove"
				<?php echo 0 === $logo_id ? 'hidden' : ''; ?>
			>
				<?php esc_html_e( 'Remove logo', 'screenly-cast' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * Note the absence of a manual nonce check. The previous implementation
	 * verified a nonce while *rendering* a GET request, which achieved nothing;
	 * options.php and settings_fields() handle the nonce on submission.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Keep only a real image attachment ID.
	 *
	 * @param mixed $value The submitted value.
	 * @return int A valid attachment ID, or 0.
	 */
	public function sanitize_attachment_id( mixed $value ): int {
		// absint() does not accept arrays or objects, and this is user input.
		$id = is_scalar( $value ) ? absint( $value ) : 0;

		if ( 0 === $id || ! wp_attachment_is_image( $id ) ) {
			/*
			 * Clear the legacy URL fallback too.
			 *
			 * Migration writes screenly_cast_logo_url when it cannot match an old
			 * logo URL to anything in the media library, and logo_markup() falls
			 * back to it. That option is not registered and the screen has no
			 * control for it, so on such an install "Remove logo" set the id to 0
			 * and the old logo reappeared on the very next render, with no way to
			 * get rid of it from the admin at all.
			 *
			 * Done here rather than on update_option_* because that hook only fires
			 * when the value actually changes, and the id is already 0 in precisely
			 * the state this needs to fix.
			 */
			delete_option( self::LOGO_URL_OPTION );

			return 0;
		}

		return $id;
	}

	/**
	 * The configured logo markup.
	 *
	 * Prefers the attachment ID, which gives a srcset. The URL option is only a
	 * carry-over for legacy installs whose stored logo URL could not be matched
	 * to a media library item during migration.
	 *
	 * @return string Logo markup, or an empty string when none is configured.
	 */
	public static function logo_markup(): string {
		$logo_id = Options::get_int( self::LOGO_ID_OPTION );

		if ( $logo_id > 0 && wp_attachment_is_image( $logo_id ) ) {
			return wp_get_attachment_image(
				$logo_id,
				'medium',
				false,
				array(
					'class' => 'srly-logo__image',
					'alt'   => '',
				)
			);
		}

		$logo_url = Options::get_string( self::LOGO_URL_OPTION );

		if ( '' !== $logo_url ) {
			return sprintf(
				'<img class="srly-logo__image" src="%s" alt="" />',
				esc_url( $logo_url )
			);
		}

		return '';
	}

	/**
	 * Every option the plugin owns, for migration and uninstall to agree on.
	 *
	 * @return string[] Option names.
	 */
	public static function option_names(): array {
		return array(
			self::LOGO_ID_OPTION,
			self::LOGO_URL_OPTION,
			self::AUTO_DETECT_OPTION,
			Migration::VERSION_OPTION,
		);
	}
}
