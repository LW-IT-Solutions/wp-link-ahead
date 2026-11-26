<?php
/**
 * Front end: turns placeholder anchors into links once their target is published.
 *
 * Stored markup: <a class="link-ahead" data-link-ahead="123">text</a>
 * An <a> without href is valid HTML and renders as plain text, so content stays
 * readable even if the plugin is deactivated.
 *
 * @package LinkAhead
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the ID of the version of $post_id that fits the current context.
 *
 * With Polylang or WPML, a placeholder in a German post that points to the
 * English original resolves to the German translation, if there is one.
 *
 * @param int $post_id    Target post ID as stored in the content.
 * @param int $context_id ID of the post that contains the placeholder, 0 if unknown.
 * @return int
 */
function link_ahead_translated_id( $post_id, $context_id ) {
	if ( $context_id && function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' ) ) {
		$language = pll_get_post_language( $context_id );
		if ( $language ) {
			$translated = pll_get_post( $post_id, $language );
			if ( $translated ) {
				return (int) $translated;
			}
		}
	}
	if ( has_filter( 'wpml_object_id' ) ) {
		// Language of the post that contains the placeholder; without it, WPML uses the language of the request.
		$language = null;
		if ( $context_id ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- documented WPML API filter.
			$details  = apply_filters( 'wpml_post_language_details', null, $context_id );
			$language = is_array( $details ) && ! empty( $details['language_code'] ) && is_string( $details['language_code'] ) ? sanitize_key( $details['language_code'] ) : null;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- documented WPML API filter.
		$translated = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), true, $language );
		if ( $translated ) {
			return (int) $translated;
		}
	}
	return (int) $post_id;
}

/**
 * Permalink of a target that visitors may see, or an empty string.
 *
 * @param int $post_id Target post ID.
 * @return string
 */
function link_ahead_public_url( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
		return '';
	}
	return (string) get_permalink( $post );
}

/**
 * Filters post content and resolves all placeholders.
 *
 * @param string $content Rendered content.
 * @return string
 */
function link_ahead_filter_content( $content ) {
	if ( ! is_string( $content ) || false === strpos( $content, 'data-link-ahead' ) ) {
		return $content;
	}

	$context_id = (int) get_the_ID();
	$tags       = new WP_HTML_Tag_Processor( $content );

	while ( $tags->next_tag( 'a' ) ) {
		$target = $tags->get_attribute( 'data-link-ahead' );
		if ( null === $target || ! ctype_digit( (string) $target ) ) {
			continue;
		}

		$url = link_ahead_public_url( link_ahead_translated_id( (int) $target, $context_id ) );
		if ( '' !== $url ) {
			$tags->set_attribute( 'href', $url );
			$tags->remove_class( 'link-ahead--pending' );
		} else {
			// Never leak the address of an unpublished post.
			$tags->remove_attribute( 'href' );
			$tags->add_class( 'link-ahead--pending' );
		}
	}

	return $tags->get_updated_html();
}
add_filter( 'the_content', 'link_ahead_filter_content', 20 );

/**
 * Shortcode for the classic editor: [link_ahead id="123"]text[/link_ahead].
 *
 * @param array|string $atts    Shortcode attributes.
 * @param string|null  $content Link text.
 * @return string
 */
function link_ahead_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'link_ahead' );
	$id   = absint( $atts['id'] );
	$text = null === $content ? '' : do_shortcode( $content );

	if ( ! $id || '' === $text ) {
		return wp_kses_post( $text );
	}

	$url = link_ahead_public_url( link_ahead_translated_id( $id, (int) get_the_ID() ) );
	if ( '' === $url ) {
		return '<a class="link-ahead link-ahead--pending" data-link-ahead="' . esc_attr( $id ) . '">' . wp_kses_post( $text ) . '</a>';
	}
	return '<a class="link-ahead" data-link-ahead="' . esc_attr( $id ) . '" href="' . esc_url( $url ) . '">' . wp_kses_post( $text ) . '</a>';
}
add_shortcode( 'link_ahead', 'link_ahead_shortcode' );

/**
 * Finds posts whose content contains a placeholder for one of the given IDs.
 *
 * @param int[] $target_ids Target post IDs (all translations of one post).
 * @param int   $limit      Maximum number of results.
 * @return int[] Post IDs.
 */
function link_ahead_find_linking_posts( array $target_ids, $limit = 200 ) {
	$target_ids = array_filter( array_map( 'absint', $target_ids ) );
	$found      = array();

	foreach ( $target_ids as $target_id ) {
		$found = array_merge( $found, link_ahead_search_content( 'data-link-ahead="' . $target_id . '"', $limit ) );
	}

	// Shortcode placeholders can quote the ID in any way, so they are matched after parsing.
	foreach ( link_ahead_search_content( '[link_ahead', $limit ) as $post_id ) {
		$targets = wp_list_pluck( link_ahead_shortcode_placeholders( (string) get_post_field( 'post_content', $post_id ) ), 'target' );
		if ( array_intersect( $target_ids, $targets ) ) {
			$found[] = $post_id;
		}
	}

	return array_slice( array_values( array_unique( $found ) ), 0, $limit );
}

