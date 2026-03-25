<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Variables here are local to an ob_start() include, not true globals.
if ( ! defined( 'ABSPATH' ) ) exit;

$prices    = ETTMC_ESI::load_all_prices();
$hubs      = ETTMC_ESI::hubs();
$minerals  = ETTMC_ESI::minerals();
$buy_rows  = ETTMC_Render::build_table_rows( 'buy',  $prices );
$sell_rows = ETTMC_Render::build_table_rows( 'sell', $prices );

// Allowed HTML for internally-built trend badges.
$ettmc_trend_kses = [ 'div' => [ 'class' => [] ], 'span' => [ 'class' => [] ] ];
?>
<div id="eve-mineral-compare-tables">

    <?php if ( ! is_user_logged_in() ) : ?>
    <div class="emc-fees-notice">
        No character authenticated &mdash; trade calculations use default fees (3% broker fee, 8% sales tax).
        Connect a character using the link above to apply your actual skill-based fees.
    </div>
    <?php elseif ( ! get_user_meta( get_current_user_id(), ETTMC_OAuth::META_KEY, true ) ) : ?>
    <div class="emc-fees-notice">
        You&rsquo;re logged in but have no EVE character connected &mdash; default fees are used (3% broker, 8% sales tax).
        Add a character using the link above to use your actual rates.
    </div>
    <?php endif; ?>

    <!-- Buy Values -->
    <h3 class="emc-section-title">Buy Values</h3>
    <div class="emc-buysell-note"><em>Highest Buy Value &mdash; Trend is most recent value minus the average highest buy over the past 30 days</em></div>
    <table id="eve-mineral-compare-table-buy" class="emc-main-table">
        <caption class="screen-reader-text">Best buy prices per hub for EVE minerals</caption>
        <thead>
            <tr>
                <th>Mineral</th>
                <?php foreach ( $hubs as $hub ) : ?><th><?php echo esc_html( $hub['name'] ); ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $buy_rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['mineral'] ); ?></td>
                <?php foreach ( $row['cells'] as $cell ) : ?>
                <td class="<?php echo esc_attr( $cell['class'] ); ?>">
                    <div class="emc-price-val"><?php echo esc_html( $cell['value'] ); ?></div>
                    <?php if ( ! empty( $cell['trend_html'] ) ) echo wp_kses( $cell['trend_html'], $ettmc_trend_kses ); ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Sell Values -->
    <h3 class="emc-section-title">Sell Values</h3>
    <div class="emc-buysell-note"><em>Lowest Sell Value &mdash; Trend is most recent value minus the average lowest sell over the past 30 days</em></div>
    <table id="eve-mineral-compare-table-sell" class="emc-main-table">
        <caption class="screen-reader-text">Best sell prices per hub for EVE minerals</caption>
        <thead>
            <tr>
                <th>Mineral</th>
                <?php foreach ( $hubs as $hub ) : ?><th><?php echo esc_html( $hub['name'] ); ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $sell_rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['mineral'] ); ?></td>
                <?php foreach ( $row['cells'] as $cell ) : ?>
                <td class="<?php echo esc_attr( $cell['class'] ); ?>">
                    <div class="emc-price-val"><?php echo esc_html( $cell['value'] ); ?></div>
                    <?php if ( ! empty( $cell['trend_html'] ) ) echo wp_kses( $cell['trend_html'], $ettmc_trend_kses ); ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Extended Trade Opportunities -->
    <h3 class="emc-section-title">Extended Trade Opportunities</h3>

    <div id="emc-hub-filters-best" class="emc-hub-row">
        <span class="emc-hub-label">Show Hubs:</span>
        <?php foreach ( $hubs as $i => $hub ) :
            $hid = 'emc-hub-' . sanitize_html_class( $hub['name'] ) . '-' . $i; ?>
        <label class="emc-hub-item" for="<?php echo esc_attr( $hid ); ?>">
            <span class="emc-hub-name"><?php echo esc_html( $hub['name'] ); ?></span>
            <input id="<?php echo esc_attr( $hid ); ?>" type="checkbox" class="emc-hub-toggle"
                   value="<?php echo esc_attr( $hub['name'] ); ?>" checked>
        </label>
        <?php endforeach; ?>

        <div id="emc-limit-60k-container" class="emc-limit">
            <div class="emc-limit-label">
                <input type="checkbox" id="emc-limit-60k">
                <span>Limit to 6,000,000 units (60,000 m&#xB3;)</span>
            </div>
            <div class="emc-limit-note"><em>Buy from Buy, Sell to Sell defaults to 100,000 units</em></div>
        </div>
    </div>

    <table id="eve-mc-extended" class="emc-main-table">
        <caption class="screen-reader-text">Extended trade opportunities with adjustable legs and margin filter</caption>
        <thead>
            <tr>
                <th>Mineral</th>
                <th>
                    <div class="emc-th-label">Buy From</div>
                    <div class="emc-th-control">
                        <select id="buy-from-select-ext" class="emc-select">
                            <option value="buy">Buy Orders</option>
                            <option value="sell">Sell Orders</option>
                        </select>
                    </div>
                </th>
                <th>
                    <div class="emc-th-label">Sell To</div>
                    <div class="emc-th-control">
                        <select id="sell-to-select-ext" class="emc-select">
                            <option value="sell">Sell Orders</option>
                            <option value="buy">Buy Orders</option>
                        </select>
                    </div>
                </th>
                <th>Qty</th>
                <th>Profit</th>
                <th>
                    <div class="emc-th-label">Minimum Margin %</div>
                    <div class="emc-th-control">
                        <input type="text" id="emc-min-margin" class="emc-decimal-only" value="5" step="0.1" inputmode="decimal">
                    </div>
                </th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <!-- No-Undock Trading -->
    <h3 class="emc-section-title">No-Undock Trading</h3>
    <div class="emc-buysell-note"><em>Always Buying from Buy, Selling to Sell within the same hub. (Including brokerage fees and sales tax)</em></div>
    <table id="emc-no-undock" class="emc-main-table">
        <thead>
            <tr>
                <th>Mineral</th>
                <?php foreach ( $hubs as $hub ) : ?><th><?php echo esc_html( $hub['name'] ); ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

</div><!-- #eve-mineral-compare-tables -->
