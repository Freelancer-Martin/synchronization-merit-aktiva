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
 * Loeb tellimuselt plugina kassavälja väärtuse.
 *
 * Blocks checkout salvestab lisaväljad grupi eesliitega: kontaktiväljad "_wc_other/<id>",
 * aadressiväljad "_wc_billing/<id>". Toetame ka vanu võtmeid (ilma eesliiteta ja myplugin/).
 *
 * @param WC_Order $order
 * @param string   $field Välja lühinimi: is_company, company_name, registration_number, kmkr_number
 * @return string
 */
function merit_aktiva_get_order_field( $order, $field ) {
    foreach ( array( 'merit-aktiva/', 'myplugin/' ) as $prefix ) {
        foreach ( array( '_wc_other/', '_wc_billing/', '' ) as $group ) {
            $value = $order->get_meta( $group . $prefix . $field, true );
            if ( $value !== '' && $value !== null && $value !== false ) {
                return is_string($value) ? trim($value) : (string) $value;
            }
        }
    }
    return '';
}

/**
 * Kas tellimus on ettevõtte tellimus ("Olen ettevõte" linnuke või täidetud WooCommerce'i ettevõtte väli).
 * "Ettevõtte nimi" väli üksi ei tee tellimust ettevõtteks — see võib jääda alles, kui linnuke võeti ära.
 */
function merit_aktiva_order_is_company( $order ) {
    return merit_aktiva_get_order_field( $order, 'is_company' ) === '1'
        || trim( (string) $order->get_billing_company() ) !== '';
}

/**
 * Ettevõtte nimi: plugina väli "Ettevõtte nimi", varuvariandina WooCommerce'i billing_company.
 */
function merit_aktiva_get_company_name( $order ) {
    $name = merit_aktiva_get_order_field( $order, 'company_name' );
    if ( $name === '' ) {
        $name = (string) $order->get_billing_company();
    }
    return trim( preg_replace( '/\s+/u', ' ', $name ) );
}

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

    // 2. Ettevõtte nimi — kuvatakse ainult kui "Olen ettevõte" on märgitud (kohustuslikkus kontrollitakse allpool)
    woocommerce_register_additional_checkout_field(
        array(
            'id'         => 'merit-aktiva/company_name',
            'label'      => __( 'Ettevõtte nimi', 'synchronization-merit-aktiva' ),
            'type'       => 'text',
            'location'   => 'address',
            'required'   => false,
            'attributes' => array(
                'autocomplete' => 'organization',
            ),
            'sanitize_callback' => function( $value ) {
                return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $value ) ) );
            },
        )
    );

    // 3. Registrikood — kuvatakse ainult kui "Olen ettevõte" on märgitud
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

    // 4. KMKR number (käibemaksukohustuslase number) — kuvatakse ainult ettevõttele
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

// "Olen ettevõte" märgitud → ettevõtte nimi ja registrikood on kohustuslikud.
// Store API on selleks hetkeks lisaväljad tellimusele salvestanud; RouteException kuvatakse kassas veana.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( $order, $request ) {
    if ( merit_aktiva_get_order_field( $order, 'is_company' ) !== '1' ) {
        return;
    }
    $missing = array();
    if ( merit_aktiva_get_company_name( $order ) === '' ) {
        $missing[] = __( 'Ettevõtte nimi', 'synchronization-merit-aktiva' );
    }
    if ( merit_aktiva_get_order_field( $order, 'registration_number' ) === '' ) {
        $missing[] = __( 'Registrikood', 'synchronization-merit-aktiva' );
    }
    if ( $missing && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'merit_aktiva_company_fields_missing',
            sprintf(
                /* translators: %s: puuduvate väljade nimekiri */
                __( 'Ettevõttena tellimiseks täida: %s.', 'synchronization-merit-aktiva' ),
                implode( ', ', $missing )
            ),
            400
        );
    }
}, 10, 2 );

// Admin tellimuse vaates kuva ettevõtte nimi, registrikood ja KMKR
add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) ) return;

    try {
        $fields_controller = Automattic\WooCommerce\Blocks\Package::container()
            ->get( Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
    } catch ( Exception $e ) {
        return;
    }

    $is_company   = merit_aktiva_get_order_field( $order, 'is_company' ) === '1';
    $company_name = merit_aktiva_get_order_field( $order, 'company_name' );
    $reg_no       = merit_aktiva_get_order_field( $order, 'registration_number' );
    $kmkr         = merit_aktiva_get_order_field( $order, 'kmkr_number' );

    if ( $is_company ) {
        echo '<p><strong>' . esc_html__('Ettevõte:', 'synchronization-merit-aktiva') . '</strong> ' . esc_html__('Jah', 'synchronization-merit-aktiva') . '</p>';
    }
    if ( $company_name ) {
        echo '<p><strong>' . esc_html__('Ettevõtte nimi:', 'synchronization-merit-aktiva') . '</strong> ' . esc_html($company_name) . '</p>';
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
            $('.wc-block-components-address-form__merit-aktiva-company_name').toggle(checked);
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
