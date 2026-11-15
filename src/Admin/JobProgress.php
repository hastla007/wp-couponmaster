<?php
namespace VCAP\Admin;

use VCAP\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Progress of a background run (code generation, import) on its admin screen (B4).
 */
final class JobProgress {
	/** Seconds a screen view works on a running job itself (sites whose WP-Cron does not run). */
	public const INLINE_SECONDS = 5;

	/**
	 * The user's job of a kind: advanced a little when still running; a finished job is handed back once and removed.
	 *
	 * @return array{0:?array, 1:bool} job (or null), whether it is still running
	 */
	public static function load( string $kind ): array {
		$id  = Jobs::id( $kind, get_current_user_id() );
		$job = Jobs::get( $id );
		if ( ! $job ) {
			return array( null, false );
		}
		if ( Jobs::running( $job ) ) {
			Jobs::advance( $id, (float) self::INLINE_SECONDS );
			$job = Jobs::get( $id );
			if ( Jobs::running( $job ) ) {
				return array( $job, true );
			}
		}
		Jobs::finish( $id ); // Shown once, like the report of a run done right away.
		return array( $job, false );
	}

	/** Progress notice that reloads the screen until the job is through. */
	public static function render( array $job, string $label ): void {
		?>
		<div class="notice notice-info vcap-job-progress">
			<p><strong><?php echo esc_html( $label ); ?></strong></p>
			<p>
				<progress max="100" value="<?php echo esc_attr( (string) Jobs::percent( $job ) ); ?>"></progress>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: done, 2: total, 3: percent */
						__( '%1$d of %2$d (%3$d %%) – this runs in the background; you may leave this page.', 'voucher-captain' ),
						(int) $job['done'],
						(int) $job['total'],
						Jobs::percent( $job )
					)
				);
				?>
			</p>
		</div>
		<?php
		// Reloads until the job is through (AP-W1-3: attached to the admin script, no inline <script>).
		wp_add_inline_script( 'vcap-admin', 'setTimeout( function () { window.location.reload(); }, 3000 );' );
	}
}
