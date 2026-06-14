<?php
/**
 * Plugin Name: Amelia External Events
 * Plugin URI: https://github.com/LamcatUK/lc-amelia-external-events
 * Description: Lets Amelia events tagged "EXTERNAL" link out to a third-party website instead of opening the Amelia booking flow. The booking button is relabelled and price/capacity/spots are hidden on the public calendar and event list.
 * Version: 1.0.0
 * Author: Lamcat - DS
 * License: GPL v2 or later
 * Text Domain: lc-amelia-external-events
 *
 * @package LcAmeliaExternalEvents
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCAEE_VERSION', '1.0.0' );
define( 'LCAEE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LCAEE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// The Amelia event tag that flags an event as "external".
if ( ! defined( 'LCAEE_TAG' ) ) {
	define( 'LCAEE_TAG', 'EXTERNAL' );
}

/**
 * Build the wp_options key that stores the external URL for an event.
 *
 * @param int $event_id Amelia event ID.
 * @return string
 */
function lcaee_url_option_key( $event_id ) {
	return 'lcaee_event_' . (int) $event_id . '_url';
}

/**
 * Get all Amelia events that carry the EXTERNAL tag (regardless of whether a URL is set).
 *
 * Used to populate the admin management screen.
 *
 * @return array Array of row objects: id, name, status, start_date.
 */
function lcaee_get_tagged_events() {
	global $wpdb;

	$events_table  = $wpdb->prefix . 'amelia_events';
	$tags_table    = $wpdb->prefix . 'amelia_events_tags';
	$periods_table = $wpdb->prefix . 'amelia_events_periods';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be passed as placeholders.
	$sql = $wpdb->prepare(
		"SELECT e.id, e.name, e.status, MIN( p.periodStart ) AS start_date
		 FROM {$events_table} e
		 INNER JOIN {$tags_table} t ON t.eventId = e.id AND t.name = %s
		 LEFT JOIN {$periods_table} p ON p.eventId = e.id
		 GROUP BY e.id, e.name, e.status
		 ORDER BY ( MIN( p.periodStart ) IS NULL ), MIN( p.periodStart )",
		LCAEE_TAG
	);

	$results = $wpdb->get_results( $sql );
	// phpcs:enable

	return is_array( $results ) ? $results : array();
}

/**
 * Get the "active" external events: EXTERNAL-tagged AND with a non-empty URL set.
 *
 * Used to build the front-end data blob.
 *
 * @return array List of [ 'id' => int, 'name' => string, 'url' => string ].
 */
function lcaee_get_external_events() {
	$out = array();

	foreach ( lcaee_get_tagged_events() as $event ) {
		$url = get_option( lcaee_url_option_key( $event->id ), '' );

		if ( ! empty( $url ) ) {
			$out[] = array(
				'id'   => (int) $event->id,
				'name' => $event->name,
				'url'  => $url,
			);
		}
	}

	return $out;
}

/**
 * Inject external markers into the Amelia /events API payload.
 *
 * Belt-and-suspenders: the public widget is a Vue SPA, so the primary behaviour is
 * driven by the front-end script. This also exposes the data on each event object so
 * a future/fallback integration can match by ID rather than by name.
 *
 * @param array $events Array of event arrays.
 * @return array
 */
function lcaee_filter_events( $events ) {
	if ( ! is_array( $events ) ) {
		return $events;
	}

	foreach ( $events as &$event ) {
		if ( empty( $event['tags'] ) || ! is_array( $event['tags'] ) ) {
			continue;
		}

		$is_external = false;

		foreach ( $event['tags'] as $tag ) {
			if ( isset( $tag['name'] ) && 0 === strcasecmp( $tag['name'], LCAEE_TAG ) ) {
				$is_external = true;
				break;
			}
		}

		if ( ! $is_external ) {
			continue;
		}

		$url = isset( $event['id'] ) ? get_option( lcaee_url_option_key( $event['id'] ), '' ) : '';

		$event['lcaeeExternal'] = true;
		$event['lcaeeUrl']      = ! empty( $url ) ? $url : null;
	}

	unset( $event );

	return $events;
}
add_filter( 'amelia_get_events_filter', 'lcaee_filter_events', 10, 1 );

