<?php
/**
 * One-time review notice on the site settings screen.
 *
 * @package AuthorChat
 */

defined( 'ABSPATH' ) || exit;

/** Track review eligibility for the current administrator and site. */
final class ACPPAuthorChat_Review_Prompt {

	/** Number of settings visits required for the notice. */
	private const MIN_VISITS = 3;

	/**
	 * Record an eligible visit and claim the notice before rendering it.
	 *
	 * @return bool Whether this request is the first eligible display.
	 */
	private static function record_visit() {
		global $wpdb;
		$key = 'acppauthorchat_review_prompt_' . get_current_user_id();
		$now = time();

		for ( $attempt = 0; $attempt < self::MIN_VISITS; ++$attempt ) {
			// Read the current database value so a concurrent update is visible on retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$state = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
			if ( $wpdb->last_error ) {
				return false;
			}
			if ( null === $state ) {
				// The options table's unique key lets only one first visit set the timestamp.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$saved = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $key, $now . ':1', 'no' ) );
				if ( 0 === $saved ) {
					continue;
				}
				return false;
			}
			if ( ! preg_match( '/^([1-9][0-9]*):([1-3])$/D', $state, $parts ) ) {
				return false;
			}

			$visits = min( self::MIN_VISITS, (int) $parts[2] + 1 );
			$show   = self::MIN_VISITS === $visits && $now - (int) $parts[1] >= 7 * DAY_IN_SECONDS;
			$next   = $show ? 'shown' : $parts[1] . ':' . $visits;
			if ( $state === $next ) {
				return false;
			}

			// Compare and swap the previous value. Only the winning request can show the notice.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$saved = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $next, $key, $state ) );
			if ( 0 !== $saved ) {
				return 1 === $saved && $show;
			}
		}
		return false;
	}

	/**
	 * Tie the dismissal nonce to the current site.
	 *
	 * @return string
	 */
	private static function nonce_action() {
		return 'acppauthorchat_dismiss_review_' . get_current_blog_id();
	}

	/** Render the notice in the settings page after a qualifying GET. */
	public static function render() {
		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( 'GET' !== $method || ! is_blog_admin() || ! current_user_can( 'manage_options' ) || ! self::record_visit() ) {
			return;
		}
		?>
		<section class="notice notice-info acppauthorchat-review" aria-labelledby="acppauthorchat-review-heading">
			<h2 id="acppauthorchat-review-heading"><?php esc_html_e( 'Have you tried Author Chat?', 'author-chat' ); ?></h2>
			<p><?php esc_html_e( "We'd love to hear about your experience. Your feedback helps us improve the plugin and supports its continued development.", 'author-chat' ); ?></p>
			<form class="acppauthorchat-review-actions" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=acppauthorchat-settings' ) ); ?>">
				<?php wp_nonce_field( self::nonce_action(), 'acppauthorchat_dismiss_review', false ); ?>
				<a class="button" href="https://wordpress.org/support/plugin/author-chat/reviews/#new-post" rel="noreferrer"><?php esc_html_e( 'Leave a review', 'author-chat' ); ?></a>
				<button class="button-link" type="submit"><?php esc_html_e( "Don't show again", 'author-chat' ); ?></button>
				<button class="notice-dismiss" type="submit"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss review request', 'author-chat' ); ?></span></button>
			</form>
		</section>
		<?php
	}

	/** Return to the settings page after the dismiss button submits its form. */
	public static function dismiss() {
		if ( ! is_blog_admin() || ! isset( $_POST['acppauthorchat_dismiss_review'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'author-chat' ), '', array( 'response' => 403 ) );
		}
		$nonce = is_string( $_POST['acppauthorchat_dismiss_review'] ) ? sanitize_text_field( wp_unslash( $_POST['acppauthorchat_dismiss_review'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action() ) ) {
			wp_nonce_ays( self::nonce_action() );
		}

		// The display is already persisted. Either dismissal button simply returns to settings.
		wp_safe_redirect( admin_url( 'admin.php?page=acppauthorchat-settings' ), 303 );
		exit;
	}
}
