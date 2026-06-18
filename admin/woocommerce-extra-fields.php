<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Migratsioon: myplugin/ -> merit-aktiva/ väljaprefiksid (jookseb üks kord)
add_action('init', function() {
    if ( get_option('merit_aktiva_field_prefix_migrated') ) return;
    global $wpdb;
    $map = array(
        'myplugin/is_company'          => 'merit-aktiva/is_company',
        'myplugin/registration_number' => 'merit-aktiva/registration_number',
        'myplugin/kmkr_number'         => 'merit-aktiva/kmkr_number',
    );
    foreach ( $map as $old => $new ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wc_orders_meta SET meta_key = %s WHERE meta_key = %s",
            $new, $old
        ) );
    }
    update_option('merit_aktiva_field_prefix_migrated', '1.2.0', false);
});

/**
 * Kontrollib eesti äriregistri koodi kehtivust.
 * Algoritm: kaalud 1-7, summa mod 11; kui jääk=10, kaalud 3-9; kui jälle 10, kontrollnumber=0.
 *
 * @param string $value Registrikood (8 numbrit)
 * @return bool
 */
function merit_aktiva_validate_reg_no( $value ) {
    $value = preg_replace('/[^0-9]/', '', $value);
    if ( strlen($value) !== 8 ) return false;
    $digits = str_split($value);
    $w1     = [1, 2, 3, 4, 5, 6, 7];
    $sum    = 0;
    for ( $i = 0; $i < 7; $i++ ) $sum += (int) $digits[$i] * $w1[$i];
    $r = $sum % 11;
    if ( $r < 10 ) return $r === (int) $digits[7];
    $w2  = [3, 4, 5, 6, 7, 8, 9];
    $sum = 0;
    for ( $i = 0; $i < 7; $i++ ) $sum += (int) $digits[$i] * $w2[$i];
    $r        = $sum % 11;
    $expected = $r < 10 ? $r : 0;
    return $expected === (int) $digits[7];
}

add_action( 'woocommerce_init', function () {
    if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
        return;
    }

    // 1. "Olen ettevõte" checkbox — contact sektsioonis, nähtav kohe
    woocommerce_register_additional_checkout_field(
        array(
            'id'       => 'merit-aktiva/is_company',
            'type'     => 'checkbox',
            'label'    => __( 'Olen ettevõte', 'synchronization-merit-aktiva' ),
            'location' => 'contact',
            'required' => false,
        )
    );

    // 2. Registrikood — kuvatakse ainult kui "Olen ettevõte" on märgitud
    woocommerce_register_additional_checkout_field(
        array(
            'id'         => 'merit-aktiva/registration_number',
            'label'      => __( 'Registrikood', 'synchronization-merit-aktiva' ),
            'type'       => 'text',
            'location'   => 'address',
            'required'   => false,
            'attributes' => array(
                'autocomplete' => 'registration-number',
            ),
            'sanitize_callback' => function( $value ) {
                return preg_replace( '/[^0-9]/', '', trim($value) );
            },
            'validate_callback' => function( $value ) {
                if ( '' === $value ) return;
                if ( ! merit_aktiva_validate_reg_no($value) ) {
                    return new WP_Error(
                        'invalid_registration_number',
                        __( 'Registrikood ei ole kehtiv eesti äriregistri kood.', 'synchronization-merit-aktiva' )
                    );
                }
            },
        )
    );

    // 3. KMKR number (käibemaksukohustuslase number) — kuvatakse ainult ettevõttele
    woocommerce_register_additional_checkout_field(
        array(
            'id'         => 'merit-aktiva/kmkr_number',
            'label'      => __( 'KMKR number', 'synchronization-merit-aktiva' ),
            'type'       => 'text',
            'location'   => 'address',
            'required'   => false,
            'attributes' => array(
                'autocomplete' => 'vat-number',
            ),
            'sanitize_callback' => function( $value ) {
                return strtoupper( trim($value) );
            },
        )
    );
});

// Admin tellimuse vaates kuva registrikood ja KMKR
add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) ) return;

    try {
        $fields_controller = Automattic\WooCommerce\Blocks\Package::container()
            ->get( Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
    } catch ( Exception $e ) {
        return;
    }

    $is_company  = $fields_controller->get_field_from_object('merit-aktiva/is_company',          $order, 'billing');
    $reg_no      = $fields_controller->get_field_from_object('merit-aktiva/registration_number',  $order, 'billing');
    $kmkr        = $fields_controller->get_field_from_object('merit-aktiva/kmkr_number',          $order, 'billing');

    if ( $is_company ) {
        echo '<p><strong>' . esc_html__('Ettevõte:', 'synchronization-merit-aktiva') . '</strong> ' . esc_html__('Jah', 'synchronization-merit-aktiva') . '</p>';
    }
    if ( $reg_no ) {
        echo '<p><strong>' . esc_html__('Registrikood:', 'synchronization-merit-aktiva') . '</strong> ' . esc_html($reg_no) . '</p>';
    }
    if ( $kmkr ) {
        echo '<p><strong>' . esc_html__('KMKR number:', 'synchronization-merit-aktiva') . '</strong> ' . esc_html($kmkr) . '</p>';
    }
});

// Peida registrikood ja KMKR väljad kuni "Olen ettevõte" on märgitud
add_action('wp_footer', function() {
    if ( ! is_checkout() ) return;
    ?>
    <script>
    jQuery(document).ready(function($) {
        function toggleCompanyFields() {
            var checked = $('#contact-merit-aktiva-is_company').is(':checked');
            $('.wc-block-components-address-form__merit-aktiva-registration_number').toggle(checked);
            $('.wc-block-components-address-form__merit-aktiva-kmkr_number').toggle(checked);
        }
        toggleCompanyFields();
        $(document).on('change', '#contact-merit-aktiva-is_company', toggleCompanyFields);
        // Blocks checkout re-renderdab — jälgi DOM muutusi
        var observer = new MutationObserver(toggleCompanyFields);
        observer.observe(document.body, { childList: true, subtree: true });
    });
    </script>
    <?php
});
