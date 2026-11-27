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
const LINK_AHEAD_REPORT_LIMIT = 500;

/**
 * Registers the report page.
 */
function link_ahead_admin_menu() {
	add_management_page(
		__( 'Link Ahead', 'link-ahead' ),
		__( 'Link Ahead', 'link-ahead' ),
		'edit_others_posts',
		'link-ahead',
		'link_ahead_render_report'
	);
}
add_action( 'admin_menu', 'link_ahead_admin_menu' );

/**
 * Collects all placeholders.
 *
 * @return array[] Rows with source, text and target.
 */
function link_ahead_collect() {
	$anchors    = link_ahead_search_content( 'data-link-ahead=', LINK_AHEAD_REPORT_LIMIT );
	$shortcodes = link_ahead_search_content( '[link_ahead', LINK_AHEAD_REPORT_LIMIT );
	$ids        = array_values( array_unique( array_merge( $anchors, $shortcodes ) ) );
	$posts      = array_filter( array_map( 'get_post', $ids ) );

	$rows = array();
	foreach ( $posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$found = array();
		if ( preg_match_all( '~<a\b[^>]*\bdata-link-ahead="(\d+)"[^>]*>(.*?)</a>~is', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$found[] = array(
					'target' => (int) $match[1],
					'text'   => $match[2],
				);
			}
		}
		foreach ( array_merge( $found, link_ahead_shortcode_placeholders( $post->post_content ) ) as $placeholder ) {
			$rows[] = array(
				'source' => (int) $post->ID,
				'text'   => wp_strip_all_tags( $placeholder['text'] ),
				'target' => (int) $placeholder['target'],
			);
		}
	}
	$truncated = count( $anchors ) >= LINK_AHEAD_REPORT_LIMIT || count( $shortcodes ) >= LINK_AHEAD_REPORT_LIMIT;
	return array( $rows, $truncated );
}

/**
 * State of a target for the report.
 *
 * @param int $target_id Target post ID.
 * @return array{label: string, key: string, group: string, time: int}
 */
function link_ahead_target_state( $target_id ) {
	$post = get_post( $target_id );
	if ( ! $post ) {
		return array(
			'label' => __( 'Missing – stays text', 'link-ahead' ),
			'key'   => 'missing',
			'group' => 'attention',
			'time'  => 0,
		);
	}
	switch ( $post->post_status ) {
		case 'publish':
			return array(
				'label' => __( 'Published – linked', 'link-ahead' ),
				'key'   => 'linked',
				'group' => 'linked',
				'time'  => 0,
			);
		case 'future':
			return array(
				/* translators: %s: publication date. */
				'label' => sprintf( __( 'Scheduled for %s', 'link-ahead' ), get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) ),
				'key'   => 'scheduled',
				'group' => 'waiting',
				'time'  => (int) get_post_time( 'U', true, $post ),
			);
		case 'trash':
			return array(
				'label' => __( 'In trash – stays text', 'link-ahead' ),
				'key'   => 'trash',
				'group' => 'attention',
				'time'  => 0,
			);
		case 'private':
			return array(
				'label' => __( 'Private – stays text', 'link-ahead' ),
				'key'   => 'private',
				'group' => 'attention',
				'time'  => 0,
			);
		case 'draft':
		case 'pending':
			return array(
				'label' => __( 'Not scheduled yet', 'link-ahead' ),
				'key'   => 'unscheduled',
				'group' => 'attention',
				'time'  => 0,
			);
		default:
			return array(
				/* translators: %s: post status. */
				'label' => sprintf( __( 'Status: %s', 'link-ahead' ), $post->post_status ),
				'key'   => 'other',
				'group' => 'attention',
				'time'  => 0,
			);
	}
}

/**
 * What an editor can do about a target in this state, or an empty string.
 *
 * @param string $key State key from link_ahead_target_state().
 * @return string
 */