/**
 * Placeholders written as [link_ahead id="123"]text[/link_ahead].
 *
 * @param string $content Post content.
 * @return array[] Each with target ID and link text.
 */
function link_ahead_shortcode_placeholders( $content ) {
	$found = array();
	if ( false === strpos( $content, '[link_ahead' ) ) {
		return $found;
	}
	if ( ! preg_match_all( '/' . get_shortcode_regex( array( 'link_ahead' ) ) . '/s', $content, $matches, PREG_SET_ORDER ) ) {
		return $found;
	}
	foreach ( $matches as $match ) {
		// [[link_ahead]] is an escaped shortcode and prints as text.
		if ( '[' === $match[1] && ']' === $match[6] ) {
			continue;
		}
		$atts = shortcode_parse_atts( $match[3] );
		$id   = is_array( $atts ) && isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;
		if ( $id ) {
			$found[] = array(
				'target' => $id,
				'text'   => $match[5],
			);
		}
	}
	return $found;
}

/**
 * Post IDs whose content contains $needle, through WP_Query's exact-phrase search.
 *
 * The language filter of Polylang is switched off, so the search covers every
 * language. Title and excerpt are searched as well; a placeholder never stands
 * there, so this does not add hits.
 *
 * @param string $needle Exact text to look for.
 * @param int    $limit  Maximum number of results.
 * @return int[]
 */
function link_ahead_search_content( $needle, $limit ) {
	$query = new WP_Query(
		array(
			's'                => $needle,
			'sentence'         => true,
			'post_type'        => 'any',
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'fields'           => 'ids',
			'posts_per_page'   => (int) $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'lang'             => '',
		)
	);
	return array_map( 'intval', $query->posts );
}

/**
 * All IDs that stand for the same post: the post itself and its translations.
 *
 * @param int $post_id Post ID.
 * @return int[]
 */
function link_ahead_translation_ids( $post_id ) {
	$ids = array( (int) $post_id );
	if ( function_exists( 'pll_get_post_translations' ) ) {
		$ids = array_merge( $ids, array_map( 'intval', (array) pll_get_post_translations( $post_id ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * When a target is published, or a published target is unpublished, clear the
 * object cache of the posts that link to it and tell caching plugins about it.
 * Otherwise a cached page keeps plain text after publication, or a link to a
 * page that no longer exists after unpublishing.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post object.
 */
function link_ahead_on_publish( $new_status, $old_status, $post ) {
	$published = 'publish' === $new_status;
	if ( $published === ( 'publish' === $old_status ) || ! $post instanceof WP_Post ) {
		return;
	}
	$linking = link_ahead_find_linking_posts( link_ahead_translation_ids( $post->ID ) );
	if ( ! $linking ) {
		return;
	}
	foreach ( $linking as $id ) {
		clean_post_cache( $id );
	}
	link_ahead_purge_linking_pages( $linking );

	if ( ! $published ) {
		/**
		 * Fires when a published post that other posts link to through Link Ahead
		 * is unpublished (draft, private, trash, scheduled again).
		 *
		 * Page-cache plugins can use this to purge the pages of $linking_ids.
		 *
		 * @param int   $post_id     The unpublished post.
		 * @param int[] $linking_ids Posts that contain a placeholder for it.
		 */
		do_action( 'link_ahead_target_unpublished', $post->ID, $linking );
		return;
	}

	/**
	 * Fires when a post is published that other posts link to through Link Ahead.
	 *
	 * Page-cache plugins can use this to purge the pages of $linking_ids.
	 *
	 * @param int   $post_id     The published post.
	 * @param int[] $linking_ids Posts that contain a placeholder for it.
	 */
	do_action( 'link_ahead_target_published', $post->ID, $linking );
}
add_action( 'transition_post_status', 'link_ahead_on_publish', 10, 3 );

/**
 * Purges the cached pages of the linking posts in common page-cache plugins.
 *
 * Only the public purge functions or actions of these plugins are used, each
 * one only if its plugin is active. Other caches can hook into link_ahead_target_published
 * and link_ahead_target_unpublished.
 *
 * @param int[] $post_ids Posts whose cached pages are outdated.
 */
function link_ahead_purge_linking_pages( array $post_ids ) {
	/**
	 * Filters whether Link Ahead purges page caches of linking posts.
	 *
	 * @param bool  $purge    Default true.
	 * @param int[] $post_ids Posts that would be purged.
	 */
	if ( ! apply_filters( 'link_ahead_purge_page_caches', true, $post_ids ) ) {
		return;
	}
	foreach ( $post_ids as $id ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- public purge actions of the respective cache plugins.
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_post', $id ); // LiteSpeed Cache.
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			do_action( 'cache_enabler_clear_page_cache_by_post', $id ); // Cache Enabler.
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			wpsc_delete_post_cache( $id ); // WP Super Cache.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $id ); // W3 Total Cache.
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id ); // WP Rocket.
		}
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $id ); // WP Fastest Cache.
		}
	}
}
