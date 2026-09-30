<?php
/**
 * Tools → Link Ahead: which placeholders exist, and where do they point?
 *
 * Read-only. Lists every placeholder with its source post and the current
 * state of the target, so an editor sees before publication day which links
 * are still waiting and which point to a deleted or trashed post.
 *
 * @package LinkAhead
 */

defined( 'ABSPATH' ) || exit;

/** Maximum number of posts the report scans. */
const LINKAHEAD_REPORT_LIMIT = 500;

/**
 * Registers the report page.
 */
function linkahead_admin_menu() {
	add_management_page(
		__( 'Link Ahead', 'link-ahead' ),
		__( 'Link Ahead', 'link-ahead' ),
		'edit_others_posts',
		'link-ahead',
		'linkahead_render_report'
	);
}
add_action( 'admin_menu', 'linkahead_admin_menu' );

/**
 * Collects all placeholders.
 *
 * @return array[] Rows with source, text and target.
 */
function linkahead_collect() {
	$ids   = linkahead_search_content( 'data-link-ahead=', LINKAHEAD_REPORT_LIMIT );
	$posts = array_filter( array_map( 'get_post', $ids ) );

	$rows = array();
	foreach ( $posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		if ( ! preg_match_all( '~<a\b[^>]*\bdata-link-ahead="(\d+)"[^>]*>(.*?)</a>~is', $post->post_content, $found, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $found as $match ) {
			$rows[] = array(
				'source' => (int) $post->ID,
				'text'   => wp_strip_all_tags( $match[2] ),
				'target' => (int) $match[1],
			);
		}
	}
	return array( $rows, count( $ids ) >= LINKAHEAD_REPORT_LIMIT );
}

/**
 * State of a target for the report.
 *
 * @param int $target_id Target post ID.
 * @return array{label: string}
 */
function linkahead_target_state( $target_id ) {
	$post = get_post( $target_id );
	if ( ! $post ) {
		return array( 'label' => __( 'Missing – stays text', 'link-ahead' ) );
	}
	switch ( $post->post_status ) {
		case 'publish':
			return array( 'label' => __( 'Published – linked', 'link-ahead' ) );
		case 'future':
			return array(
				/* translators: %s: publication date. */
				'label'    => sprintf( __( 'Scheduled for %s', 'link-ahead' ), get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ),
			);
		case 'trash':
			return array( 'label' => __( 'In trash – stays text', 'link-ahead' ) );
		case 'draft':
		case 'pending':
			return array( 'label' => __( 'Not scheduled yet', 'link-ahead' ) );
		default:
			/* translators: %s: post status. */
			return array( 'label' => sprintf( __( 'Status: %s', 'link-ahead' ), $post->post_status ) );
	}
}

/**
 * Renders the report page.
 */
function linkahead_render_report() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	list( $rows, $truncated ) = linkahead_collect();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Placeholder links to posts that are not published yet. They become links on their own once the target is published.', 'link-ahead' ); ?></p>
		<?php if ( $truncated ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %d: number of posts scanned. */
				echo esc_html( sprintf( __( 'Only the %d most recent posts with placeholders were scanned.', 'link-ahead' ), LINKAHEAD_REPORT_LIMIT ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! $rows ) : ?>
			<p><em><?php esc_html_e( 'No placeholders found.', 'link-ahead' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'In post', 'link-ahead' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Link text', 'link-ahead' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Target', 'link-ahead' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'link-ahead' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $state = linkahead_target_state( $row['target'] ); ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['source'] ) ); ?>"><?php echo esc_html( get_the_title( $row['source'] ) ); ?></a></td>
						<td><?php echo esc_html( $row['text'] ); ?></td>
						<td>
							<?php if ( get_post( $row['target'] ) && current_user_can( 'edit_post', $row['target'] ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $row['target'] ) ); ?>"><?php echo esc_html( get_the_title( $row['target'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '#' . $row['target'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $state['label'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
