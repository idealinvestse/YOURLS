<?php
/*
 * yourls-go.php — Front controller for short URL redirection and page routing
 *
 * This script is the public entry point that handles incoming requests of the form:
 *   https://example.tld/KEYWORD
 * It determines whether KEYWORD represents:
 *   - a YOURLS "page" (static/dynamic content handled by YOURLS), or
 *   - a short URL keyword that should redirect to a long URL stored in the DB, or
 *   - an unknown or reserved keyword, which results in a fallback redirect.
 *
 * The actual parsing of the requested keyword (eg, from PATH_INFO or REQUEST_URI)
 * happens earlier in the bootstrap sequence (see yourls-loader.php). Here, we
 * simply consume the resulting $keyword and decide what to do with it.
 */

// Flag to inform the bootstrap and plugins that we are in the "go" (redirect) context.
// Some components may alter behavior depending on whether the request is an admin call,
// API call, or a public redirect.
define( 'YOURLS_GO', true );

// Load YOURLS core, configuration, and plugin system. This sets up constants like
// YOURLS_SITE, connects to the database, registers hooks, and defines helper functions
// used below (yourls_is_page, yourls_redirect_shorturl, etc.).
require_once( dirname( __FILE__ ) . '/includes/load-yourls.php' );

// NOTE: The variable $keyword is populated by earlier bootstrap code in yourls-loader.php.
// It typically contains the path segment after the domain (eg, "abc123" from "/abc123").
// If for any reason $keyword is not set, we cannot determine the intent of the request,
// so we treat it as a request to the site root and perform a permanent redirect (301).
if( !isset( $keyword ) ) {
	// Allow plugins to react when no keyword is present (eg, analytics, logging, ACLs).
	yourls_do_action( 'redirect_no_keyword' );
	// Safety net: send the user to the YOURLS site root.
	yourls_redirect( YOURLS_SITE, 301 );
}

// Sanitize the keyword to a canonical, safe form. This typically enforces allowed
// characters, case rules, and removes/normalizes anything unexpected to prevent issues
// like SQL injection, header injection, or routing ambiguities.
$keyword = yourls_sanitize_keyword( $keyword );

// Apply a conservative, configurable maximum length guard. Extremely long
// keywords are treated as unknown to avoid unnecessary processing and to
// protect against pathological requests.
$max_keyword_length = defined( 'YOURLS_MAX_KEYWORD_LENGTH' ) ? (int) YOURLS_MAX_KEYWORD_LENGTH : 128;
if ( $max_keyword_length > 0 && strlen( $keyword ) > $max_keyword_length ) {
	if ( function_exists( 'yourls_log' ) ) {
		yourls_log( 'warning', 'keyword_too_long', [ 'keyword' => $keyword, 'max' => $max_keyword_length ] );
	}
	yourls_handle_unknown_keyword( $keyword, 'go' );
}

// Check if the requested keyword maps to a YOURLS "page" instead of a short URL.
// Pages are user- or system-defined routes (eg, "/about") rendered by YOURLS itself.
// If it's a page, render it and stop further processing.
if( yourls_is_page( $keyword ) ) {
    // Render the page associated with $keyword (handles headers and output).
    yourls_page( $keyword );
    // Explicitly stop so we do not attempt to resolve it as a short URL afterward.
    return;
}

// Attempt to resolve the keyword as a short URL stored in the database. If found,
// $url will be the destination (long URL) for this short code.
if( $url = yourls_get_keyword_longurl( $keyword ) ) {
    // Perform the actual redirect. This helper typically takes care of:
    // - Choosing the correct HTTP status code (eg, 301/302 based on settings)
    // - Emitting proper headers (Location, Cache-Control)
    // - Logging the click, updating stats, and firing plugin hooks
    yourls_redirect_shorturl( $url, $keyword );
    return;
}

// If we reach this point, either the keyword is explicitly reserved (blocked), or it
// simply does not exist in the database. Delegate to the central unknown keyword
// handler, which preserves the previous default behavior (302 to YOURLS_SITE) and
// keeps related hooks in one place.
yourls_handle_unknown_keyword( $keyword, 'go' );
