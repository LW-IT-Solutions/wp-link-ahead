<?php
/**
 * Plugin Name:       Link Ahead
 * Description:       Link to scheduled or draft posts today. The link stays plain text until the target is published, then it turns into a real link on its own.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            LW IT Solutions
 * Author URI:        https://www.lukaswojcik.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       link-ahead
 *
 * @package LinkAhead
 */

defined( 'ABSPATH' ) || exit;

define( 'LINK_AHEAD_VERSION', '0.3.0' );
define( 'LINK_AHEAD_FILE', __FILE__ );

require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/editor.php';
require_once __DIR__ . '/includes/report.php';