function link_ahead_state_hint( $key ) {
	switch ( $key ) {
		case 'unscheduled':
			return __( 'Schedule or publish the target; the link then turns on by itself.', 'link-ahead' );
		case 'trash':
			return __( 'Restore the target, or remove the placeholder from the post.', 'link-ahead' );
		case 'missing':
			return __( 'The target was deleted. Remove the placeholder or link another post.', 'link-ahead' );
		case 'private':
			return __( 'Private posts are never linked for visitors.', 'link-ahead' );
		default:
			return '';
	}
}

/**
 * Icon of a state (Dashicons).
 *
 * @param string $key State key.
 * @return string
 */
function link_ahead_state_icon( $key ) {
	$icons = array(
		'linked'      => 'yes-alt',
		'scheduled'   => 'calendar-alt',
		'unscheduled' => 'edit',
		'private'     => 'lock',
		'trash'       => 'trash',
		'missing'     => 'dismiss',
	);
	return isset( $icons[ $key ] ) ? $icons[ $key ] : 'warning';
}

/**
 * Loads the stylesheet of the report page.
 *
 * @param string $hook Current admin screen.
 */
function link_ahead_report_assets( $hook ) {
	if ( 'tools_page_link-ahead' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'link-ahead-report', plugins_url( 'assets/report.css', LINK_AHEAD_FILE ), array( 'dashicons' ), LINK_AHEAD_VERSION );
	wp_enqueue_script( 'link-ahead-report', plugins_url( 'assets/report.js', LINK_AHEAD_FILE ), array(), LINK_AHEAD_VERSION, true );
	wp_localize_script(
		'link-ahead-report',
		'linkAheadReport',
		array(
			'search'  => __( 'Search posts, link texts and targets', 'link-ahead' ),
			/* translators: 1: placeholders shown, 2: all placeholders */
			'shown'   => __( 'Placeholders shown: %1$d of %2$d', 'link-ahead' ),
			/* translators: %s: title of the target post */
			'target'  => __( 'Only placeholders that point to "%s".', 'link-ahead' ),
			'showAll' => __( 'Show all', 'link-ahead' ),
			'none'    => __( 'No placeholder matches.', 'link-ahead' ),
		)
	);
}

/**
 * Ring chart of the groups (inline SVG): linked, waiting, needs attention. Each segment is focusable and names
 * its count; the tiles next to it carry the same numbers as text.
 *
 * @param array $counts Counts per group.
 */
function link_ahead_ring( array $counts ) {
	if ( ! $counts['all'] ) {
		return;
	}
	$parts  = array(
		'linked'    => __( 'Linked', 'link-ahead' ),
		'waiting'   => __( 'Waiting', 'link-ahead' ),
		'attention' => __( 'Needs attention', 'link-ahead' ),
	);
	$r      = 44;
	$circ   = 2 * M_PI * $r;
	$offset = 0.0;
	$gap    = count( array_filter( array_intersect_key( $counts, $parts ) ) ) > 1 ? 2 : 0;
	$ready  = (int) round( ( $counts['linked'] + $counts['waiting'] ) / $counts['all'] * 100 );
	?>
	<figure class="link-ahead-ring">
		<svg viewBox="0 0 110 110" role="group" aria-label="<?php esc_attr_e( 'Placeholders by state', 'link-ahead' ); ?>">
			<circle class="link-ahead-ring-track" cx="55" cy="55" r="<?php echo esc_attr( (string) $r ); ?>"></circle>
			<?php foreach ( $parts as $key => $label ) : ?>
				<?php
				if ( ! $counts[ $key ] ) {
					continue;
				}
				$len = $counts[ $key ] / $counts['all'] * $circ;
				/* translators: 1: group such as "Waiting", 2: number of placeholders */
				$text = sprintf( __( '%1$s: %2$s', 'link-ahead' ), $label, number_format_i18n( $counts[ $key ] ) );
				?>
				<circle class="link-ahead-ring-seg is-<?php echo esc_attr( $key ); ?>" cx="55" cy="55" r="<?php echo esc_attr( (string) $r ); ?>" tabindex="0" role="img" aria-label="<?php echo esc_attr( $text ); ?>"
					stroke-dasharray="<?php echo esc_attr( round( max( 0.8, $len - $gap ), 2 ) . ' ' . round( $circ, 2 ) ); ?>" stroke-dashoffset="<?php echo esc_attr( (string) round( -$offset, 2 ) ); ?>"><title><?php echo esc_html( $text ); ?></title></circle>
				<?php $offset += $len; ?>
			<?php endforeach; ?>
		</svg>
		<figcaption><strong><?php echo esc_html( $ready ); ?>&nbsp;%</strong><span><?php esc_html_e( 'linked or scheduled', 'link-ahead' ); ?></span></figcaption>
	</figure>
	<?php
}