/**
 * Main bootstrap.
 */
class LCAEE_Plugin {

	/**
	 * Hook up initialisation.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Load admin vs front-end pieces.
	 */
	public function init() {
		if ( is_admin() ) {
			new LCAEE_Admin();
		} else {
			new LCAEE_Frontend();
		}
	}
}

/**
 * Admin: management screen + AJAX persistence of URLs.
 */
class LCAEE_Admin {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'wp_ajax_lcaee_save_url', array( $this, 'ajax_save_url' ) );
		add_action( 'wp_ajax_lcaee_delete_url', array( $this, 'ajax_delete_url' ) );
	}

	/**
	 * Add the "External Events" page under the Amelia menu (with a sensible fallback).
	 */
	public function add_admin_menu() {
		global $admin_page_hooks;

		$possible_parents = array(
			'wpamelia-dashboard',
			'amelia',
			'wpamelia-events',
			'ameliabooking',
			'amelia-dashboard',
		);

		$parent_slug = null;

		if ( is_array( $admin_page_hooks ) ) {
			foreach ( $possible_parents as $slug ) {
				if ( isset( $admin_page_hooks[ $slug ] ) ) {
					$parent_slug = $slug;
					break;
				}
			}

			if ( ! $parent_slug ) {
				foreach ( $admin_page_hooks as $slug => $hook ) {
					if ( false !== strpos( (string) $slug, 'amelia' ) ) {
						$parent_slug = $slug;
						break;
					}
				}
			}
		}

		if ( $parent_slug ) {
			add_submenu_page(
				$parent_slug,
				__( 'External Events', 'lc-amelia-external-events' ),
				__( 'External Events', 'lc-amelia-external-events' ),
				'manage_options',
				'lcaee-external-events',
				array( $this, 'render_admin_page' )
			);
		} else {
			add_menu_page(
				__( 'External Events', 'lc-amelia-external-events' ),
				__( 'External Events', 'lc-amelia-external-events' ),
				'manage_options',
				'lcaee-external-events',
				array( $this, 'render_admin_page' ),
				'dashicons-external',
				30
			);
		}
	}

	/**
	 * Enqueue the small admin script on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( false === strpos( $hook, 'lcaee-external-events' ) ) {
			return;
		}

		wp_enqueue_script(
			'lcaee-admin',
			LCAEE_PLUGIN_URL . 'assets/js/lcaee-admin.js',
			array( 'jquery' ),
			LCAEE_VERSION,
			true
		);

		wp_localize_script(
			'lcaee-admin',
			'lcaeeAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lcaee_admin_nonce' ),
				'strings' => array(
					'saving'        => __( 'Saving…', 'lc-amelia-external-events' ),
					'saved'         => __( 'Saved.', 'lc-amelia-external-events' ),
					'removed'       => __( 'Link removed.', 'lc-amelia-external-events' ),
					'error'         => __( 'An error occurred. Please try again.', 'lc-amelia-external-events' ),
					'confirmDelete' => __( 'Remove the external link for this event?', 'lc-amelia-external-events' ),
					'invalidUrl'    => __( 'Please enter a valid URL including https://', 'lc-amelia-external-events' ),
				),
			)
		);
	}

	/**
	 * Render the management table.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$events     = lcaee_get_tagged_events();
		$date_format = get_option( 'date_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Amelia External Events', 'lc-amelia-external-events' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: the tag name, e.g. EXTERNAL */
					esc_html__( 'Tag an Amelia event with %s to make it appear here, then set the website link it should open. On the public calendar and event list the booking button is relabelled "Find out more", opens the link in a new tab, and the price/capacity/spots are hidden.', 'lc-amelia-external-events' ),
					'<code>' . esc_html( LCAEE_TAG ) . '</code>'
				);
				?>
			</p>

			<?php if ( empty( $events ) ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %s: the tag name, e.g. EXTERNAL */
							esc_html__( 'No events are tagged %s yet. Add the tag to an event in Amelia, then refresh this page.', 'lc-amelia-external-events' ),
							'<code>' . esc_html( LCAEE_TAG ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;margin-top:15px;">
					<thead>
						<tr>
							<th style="width:35%;"><?php esc_html_e( 'Event Name & Date', 'lc-amelia-external-events' ); ?></th>
							<th><?php esc_html_e( 'External Link (URL)', 'lc-amelia-external-events' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Actions', 'lc-amelia-external-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $events as $event ) :
							$url  = get_option( lcaee_url_option_key( $event->id ), '' );
							$date = $event->start_date
								? date_i18n( $date_format, strtotime( $event->start_date ) )
								: __( 'No date set', 'lc-amelia-external-events' );
							?>
							<tr data-event-id="<?php echo esc_attr( $event->id ); ?>">
								<td>
									<strong><?php echo esc_html( $event->name ); ?></strong><br>
									<small><?php echo esc_html( $date ); ?></small>
								</td>
								<td>
									<input
										type="url"
										class="lcaee-url-input regular-text"
										style="width:100%;"
										value="<?php echo esc_attr( $url ); ?>"
										placeholder="https://example.com/event"
									/>
								</td>
								<td>
									<button type="button" class="button button-primary lcaee-save"><?php esc_html_e( 'Save', 'lc-amelia-external-events' ); ?></button>
									<button type="button" class="button lcaee-remove" <?php echo empty( $url ) ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'lc-amelia-external-events' ); ?></button>
									<span class="lcaee-status" style="margin-left:8px;color:#666;"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: save (or clear) the URL for an event.
	 */
	public function ajax_save_url() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lcaee_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'lc-amelia-external-events' ) ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
		$url      = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';

		if ( ! $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'lc-amelia-external-events' ) ) );
		}

		if ( empty( $url ) ) {
			delete_option( lcaee_url_option_key( $event_id ) );
			wp_send_json_success(
				array(
					'removed' => true,
					'message' => __( 'Link removed.', 'lc-amelia-external-events' ),
				)
			);
		}

		update_option( lcaee_url_option_key( $event_id ), $url );

		wp_send_json_success(
			array(
				'removed' => false,
				'url'     => $url,
				'message' => __( 'Saved.', 'lc-amelia-external-events' ),
			)
		);
	}

	/**
	 * AJAX: delete the URL for an event.
	 */
	public function ajax_delete_url() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lcaee_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'lc-amelia-external-events' ) ) );
		}

		$event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;

		if ( ! $event_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid event.', 'lc-amelia-external-events' ) ) );
		}

		delete_option( lcaee_url_option_key( $event_id ) );

		wp_send_json_success( array( 'message' => __( 'Link removed.', 'lc-amelia-external-events' ) ) );
	}
}

/**
 * Front-end: enqueue the decorate/intercept script with the external events data.
 */
class LCAEE_Frontend {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets only when there is at least one active external event.
	 */
	public function enqueue() {
		/**
		 * Allow sites to short-circuit asset loading (e.g. restrict to certain pages).
		 *
		 * @param bool $should_load Default true on the front-end.
		 */
		if ( ! apply_filters( 'lcaee_should_load_assets', true ) ) {
			return;
		}

		$external = lcaee_get_external_events();

		if ( empty( $external ) ) {
			return;
		}

		wp_enqueue_style(
			'lcaee-frontend',
			LCAEE_PLUGIN_URL . 'assets/css/lcaee-frontend.css',
			array(),
			LCAEE_VERSION
		);

		wp_enqueue_script(
			'lcaee-frontend',
			LCAEE_PLUGIN_URL . 'assets/js/lcaee-frontend.js',
			array(),
			LCAEE_VERSION,
			true
		);

		wp_localize_script(
			'lcaee-frontend',
			'lcaeeData',
			array(
				'events' => $external,
				'label'  => __( 'Find out more', 'lc-amelia-external-events' ),
			)
		);
	}
}

new LCAEE_Plugin();
