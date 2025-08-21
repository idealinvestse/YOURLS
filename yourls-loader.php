<?php
/*
 * yourls-loader.php — Front loader and dispatcher
 *
 * Responsibilities:
 * - Handle special root requests that are not part of YOURLS routing (eg, /favicon.ico, /robots.txt)
 * - Bootstrap YOURLS core
 * - Parse the incoming request path into a candidate keyword and optional stats flag
 * - Route to the appropriate front controller:
 *   - yourls-go.php for redirect/page rendering
 *   - yourls-infos.php for statistics pages (keyword+ or keyword+all)
 * - Fallback to site root if nothing matches
 */

// Handle inexistent root favicon requests and exit early. This avoids 404s in logs
// and provides a tiny embedded image without hitting the full framework.
if ( '/favicon.ico' == $_SERVER['REQUEST_URI'] ) {
	header( 'Content-Type: image/gif' );
	echo base64_decode( "R0lGODlhEAAQAJECAAAAzFZWzP///wAAACH5BAEAAAIALAAAAAAQABAAAAIplI+py+0PUQAgSGoNQFt0LWTVOE6GuX1H6onTVHaW2tEHnJ1YxPc+UwAAOw==" );
	exit;
}

// Handle inexistent root robots.txt requests similarly: return a minimal file that
// instructs crawlers without going through the full app.
if ( '/robots.txt' == $_SERVER['REQUEST_URI'] ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "User-agent: *\n";
	echo "Disallow:\n";
	exit;
}

// Load YOURLS core (config, DB, plugins, helper functions). Past this point we
// can use YOURLS APIs to interpret and route the request.
require_once __DIR__ . '/includes/load-yourls.php';

// Extract the request path relative to YOURLS base.
// Example: for 'http://sho.rt/yourls/abcd', this returns 'abcd'.
// Note: this value is NOT sanitized yet; treat it as raw input.
$request = yourls_get_request();

// Hook for plugins to inspect/modify behavior before routing to a template/front
// controller.
yourls_do_action( 'pre_load_template', $request );

// Parse the request for 2 shapes:
//  - "anything"       => keyword
//  - "anything+"      => stats page for that keyword
//  - "anything+all"   => aggregated stats when duplicates are allowed
preg_match( "@^(.+?)(\+(all)?)?/?$@", $request, $matches );
$keyword   = isset($matches[1]) ? $matches[1] : null; // 'anything' whatever the request is (keyword, bookmarklet URL...)
$stats     = isset($matches[2]) ? $matches[2] : null; // null, or '+' if request is 'anything+', '+all' if request is 'anything+all'
$stats_all = isset($matches[3]) ? $matches[3] : null; // null, or 'all' if request is 'anything+all'

// If the request looks like a full URL (has a scheme), this is a special case
// used by the "Prefix-n-Shorten" feature: redirect to the admin bookmarklet to
// pre-fill the URL for shortening.
if ( yourls_get_protocol($keyword) ) {
	$url = yourls_sanitize_url_safe($keyword);
	$parse = yourls_get_protocol_slashes_and_rest( $url, [ 'up', 'us', 'ur' ] );
	yourls_do_action( 'load_template_redirect_admin', $url );
	yourls_do_action( 'pre_redirect_bookmarklet', $url );

	// Redirect to /admin/index.php?up=<url protocol>&us=<url slashes>&ur=<url rest>
	yourls_redirect( yourls_add_query_arg( $parse , yourls_admin_url( 'index.php' ) ), 302 );
	exit;
}

// If the request is an existing short URL keyword ("abc") or an existing YOURLS
// page, route accordingly. The presence of $stats controls whether we show the
// redirect/page or the stats.
if ( yourls_keyword_is_taken($keyword) or yourls_is_page($keyword) ) {

	// we have a short URL or a page
	if( $keyword && !$stats ) {
		// Normal short URL or page: hand off to the go controller which handles
		// redirection or page rendering.
		yourls_do_action( 'load_template_go', $keyword );
		require_once( YOURLS_ABSPATH.'/yourls-go.php' );
		exit;
	}

	// we have a stat page
	if( $keyword && $stats ) {
		// Stats page requested ("keyword+" or "keyword+all"). Aggregate across
		// duplicates when allowed and +all was requested.
		$aggregate = $stats_all && yourls_allow_duplicate_longurls();
		yourls_do_action( 'load_template_infos', $keyword );
		require_once( YOURLS_ABSPATH.'/yourls-infos.php' );
		exit;
	}

}

// Fallback: unrecognized request (not a valid short URL, not a bookmarklet, not
// a page). Give plugins a chance to react, then send the user back to site root.
yourls_do_action( 'redirect_keyword_not_found', $keyword );
yourls_do_action( 'loader_failed', $request );
yourls_redirect( YOURLS_SITE, 302 );
exit;