/**
 * What to do about each placeholder that needs attention, with a link to the place where it is done.
 *
 * @param array[] $rows Report rows.
 */
function link_ahead_fix_list( array $rows ) {
	$items = array();
	foreach ( $rows as $row ) {
		if ( 'attention' !== $row['state']['group'] ) {
			continue;
		}
		$key    = $row['state']['key'];
		$target = get_post( $row['target_id'] );
		$link   = '';
		$label  = '';
		if ( 'trash' === $key && $target && current_user_can( 'edit_post', $target->ID ) ) {
			$link  = admin_url( 'edit.php?post_status=trash&post_type=' . $target->post_type );
			$label = __( 'Open the trash', 'link-ahead' );
		} elseif ( in_array( $key, array( 'unscheduled', 'private', 'other' ), true ) && $target && current_user_can( 'edit_post', $target->ID ) ) {
			$link  = (string) get_edit_post_link( $target->ID );
			$label = 'unscheduled' === $key ? __( 'Schedule the target', 'link-ahead' ) : __( 'Edit the target', 'link-ahead' );
		} elseif ( current_user_can( 'edit_post', $row['source'] ) ) {
			$link  = (string) get_edit_post_link( $row['source'] );
			$label = __( 'Edit the placeholder', 'link-ahead' );
		}
		$items[] = array( $row, $link, $label );
	}
	if ( ! $items ) {
		return;
	}
	?>
	<section class="link-ahead-fix" aria-labelledby="link-ahead-fix-title">
		<h2 id="link-ahead-fix-title"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> <?php esc_html_e( 'To fix', 'link-ahead' ); ?> <span class="link-ahead-fix-n"><?php echo esc_html( number_format_i18n( count( $items ) ) ); ?></span></h2>
		<p class="link-ahead-fix-lead"><?php esc_html_e( 'These placeholders stay plain text for visitors until they are fixed.', 'link-ahead' ); ?></p>
		<ol>
			<?php foreach ( $items as $item ) : ?>
				<?php $row = $item[0]; ?>
				<li class="is-<?php echo esc_attr( $row['state']['key'] ); ?>">
					<span class="link-ahead-badge is-<?php echo esc_attr( $row['state']['key'] ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( link_ahead_state_icon( $row['state']['key'] ) ); ?>" aria-hidden="true"></span><?php echo esc_html( $row['state']['label'] ); ?></span>
					<span class="link-ahead-fix-what">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: link text, 2: title of the post that contains the placeholder */
								__( '"%1$s" in %2$s', 'link-ahead' ),
								$row['text'],
								get_the_title( $row['source'] )
							)
						);
						?>
					</span>
					<?php if ( '' !== $item[1] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $item[1] ); ?>"><?php echo esc_html( $item[2] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
	<?php
}
add_action( 'admin_enqueue_scripts', 'link_ahead_report_assets' );

/**
 * Upcoming publication dates of scheduled targets, for the timeline.
 *
 * @param array[] $rows Report rows with 'target_id' and 'state'.
 * @return array{items: array[], days: int}
 */
