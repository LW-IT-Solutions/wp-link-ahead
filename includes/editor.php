<?php
/**
 * Block editor: a rich-text format "Link Ahead" in the formatting toolbar.
 *
 * The script is plain JavaScript on top of the wp.* globals, so it ships
 * without a build step and the file in the plugin is the source.
 *
 * @package LinkAhead
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the format script and its translations in the block editor.
 */
function linkahead_enqueue_editor() {
	$handle = 'link-ahead-editor';
	wp_enqueue_script(
		$handle,
		plugins_url( 'assets/editor.js', LINKAHEAD_FILE ),
		array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-rich-text', 'wp-block-editor', 'wp-url' ),
		LINKAHEAD_VERSION,
		true
	);
	wp_set_script_translations( $handle, 'link-ahead' );
	linkahead_enqueue_editor_style();
}
add_action( 'enqueue_block_editor_assets', 'linkahead_enqueue_editor' );

/**
 * The same small stylesheet serves the popover (outer editor frame) and the
 * dotted underline of placeholders (content iframe). Front end stays untouched.
 */
function linkahead_enqueue_editor_style() {
	if ( ! is_admin() ) {
		return;
	}
	wp_enqueue_style(
		'link-ahead-editor',
		plugins_url( 'assets/editor.css', LINKAHEAD_FILE ),
		array(),
		LINKAHEAD_VERSION
	);
}
add_action( 'enqueue_block_assets', 'linkahead_enqueue_editor_style' );
