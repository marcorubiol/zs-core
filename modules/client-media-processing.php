<?php
/**
 * Plugin Name:  Zerø — Server-side Media Processing
 * Description:  Fleet policy. Turns off WordPress 7.1 client-side media processing so image
 *               resizing, thumbnails and format conversion keep running on the server, where
 *               the fleet's image tooling hooks in.
 * Version:      1.0.0
 * Author:       Zerø Sense
 * Author URI:   https://zerosense.studio
 * License:      GPL-2.0-or-later
 *
 * Why
 * ---
 * WordPress 7.1 moves resize/compress/thumbnail generation into the uploader's browser
 * (Chromium 137+). On that path no server-side image editor runs, so `wp_image_editors`,
 * `image_memory_limit` and `image_make_intermediate_size` never fire. That is exactly where
 * wp-compress-image-optimizer, the fleet's image optimizer, attaches. The failure mode is
 * silent: uploads succeed, the optimizer just stops seeing them, and only in some browsers.
 *
 * The filter is the documented off switch (make.wordpress.org/core, "Client-Side Media
 * Processing in WordPress 7.1", 2026-07-22). Uploads fall back to the pre-7.1 server path.
 * On WordPress < 7.1 the filter is never read, so this module is inert there.
 *
 * Re-evaluate when WP-Compress states support for the 7.1 finalize path; then delete this file.
 * Decision: 03_AGENCY/Fleet/_decisions.md § WordPress 7.1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
