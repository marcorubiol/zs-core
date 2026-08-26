<?php
/**
 * Plugin Name:  Zerø — Trust Cloudflare HTTPS at the origin
 * Description:  Fleet fix. Makes is_ssl() true when a request reached WordPress over
 *               HTTPS but TLS was terminated at Cloudflare, so PHP at the origin sees
 *               plain HTTP. Without it WordPress refuses Application Password auth (its
 *               is_ssl() gate) and hides the app-password admin UI — which blocks the
 *               fleet engine's FlowGuard onboard mint (M5) and any REST Basic-Auth on
 *               every Cloudflare-fronted site.
 * Version:      1.1.0
 * Author:       Zerø Sense
 * Author URI:   https://zerosense.studio
 * License:      GPL-2.0-or-later
 *
 * What it does
 * ------------
 * Cloudflare terminates TLS at the edge and connects to the origin (often over plain
 * HTTP). PHP therefore sees $_SERVER['HTTPS'] empty and is_ssl() returns false, even
 * though the visitor is on HTTPS. WordPress uses is_ssl() to gate Application Passwords
 * (both the authentication handler and the profile UI), so on a Cloudflare-fronted
 * origin a minted app-password is unusable — exactly the M5 block the fleet onboard
 * engine reports ("WP does not see HTTPS (is_ssl() false) — an app-password would be
 * refused").
 *
 * This module, loaded as an mu-plugin BEFORE REST authentication and the profile
 * render, sets $_SERVER['HTTPS']='on' when a trusted proxy says the visitor arrived
 * over HTTPS. is_ssl() then returns true for the rest of the request.
 *
 * Trust boundary (read this)
 * --------------------------
 * We trip on Cloudflare's CF-Visitor header ({"scheme":"https"}) and nothing else.
 * That header is only trustworthy if the origin is NOT reachable directly: an attacker
 * able to hit the origin over plain HTTP can forge it and make WordPress believe the
 * request is secure, then use a captured app-password over an unencrypted link. The
 * guarantee holds only while the origin firewall accepts Cloudflare IP ranges ONLY.
 * On an origin that is genuinely HTTP-only (no proxy, no TLS anywhere) this module is
 * inert — the header is absent.
 *
 * ⚠ AS OF 2026-08-26 THAT PRECONDITION IS NOT MET ON THIS FLEET.
 * Audited all 15 sites in the maintenance registry: the origin (152.53.150.2) answers
 * plain HTTP from any address for any Host, and the shim demonstrably trips on a forged
 * header — /wp-admin/ returns 301 without it and 302 with it, on every site. The origin
 * has no IP filtering, so the same reachability also makes Cloudflare's WAF and the
 * fleet's wp-login rate limit optional for anyone who addresses the IP directly.
 *
 * Checking REMOTE_ADDR against Cloudflare's ranges does NOT fix this. The fleet runs
 * OpenLiteSpeed with useIpInProxyHeader = 2 and Cloudflare trusted, so a legitimate
 * proxied request arrives with REMOTE_ADDR already rewritten to the visitor's address —
 * not a Cloudflare one. Genuine and forged requests are indistinguishable at PHP level.
 *
 * The real fix is a secret the edge knows and a direct caller cannot: a Cloudflare
 * Transform Rule adding a per-fleet header, checked here before the scheme is trusted.
 * Until that exists, treat this module as convenience, not as a security boundary.
 *
 * Why a define() opt-out and not a filter
 * ---------------------------------------
 * This runs at module-load (mu-plugin) time, before ordinary plugins — so a
 * per-site plugin's add_filter() has not registered yet and could not be honoured.
 * The opt-out therefore lives in wp-config.php (loaded before mu-plugins):
 *
 *     define( 'ZS_FLEET_NO_PROXY_HTTPS', true );
 *
 * To disable fleet-wide: delete this file from modules/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does a trusted proxy signal that this request reached us over HTTPS?
 * Pure: reads only the passed server array, so it is unit-testable without WordPress.
 *
 * @param array $server A $_SERVER-shaped array.
 * @return bool
 */
function zs_fleet_proxy_says_https( array $server ) {
	// Cloudflare CF-Visitor: {"scheme":"https"} (strip spaces defensively).
	if ( isset( $server['HTTP_CF_VISITOR'] ) ) {
		$cf = str_replace( ' ', '', (string) $server['HTTP_CF_VISITOR'] );
		if ( false !== strpos( $cf, '"scheme":"https"' ) ) {
			return true;
		}
	}
	// No generic X-Forwarded-Proto fallback. Every proxied site on this fleet sits
	// behind Cloudflare and therefore sends CF-Visitor; the fallback bought nothing
	// and widened what an attacker had to guess. Removing it does NOT make the
	// module safe on a reachable origin — CF-Visitor is just as forgeable — it only
	// stops the module answering to a header that no legitimate request here sends.
	return false;
}

/**
 * Is $_SERVER already flagged secure? Mirrors is_ssl()'s $_SERVER['HTTPS'] test so we
 * stay independent of load order (we never call is_ssl() ourselves).
 *
 * @param array $server A $_SERVER-shaped array.
 * @return bool
 */
function zs_fleet_server_already_https( array $server ) {
	if ( ! isset( $server['HTTPS'] ) ) {
		return false;
	}
	$v = strtolower( (string) $server['HTTPS'] );
	return ( 'on' === $v || '1' === $v );
}

// ── Apply the shim (unless opted out, or the request is already secure). ──
if ( ! ( defined( 'ZS_FLEET_NO_PROXY_HTTPS' ) && ZS_FLEET_NO_PROXY_HTTPS )
	&& ! zs_fleet_server_already_https( $_SERVER )
	&& zs_fleet_proxy_says_https( $_SERVER ) ) {
	$_SERVER['HTTPS'] = 'on';
}
