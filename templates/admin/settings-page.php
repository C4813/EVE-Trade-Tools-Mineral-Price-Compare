<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Variables here are local to an ob_start() include, not true globals.
if ( ! defined( 'ABSPATH' ) ) exit;

$ph_active      = class_exists( 'ETT_ExternalDB' ) && class_exists( 'ETT_Crypto' );
$db_configured  = $ph_active && ETT_ExternalDB::is_configured();
$sso_configured = ! empty( get_option( 'ett_sso_client_id' ) ) && ! empty( get_option( 'ett_sso_client_secret' ) );
?>
<div class="ett-settings-grid">

    <!-- EVE SSO -->
    <div class="ett-card">
        <h2>EVE SSO</h2>

        <?php if ( ! $ph_active ) : ?>
            <div class="ett-statusline">
                <span class="ett-dot bad"></span>
                <span class="ett-bad">ETT Price Helper is not active.</span>
            </div>
            <p class="description">Install and activate ETT Price Helper to configure EVE SSO.</p>

        <?php elseif ( $sso_configured ) : ?>
            <div class="ett-statusline">
                <span class="ett-dot ok"></span>
                <span class="ett-ok">Client ID and Secret configured via ETT Price Helper</span>
            </div>

            <p class="description" style="margin-top:10px;">
                This plugin uses the <strong>same EVE developer app and callback URL</strong> as ETT Reprocess Trading —
                no changes to your EVE app are needed. Characters authenticated here are stored
                separately from Reprocess Trading and are only used by Mineral Compare.
            </p>

            <p class="description" style="margin-top:8px;">
                Required scopes for the EVE app:
            </p>
            <ul style="margin:4px 0 0 20px; list-style:disc;">
                <?php foreach ( explode( ' ', ETTMC_OAuth::SCOPE ) as $scope ) : ?>
                <li><code><?php echo esc_html( $scope ); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p class="description" style="margin-top:8px;">
                These are the same scopes used by ETT Reprocess Trading, so your existing EVE app
                does not need to be modified.
            </p>

        <?php else : ?>
            <div class="ett-statusline">
                <span class="ett-dot bad"></span>
                <span class="ett-bad">Not configured &mdash; set up EVE SSO in the Price Helper tab.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- External Price Database -->
    <div class="ett-card">
        <h2>External Price Database</h2>

        <?php if ( ! $ph_active ) : ?>
            <div class="ett-statusline">
                <span class="ett-dot bad"></span>
                <span class="ett-bad">ETT Price Helper is not active.</span>
            </div>
            <p class="description">Install and activate ETT Price Helper to configure the external database.</p>

        <?php elseif ( $db_configured ) : ?>
            <div class="ett-statusline">
                <span class="ett-dot" id="ettmc-db-dot"></span>
                <span id="ettmc-db-status-text" class="ett-muted">Testing&hellip;</span>
            </div>

            <p class="description" style="margin-top:10px;">
                Mineral Compare writes to the same external database configured in ETT Price Helper.
                The <code>ettmc_mineral_orders</code> table stores individual mineral order-book rows
                captured during Price Helper&rsquo;s price runs, enabling extended trade simulation.
            </p>

        <?php else : ?>
            <div class="ett-statusline">
                <span class="ett-dot bad"></span>
                <span class="ett-bad">Not configured &mdash; set up the database in the Price Helper tab.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Extended Trades Info -->
    <div class="ett-card" style="grid-column: 1 / -1;">
        <h2>Extended Trade Opportunities</h2>
        <p class="description">
            Extended trade simulation requires individual mineral order-book data captured during
            ETT Price Helper&rsquo;s price runs. Once ETT Price Helper v1.8.2+ has completed a
            price job, the <code>ettmc_mineral_orders</code> table will be populated and extended
            trade simulation will be available on the frontend.
        </p>
        <p class="description" style="margin-top:8px;">
            <strong>How it works:</strong> During each Price Helper price run, raw ESI market orders
            for the 8 mineral type IDs (Tritanium through Morphite) are captured page-by-page and
            stored as individual rows. The frontend then simulates walking the order book &mdash;
            buying from sell orders at one hub and selling into buy orders at another &mdash; to find
            the realistic profitable volume up to the point of non-profitability.
        </p>
        <p class="description" style="margin-top:8px;">
            <strong>Trend data (Buy/Sell tables):</strong> During each Price Helper history job, the
            daily <code>lowest</code> and <code>highest</code> transaction prices for minerals are
            captured and stored in <code>ettmc_mineral_trend</code>. Trend arrows show today&rsquo;s
            price vs the 30-day average and appear once a history job has completed with ETT Price
            Helper v1.8.2+.
        </p>
        <?php if ( $db_configured ) :
            $any = false;
            foreach ( ETTMC_ESI::hubs() as $hub ) {
                if ( ETTMC_ExtDB::hub_has_orders( $hub['key'] ) ) {
                    $any    = true;
                    $last   = ETTMC_ExtDB::hub_last_updated( $hub['key'] );
                    echo '<p class="description"><strong>' . esc_html( $hub['name'] ) . ':</strong> ';
                    echo $last // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        ? 'Order book populated (last updated ' . esc_html( $last ) . ').'
                        : 'No order book data yet.';
                    echo '</p>';
                }
            }
            if ( ! $any ) :
        ?>
            <p class="description"><em>No mineral order book data found yet. Run a price job in ETT Price Helper v1.8.2+ to populate.</em></p>
        <?php endif; endif; ?>
    </div>

</div><!-- .ett-settings-grid -->
