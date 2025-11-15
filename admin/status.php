<?php
// Simple admin status page for YOURLS

define( 'YOURLS_ADMIN', true );

require_once dirname( __DIR__ ) . '/includes/load-yourls.php';

yourls_maybe_require_auth();

yourls_html_head( 'status', yourls__( 'System status' ) );
yourls_html_logo();
yourls_html_menu();

yourls_do_action( 'admin_page_status_top' );

$checks = [];

// DB connectivity
$db_ok   = true;
$db_info = '';
try {
    $db = yourls_get_db();
    $db->fetchValue( 'SELECT 1' );
    $db_info = yourls__( 'OK' );
} catch ( Exception $e ) {
    $db_ok   = false;
    $db_info = yourls__( 'Error' ) . ': ' . $e->getMessage();
}

$checks[] = [
    'label' => yourls__( 'Database connection' ),
    'ok'    => $db_ok,
    'info'  => $db_info,
];

// Installed state
$installed = yourls_is_installed();
$checks[] = [
    'label' => yourls__( 'YOURLS installed' ),
    'ok'    => $installed,
    'info'  => $installed ? yourls__( 'Yes' ) : yourls__( 'No (install required)' ),
];

// Upgrade state
$upgrade_needed = false;
if ( $installed ) {
    $upgrade_needed = yourls_upgrade_is_needed();
}
$checks[] = [
    'label' => yourls__( 'Database schema up to date' ),
    'ok'    => !$upgrade_needed,
    'info'  => $upgrade_needed ? yourls__( 'Upgrade required' ) : yourls__( 'Up to date' ),
];

// Summary of options state (best-effort)
$options_info = '';
try {
    $options = new \YOURLS\Database\Options( yourls_get_db() );
    // We cannot distinguish all cases without side effects, but a quick call
    // indicates if the options table can be read at all.
    $ok = $options->get_all_options();
    $options_info = $ok ? yourls__( 'Loaded successfully' ) : yourls__( 'Missing or empty options table' );
} catch ( Exception $e ) {
    $options_info = yourls__( 'Error' ) . ': ' . $e->getMessage();
}

$checks[] = [
    'label' => yourls__( 'Options table' ),
    'ok'    => strpos( $options_info, 'Error' ) === false,
    'info'  => $options_info,
];

?>
<div class="wrap">
    <h2><?php echo yourls__( 'System status' ); ?></h2>
    <table class="tblSorter" cellpadding="0" cellspacing="1" border="0">
        <thead>
            <tr>
                <th><?php echo yourls__( 'Check' ); ?></th>
                <th><?php echo yourls__( 'Status' ); ?></th>
                <th><?php echo yourls__( 'Details' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $checks as $check ) : ?>
            <tr>
                <td><?php echo yourls_esc_html( $check['label'] ); ?></td>
                <td style="font-weight:bold; color:<?php echo $check['ok'] ? '#008000' : '#cc0000'; ?>;">
                    <?php echo $check['ok'] ? yourls__( 'OK' ) : yourls__( 'Problem' ); ?>
                </td>
                <td><?php echo yourls_esc_html( $check['info'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php

yourls_do_action( 'admin_page_status_bottom' );

yourls_html_footer();
