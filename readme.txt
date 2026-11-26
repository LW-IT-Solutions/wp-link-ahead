=== Link Ahead ===
Contributors: lwitsolutions
Tags: internal links, scheduled posts, editorial, series, polylang
Requires at least: 6.5
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Link to scheduled or draft posts today. The text stays unlinked until the target is published, then it becomes a link on its own.

== Description ==

Planning a series means writing part 1 while part 3 is still scheduled. A normal link to part 3 leads to a 404 page until its publication date, and adding the links later means editing every earlier post again.

Link Ahead solves this with a placeholder link:

* Select text in the block editor, click **Link Ahead** in the toolbar and pick a scheduled, draft or pending post or page.
* Until the target is published, visitors see plain text. The address of the unpublished post is never output.
* From the moment the target is published, the same text is a normal link to it.
* With Polylang or WPML, a placeholder resolves to the translation in the language of the current post.
* **Tools → Link Ahead** lists the placeholders, including shortcodes, in the posts you can edit, with the source post and the current state of the target in the language of that post: scheduled, published, still a draft, private, in the trash or missing. It reads up to the 500 most recent posts that contain placeholders and says so when there are more.

= How it works =

The editor stores `<a class="link-ahead" data-link-ahead="123">text</a>`. An anchor without `href` is valid HTML and is displayed as plain text. When a post is rendered, the plugin adds the `href` for every target that is published and publicly viewable.

Because the placeholder is ordinary markup, deactivating the plugin does not break anything: the text simply stays unlinked. The plugin stores no options and no data of its own.

For the classic editor there is a shortcode: `[link_ahead id="123"]link text[/link_ahead]`. Unlike the block editor markup, a shortcode shows as plain shortcode text while the plugin is deactivated.

= Page caches =

A page cache may still hold the old copy of a linking post, with plain text where the link should now be, or with a link to a target that was unpublished again. When a target is published, or a published target is set back to draft, private, scheduled or trash, Link Ahead therefore:

* clears the object cache of the posts that link to it (up to 200, the most recent first),
* asks these page caches to purge the linking posts, if they are active: LiteSpeed Cache, WP Super Cache, W3 Total Cache, WP Rocket, WP Fastest Cache and Cache Enabler (only their public purge functions or actions are used),
* fires the action `link_ahead_target_published( $post_id, $linking_ids )`, or `link_ahead_target_unpublished( $post_id, $linking_ids )`, so other caches can purge exactly those pages. The actions only fire if at least one post links to the target.

Many caching plugins already clear more than the published post itself when something is published (WP Super Cache does in its default settings). The purge above covers setups that only clear the published post. It can be switched off with `add_filter( 'link_ahead_purge_page_caches', '__return_false' );`.

= Privacy =

Link Ahead makes no external requests, sets no cookies and stores no personal data.

== Installation ==

1. Install the plugin through **Plugins → Add New** or upload the `link-ahead` folder to `/wp-content/plugins/`.
2. Activate it.
3. In the block editor, select text and choose **Link Ahead** from the formatting toolbar (the arrow menu for more formats).

== Frequently Asked Questions ==

= Can visitors find out the address of the scheduled post? =

No. As long as the target is not published, the anchor has no `href`. The plugin removes any `href` that was added to a placeholder by hand.

= What happens if the target is deleted or never published? =

The text stays unlinked. **Tools → Link Ahead** marks such placeholders so they can be fixed.

= Does it work with custom post types? =

The front end resolves placeholders for any public post type. The picker in the editor searches posts and pages.

= Why does a published target still show as text? =

A page cache is most likely serving an older copy. Purge the page cache or wait until it expires.

== Screenshots ==

1. Picking a scheduled post in the block editor.
2. Tools → Link Ahead lists all placeholders and their targets.

== Changelog ==

= 0.2.0 =
* Tools → Link Ahead opens with tiles for all, waiting, linked and placeholders that need attention, plus filter views for each group.
* A timeline shows when the waiting links turn on, with date, relative time and number of placeholders per target.
* Every state has a colored badge with an icon; placeholders that need attention are listed first, each with a short hint what to do.
* Empty report: a short guide how to add the first placeholder.
* The link picker in the editor shows the status as a colored marker and the post type as a label; placeholders are tinted in the editor canvas.
* Narrow screens: the report table turns into stacked cards, filter links are at least 40 px high.
* Front end, stored markup and cache purging are unchanged.

= 0.1.1 =
* With WPML, a placeholder resolves to the translation in the language of the post that contains it, also in the report under Tools.
* The readme states the limits of the report (500 posts) and of the cache purge (200 linking posts).
* One prefix for all PHP functions and constants (link_ahead_). Shortcode, actions, filter and stored markup are unchanged.

= 0.1.0 =
* First version: placeholder format for the block editor, rendering on the front end, Polylang and WPML support, shortcode, report under Tools.