function link_ahead_timeline( array $rows ) {
	$now   = time();
	$items = array();
	foreach ( $rows as $row ) {
		if ( 'scheduled' !== $row['state']['key'] || isset( $items[ $row['target_id'] ] ) ) {
			continue;
		}
		$items[ $row['target_id'] ] = array(
			'id'    => $row['target_id'],
			'title' => get_the_title( $row['target_id'] ),
			'time'  => max( $now, $row['state']['time'] ),
			'count' => 0,
		);
	}
	foreach ( $rows as $row ) {
		if ( isset( $items[ $row['target_id'] ] ) && 'scheduled' === $row['state']['key'] ) {
			++$items[ $row['target_id'] ]['count'];
		}
	}
	uasort(
		$items,
		static function ( $a, $b ) {
			return $a['time'] - $b['time'];
		}
	);
	$last = $items ? end( $items )['time'] : $now;
	$days = max( 14, (int) ceil( ( $last - $now ) / DAY_IN_SECONDS ) + 1 );
	return array(
		'items' => array_values( $items ),
		'days'  => $days,
	);
}

/**
 * Renders the report page.
 */
function link_ahead_render_report() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	list( $raw, $truncated ) = link_ahead_collect();

	$rows   = array();
	$counts = array(
		'all'       => 0,
		'waiting'   => 0,
		'linked'    => 0,
		'attention' => 0,
	);
	foreach ( $raw as $row ) {
		// Visitors of a translated post get the translation in its language, so its state counts.
		$row['target_id'] = link_ahead_translated_id( $row['target'], $row['source'] );
		$row['state']     = link_ahead_target_state( $row['target_id'] );
		++$counts['all'];
		++$counts[ $row['state']['group'] ];
		$rows[] = $row;
	}
	$rank = array(
		'attention' => 0,
		'waiting'   => 1,
		'linked'    => 2,
	);
	usort(
		$rows,
		static function ( $a, $b ) use ( $rank ) {
			$by_group = $rank[ $a['state']['group'] ] - $rank[ $b['state']['group'] ];
			return 0 !== $by_group ? $by_group : $a['state']['time'] - $b['state']['time'];
		}
	);

	// The view only filters the read-only list; nothing is changed.
	$view = sanitize_key( (string) filter_input( INPUT_GET, 'state', FILTER_SANITIZE_SPECIAL_CHARS ) );
	$view = isset( $counts[ $view ] ) ? $view : 'all';
	$shown = 'all' === $view ? $rows : array_values(
		array_filter(
			$rows,
			static function ( $row ) use ( $view ) {
				return $row['state']['group'] === $view;
			}
		)
	);

	$views = array(
		'all'       => __( 'All', 'link-ahead' ),
		'attention' => __( 'Needs attention', 'link-ahead' ),
		'waiting'   => __( 'Waiting', 'link-ahead' ),
		'linked'    => __( 'Linked', 'link-ahead' ),
	);
	$tiles = array(
		'all'       => array( __( 'Placeholders', 'link-ahead' ), 'admin-links', __( 'in posts you can edit', 'link-ahead' ) ),
		'waiting'   => array( __( 'Waiting', 'link-ahead' ), 'calendar-alt', __( 'target is scheduled', 'link-ahead' ) ),
		'linked'    => array( __( 'Linked', 'link-ahead' ), 'yes-alt', __( 'target is published', 'link-ahead' ) ),
		'attention' => array( __( 'Needs attention', 'link-ahead' ), 'warning', __( 'stays text until fixed', 'link-ahead' ) ),
	);
	$timeline = link_ahead_timeline( $rows );
	$base_url = admin_url( 'tools.php?page=link-ahead' );
	?>
	<div class="wrap link-ahead-report">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p class="link-ahead-lead"><?php esc_html_e( 'Placeholder links to posts that are not published yet. They become links on their own once the target is published.', 'link-ahead' ); ?></p>
		<?php if ( $truncated ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %d: number of posts scanned. */
				echo esc_html( sprintf( __( 'Only the %d most recent posts with placeholders were scanned.', 'link-ahead' ), LINK_AHEAD_REPORT_LIMIT ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( ! $rows ) : ?>
			<div class="link-ahead-empty">
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'No placeholders found.', 'link-ahead' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'In the block editor, select the words that should become a link.', 'link-ahead' ); ?></li>
					<li><?php esc_html_e( 'Open the arrow menu of the formatting toolbar and choose Link Ahead.', 'link-ahead' ); ?></li>
					<li><?php esc_html_e( 'Pick a scheduled, draft or pending post. The words stay text until it is published.', 'link-ahead' ); ?></li>
				</ol>
				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php esc_html_e( 'Write a post', 'link-ahead' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="link-ahead-overview">
			<?php link_ahead_ring( $counts ); ?>
			<dl class="link-ahead-tiles">
				<?php foreach ( $tiles as $link_ahead_key => $link_ahead_tile ) : ?>
					<div class="link-ahead-tile is-<?php echo esc_attr( $link_ahead_key ); ?>">
						<dt><span class="dashicons dashicons-<?php echo esc_attr( $link_ahead_tile[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $link_ahead_tile[0] ); ?></dt>
						<dd><span class="link-ahead-num" data-la-count="<?php echo esc_attr( (string) $counts[ $link_ahead_key ] ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $link_ahead_key ] ) ); ?></span><span><?php echo esc_html( $link_ahead_tile[2] ); ?></span></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			</div>

			<?php if ( $timeline['items'] ) : ?>
				<section class="link-ahead-timeline" aria-labelledby="link-ahead-timeline-title">
					<h2 id="link-ahead-timeline-title"><?php esc_html_e( 'When the waiting links turn on', 'link-ahead' ); ?></h2>
					<div class="link-ahead-axis">
						<span class="link-ahead-axis-start" aria-hidden="true"><?php esc_html_e( 'Today', 'link-ahead' ); ?></span>
						<span class="link-ahead-axis-end" aria-hidden="true">
							<?php
							/* translators: %d: number of days. */
							echo esc_html( sprintf( _n( '+%d day', '+%d days', $timeline['days'], 'link-ahead' ), $timeline['days'] ) );
							?>
						</span>
						<?php foreach ( $timeline['items'] as $link_ahead_i => $link_ahead_item ) : ?>
							<?php $link_ahead_pos = min( 100, max( 0, ( $link_ahead_item['time'] - time() ) / ( $timeline['days'] * DAY_IN_SECONDS ) * 100 ) ); ?>
							<?php
							/* translators: 1: number on the timeline, 2: post title, 3: date and time */
							$link_ahead_dot_text = sprintf( __( '%1$s. %2$s, %3$s', 'link-ahead' ), number_format_i18n( $link_ahead_i + 1 ), $link_ahead_item['title'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link_ahead_item['time'] ) );
							?>
							<a class="link-ahead-dot<?php echo esc_attr( $link_ahead_pos > 80 ? ' is-right' : ( $link_ahead_pos < 20 ? ' is-left' : '' ) ); ?>" href="<?php echo esc_attr( '#link-ahead-date-' . ( $link_ahead_i + 1 ) ); ?>" style="<?php echo esc_attr( 'left:' . round( $link_ahead_pos, 2 ) . '%' ); ?>" data-la-target="<?php echo esc_attr( (string) $link_ahead_item['id'] ); ?>" data-la-title="<?php echo esc_attr( $link_ahead_item['title'] ); ?>" aria-label="<?php echo esc_attr( $link_ahead_dot_text ); ?>"><?php echo esc_html( number_format_i18n( $link_ahead_i + 1 ) ); ?><span class="link-ahead-dot-tip" aria-hidden="true"><?php echo esc_html( $link_ahead_dot_text ); ?></span></a>
						<?php endforeach; ?>
					</div>
					<ol class="link-ahead-dates">
						<?php foreach ( $timeline['items'] as $link_ahead_i => $link_ahead_item ) : ?>
							<li id="<?php echo esc_attr( 'link-ahead-date-' . ( $link_ahead_i + 1 ) ); ?>" tabindex="-1">
								<strong><?php echo esc_html( $link_ahead_item['title'] ); ?></strong>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: date and time, 2: relative time such as "3 days", 3: number of placeholders. */
											_n( '%1$s, in %2$s · %3$d placeholder', '%1$s, in %2$s · %3$d placeholders', $link_ahead_item['count'], 'link-ahead' ),
											wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link_ahead_item['time'] ),
											human_time_diff( time(), $link_ahead_item['time'] ),
											$link_ahead_item['count']
										)
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<?php link_ahead_fix_list( $rows ); ?>

			<ul class="subsubsub">
				<?php $link_ahead_n = 0; ?>
				<?php foreach ( $views as $link_ahead_key => $link_ahead_label ) : ?>
					<li>
						<?php echo $link_ahead_n++ ? ' | ' : ''; ?>
						<a data-la-view="<?php echo esc_attr( $link_ahead_key ); ?>" href="<?php echo esc_url( 'all' === $link_ahead_key ? $base_url : add_query_arg( 'state', $link_ahead_key, $base_url ) ); ?>"<?php echo $view === $link_ahead_key ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $link_ahead_label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $link_ahead_key ] ) ); ?>)</span></a>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="clear"></div>
			<div class="link-ahead-tools" data-la-tools data-la-view-now="<?php echo esc_attr( $view ); ?>" hidden></div>

			<?php if ( ! $shown ) : ?>
				<p class="link-ahead-none"><?php esc_html_e( 'No placeholders in this view.', 'link-ahead' ); ?></p>
			<?php else : ?>
				<table class="widefat striped link-ahead-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'In post', 'link-ahead' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Link text', 'link-ahead' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Target', 'link-ahead' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'link-ahead' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $shown as $row ) : ?>
						<?php
						$target = $row['target_id'];
						$state  = $row['state'];
						$hint   = link_ahead_state_hint( $state['key'] );
						?>
						<tr data-la-group="<?php echo esc_attr( $state['group'] ); ?>" data-la-target="<?php echo esc_attr( (string) $target ); ?>">
							<td data-label="<?php esc_attr_e( 'In post', 'link-ahead' ); ?>"><a href="<?php echo esc_url( (string) get_edit_post_link( $row['source'] ) ); ?>"><?php echo esc_html( get_the_title( $row['source'] ) ); ?></a></td>
							<td data-label="<?php esc_attr_e( 'Link text', 'link-ahead' ); ?>"><span class="link-ahead-text"><?php echo esc_html( $row['text'] ); ?></span></td>
							<td data-label="<?php esc_attr_e( 'Target', 'link-ahead' ); ?>">
								<?php if ( get_post( $target ) && current_user_can( 'edit_post', $target ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $target ) ); ?>"><?php echo esc_html( get_the_title( $target ) ); ?></a>
								<?php else : ?>
									<?php echo esc_html( '#' . $target ); ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'State', 'link-ahead' ); ?>">
								<span class="link-ahead-badge is-<?php echo esc_attr( $state['key'] ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( link_ahead_state_icon( $state['key'] ) ); ?>" aria-hidden="true"></span><?php echo esc_html( $state['label'] ); ?></span>
								<?php if ( 'scheduled' === $state['key'] && $state['time'] > time() ) : ?>
									<span class="link-ahead-sub">
										<?php
										/* translators: %s: relative time such as "3 days". */
										echo esc_html( sprintf( __( 'Turns into a link in %s.', 'link-ahead' ), human_time_diff( time(), $state['time'] ) ) );
										?>
									</span>
								<?php elseif ( '' !== $hint ) : ?>
									<span class="link-ahead-sub"><?php echo esc_html( $hint ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
