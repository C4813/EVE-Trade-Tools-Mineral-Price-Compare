<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Variables here are local to an ob_start() include, not true globals.
if ( ! defined( 'ABSPATH' ) ) exit;
/** @var int   $user_id    */
/** @var array $characters */
/** @var int   $count      */

// Pre-pass: check for any expired tokens before rendering.
$has_expired = false;
if ( ! empty( $characters ) ) {
    foreach ( $characters as $char_id => $data ) {
        if ( ! ETTMC_OAuth::get_valid_access_token( $user_id, (string) $char_id ) ) {
            $has_expired = true;
            break;
        }
    }
}
?>
<div class="ettmc-profile-wrapper"><div class="ett-characters">
    <h3>Authenticated Characters (<?php echo esc_html( (string) $count ); ?>)</h3>

    <?php if ( $has_expired ) : ?>
        <p class="ett-token-warning">&#9888; Token expired &mdash; check characters below and reconnect.</p>
    <?php endif; ?>

    <div class="ett-connect-wrap">
        <?php echo ETTMC_OAuth::connect_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>

    <?php if ( empty( $characters ) ) : ?>
        <p>No authenticated characters yet. Use &quot;Connect with EVE Online&quot; to add one.</p>
    <?php else : ?>
        <?php foreach ( $characters as $char_id => $data ) :
            $char_id        = (string) $char_id;
            $name           = ( is_array( $data ) && ! empty( $data['name'] ) ) ? (string) $data['name'] : 'Character ' . $char_id;
            $disconnect_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=ettmc_disconnect_char&char_id=' . rawurlencode( $char_id ) ),
                'ettmc_disconnect_' . $char_id
            );
            $token          = ETTMC_OAuth::get_valid_access_token( $user_id, $char_id );
            $char_data      = $token ? ETTMC_OAuth::get_character_data( $char_id ) : [];
            $has_skills     = ! empty( $char_data['skill_levels'] );
        ?>
        <div class="ett-character">
            <div class="ett-character-header">
                <strong><?php echo esc_html( $name ); ?></strong>
                <a href="<?php echo esc_url( $disconnect_url ); ?>" class="ett-disconnect">Disconnect</a>
            </div>

            <div class="ett-character-body">
                <?php if ( ! $token ) : ?>
                    <p>Token expired. Please reconnect.</p>
                <?php elseif ( ! $has_skills ) : ?>
                    <p>Could not load skill data. Please reconnect this character.</p>
                <?php else :
                    $skills     = $char_data['skill_levels'];
                    $accounting = (int) ( $skills[16622] ?? 0 );
                    $br         = (int) ( $skills[3446]  ?? 0 );
                ?>
                <?php
                    // Sales tax is skill-based only (Accounting), same for all hubs.
                    $any_hub_fees = ETTMC_OAuth::calc_fees( $char_data, 'Jita' );
                    $sales_tax_pct = number_format( $any_hub_fees['sales_tax'] * 100, 1 );
                ?>
                <div class="ettmc-char-cols">
                    <div class="ettmc-char-col-left">
                        <table class="ett-table">
                            <thead>
                                <tr>
                                    <th>Skill</th>
                                    <th>Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Accounting</td>      <td><?php echo absint( $accounting ); ?></td></tr>
                                <tr><td>Broker Relations</td><td><?php echo absint( $br );         ?></td></tr>
                            </tbody>
                        </table>
                        <p class="ettmc-sales-tax">Sales Tax: <strong><?php echo esc_html( $sales_tax_pct ); ?>%</strong></p>
                    </div>

                    <div class="ettmc-char-col-right">
                        <table class="ett-table">
                            <thead>
                                <tr>
                                    <th>Hub</th>
                                    <th>Broker Fee</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( ETTMC_ESI::hubs() as $hub ) :
                                    $fees = ETTMC_OAuth::calc_fees( $char_data, $hub['name'] );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $hub['name'] ); ?></td>
                                    <td><?php echo number_format( $fees['broker_fee'] * 100, 2 ); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div><!-- .ett-character-body -->
        </div><!-- .ett-character -->
        <?php endforeach; ?>
    <?php endif; ?>
</div><!-- .ett-characters --></div><!-- .ettmc-profile-wrapper -->
