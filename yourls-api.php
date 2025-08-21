<?php
/*
 * YOURLS API — Public/Programmatic entry point
 *
 * This script processes API requests sent to YOURLS. It wires standard actions
 * (shortening, stats, version, etc.) and also allows plugins to register custom
 * API endpoints via the hook/filter system.
 *
 * Translation note: This file is intentionally NOT translation-ready. API
 * messages are meant to be programmatically parsed/tested, so English defaults
 * ensure predictable output for clients.
 */

// Signal to core/plugins that we're in the API execution context (not front-end
// redirect nor admin UI). Some behaviors may depend on this constant.
define( 'YOURLS_API', true );
// Bootstrap YOURLS core: loads configuration, connects to DB, initializes
// plugin system and helper functions used below.
require_once( dirname( __FILE__ ) . '/includes/load-yourls.php' );
// Depending on YOURLS configuration, API calls may require authentication
// (eg, signature, username/password). This helper enforces it when needed.
yourls_maybe_require_auth();

// Determine requested API action from query/body parameters. Using $_REQUEST
// allows GET and POST clients. Null when missing.
$action = ( isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : null );

// Give plugins a chance to run code on every API call (eg, logging, rate
// limiting, metrics) regardless of the specific action requested.
yourls_do_action( 'api', $action );

// Define standard API actions exposed by YOURLS core. Each entry maps an action
// name to a callback that must return an array payload suitable for output.
$api_actions = array(
	'shorturl'  => 'yourls_api_action_shorturl',
	'stats'     => 'yourls_api_action_stats',
	'db-stats'  => 'yourls_api_action_db_stats',
	'url-stats' => 'yourls_api_action_url_stats',
	'expand'    => 'yourls_api_action_expand',
	'version'   => 'yourls_api_action_version',
);
// Allow plugins to add/modify/remove available API actions.
$api_actions = yourls_apply_filter( 'api_actions', $api_actions );

// Register API actions so they can be invoked via the filter system. Each
// action gets its own tag like 'api_action_stats'. Priority 99 ensures core
// defaults run late enough to be overridden by plugins when desired.
foreach( (array) $api_actions as $_action => $_callback ) {
	yourls_add_filter( 'api_action_' . $_action, $_callback, 99 );		
}

// Execute the requested API method. If a matching action was registered, the
// associated callback should return an array describing the response.
$return = yourls_apply_filter( 'api_action_' . $action, false );
if ( false === $return ) {
	// If no callback handled the action (unknown/missing), build a generic error
	// response with a 400-like error code and a stable message string.
	$return = array(
		'errorCode' => '400',
		'message'   => 'Unknown or missing "action" parameter',
		'simple'    => 'Unknown or missing "action" parameter',
	);
}

// JSONP support: clients may specify a callback function name using 'callback'
// (preferred) or legacy 'jsonp'. When present, output will be wrapped to allow
// cross-domain script inclusion.
if( isset( $_REQUEST['callback'] ) )
	$return['callback'] = $_REQUEST['callback'];
elseif ( isset( $_REQUEST['jsonp'] ) )
	$return['callback'] = $_REQUEST['jsonp'];

// Select output format. Defaults to 'xml' for historical reasons. Other common
// values: 'json', 'jsonp', 'simple'.
// This determines the format of the API response.
$format = ( isset( $_REQUEST['format'] ) ? $_REQUEST['format'] : 'xml' );

// Serialize and emit the API response according to the requested format.
// Handles headers and output body. For JSONP, uses $return['callback'] if set.
// This function generates the API response in the requested format.
yourls_api_output( $format, $return );

// Terminate immediately after output to prevent any further processing.
// This ensures that the API response is sent and no additional code is executed.
die();