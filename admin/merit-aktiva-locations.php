<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Merit Aktiva laod (LocationCode).
 *
 * Lao valimise järjekord arverea jaoks:
 *  1. toote enda ladu (Toote andmed → Laoseis → "Merit Aktiva ladu"); variatsioonil põhitoote ladu
 *  2. tootekategooria ladu (Tooted → Kategooriad → "Merit Aktiva ladu"), sh ülemkategooriad
 *  3. plugina seadete vaikeladu
 *
 * Ladude nimekiri laaditakse Meritist (v2/getlocations) ja hoitakse valikus merit_aktiva_locations_cache.
 */

const MERIT_AKTIVA_LOCATION_META = '_merit_location_code';

/**
 * Vaikeladu plugina seadetest. Seadistamata = "1" (varasem käitumine), tühi = ladu ei saadeta.
 */
function merit_aktiva_default_location_code() {
    return (string) get_option( 'merit_location_code', '1' );
}

/**
 * Meritist laaditud laod: [ ['Code' => '1', 'Name' => 'Üldladu'], ... ].
 */
function merit_aktiva_get_cached_locations() {
    $cached = get_option( 'merit_aktiva_locations_cache' );
    return is_array( $cached ) ? $cached : array();
}

/**
 * Laadib laod Merit API-st ja salvestab vahemällu.
 *
 * @return array|WP_Error
 */
function merit_aktiva_fetch_locations() {
    $api_id     = get_option( 'apikey_text_field' );
    $api_secret = get_option( 'apisecret_text_field' );
    if ( ! $api_id || ! $api_secret ) {
        return new WP_Error( 'merit_locations', 'API ID või API Key on seadistamata — salvesta need enne' );
    }

    $body      = '{}';
    $timestamp = gmdate( 'YmdHis' );
    $signature = base64_encode( hash_hmac( 'sha256', $api_id . $timestamp . $body, $api_secret, true ) );
    $url       = 'https://aktiva.merit.ee/api/v2/getlocations?ApiId=' . urlencode( $api_id ) . '&timestamp=' . $timestamp . '&signature=' . urlencode( $signature );

    $response = wp_remote_post( $url, array(
        'body'    => $body,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'timeout' => 15,
    ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'merit_locations', 'HTTP ' . $code . ( $raw ? ': ' . wp_strip_all_tags( mb_substr( $raw, 0, 200 ) ) : '' ) );
    }

    $data = json_decode( $raw, true );
    if ( is_string( $data ) ) {
        $data = json_decode( $data, true );
    }
    if ( is_array( $data ) && isset( $data['Code'] ) ) {
        $data = array( $data );
    }
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'merit_locations', 'Merit API vastus oli tundmatu kujuga' );
    }

    $locations = array();
    foreach ( $data as $loc ) {
        if ( ! is_array( $loc ) || ! isset( $loc['Code'] ) || $loc['Code'] === '' ) continue;
        // Dokumentatsioonis kirjaviga "NonActine" — kontrollime mõlemat
        if ( ! empty( $loc['NonActive'] ) || ! empty( $loc['NonActine'] ) ) continue;
        $locations[] = array(
            'Code' => sanitize_text_field( (string) $loc['Code'] ),
            'Name' => sanitize_text_field( (string) ( $loc['Name'] ?? '' ) ),
        );
    }
    if ( ! $locations ) {
        return new WP_Error( 'merit_locations', 'Meritis ei leitud ühtegi aktiivset ladu' );
    }
    update_option( 'merit_aktiva_locations_cache', $locations, false );
    return $locations;
}

/**
 * Lao valikukast. Kui ladusid pole veel laaditud, kuvatakse vähemalt praegune väärtus.
 */
function merit_aktiva_location_select_html( $name, $value, $empty_label, $id = '' ) {
    $locations = merit_aktiva_get_cached_locations();
    $html  = '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="merit-location-select">';
    $html .= '<option value="">' . esc_html( $empty_label ) . '</option>';
    $found = false;
    foreach ( $locations as $loc ) {
        $sel = selected( (string) $value, $loc['Code'], false );
        if ( $sel ) $found = true;
        $label = $loc['Code'] . ( $loc['Name'] !== '' ? ' — ' . $loc['Name'] : '' );
        $html .= '<option value="' . esc_attr( $loc['Code'] ) . '" ' . $sel . '>' . esc_html( $label ) . '</option>';
    }
    if ( (string) $value !== '' && ! $found ) {
        $html .= '<option value="' . esc_attr( $value ) . '" selected="selected">' . esc_html( $value ) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Leiab tootele lao koodi: toode → põhitoode (variatsioon) → kategooria (+ ülemkategooriad) → vaikeladu.
 *
 * @param WC_Product|false|null $product
 * @return string
 */
function merit_aktiva_resolve_location_code( $product ) {
    $default = merit_aktiva_default_location_code();
    if ( ! $product ) {
        return $default;
    }

    $own = (string) $product->get_meta( MERIT_AKTIVA_LOCATION_META, true );
    if ( $own !== '' ) {
        return $own;
    }

    $product_id = $product->get_id();
    if ( $product->get_parent_id() ) {
        $parent = wc_get_product( $product->get_parent_id() );
        if ( $parent ) {
            $parent_loc = (string) $parent->get_meta( MERIT_AKTIVA_LOCATION_META, true );
            if ( $parent_loc !== '' ) {
                return $parent_loc;
            }
            $product_id = $parent->get_id();
        }
    }

    $terms = get_the_terms( $product_id, 'product_cat' );
    if ( is_array( $terms ) ) {
        // Kõige sügavam (täpsem) kategooria enne
        usort( $terms, function( $a, $b ) {
            return count( get_ancestors( $b->term_id, 'product_cat' ) ) - count( get_ancestors( $a->term_id, 'product_cat' ) );
        } );
        foreach ( $terms as $term ) {
            $chain = array_merge( array( $term->term_id ), get_ancestors( $term->term_id, 'product_cat' ) );
            foreach ( $chain as $term_id ) {
                $loc = (string) get_term_meta( $term_id, MERIT_AKTIVA_LOCATION_META, true );
                if ( $loc !== '' ) {
                    return $loc;
                }
            }
        }
    }

    return $default;
}

// AJAX: lae laod Meritist (seadete leht, toote ja kategooria vorm)
add_action( 'wp_ajax_merit_get_locations', function() {
    check_ajax_referer( 'merit_locations_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $result = merit_aktiva_fetch_locations();
    if ( is_wp_error( $result ) ) {
        error_log( 'Merit Aktiva ladude laadimine ebaõnnestus: ' . $result->get_error_message() );
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( $result );
} );

/**
 * Lubatud HTML lao valikukasti ja laadimisnupu väljundis (wp_kses).
 */
function merit_aktiva_locations_allowed_html() {
    return array(
        'select' => array( 'name' => true, 'id' => true, 'class' => true ),
        'option' => array( 'value' => true, 'selected' => true ),
        'button' => array( 'type' => true, 'class' => true, 'style' => true ),
        'span'   => array( 'class' => true, 'style' => true ),
    );
}

/**
 * Väljastab lao valikukasti (escape'itud).
 */
function merit_aktiva_the_location_select( $name, $value, $empty_label, $id = '' ) {
    echo wp_kses( merit_aktiva_location_select_html( $name, $value, $empty_label, $id ), merit_aktiva_locations_allowed_html() );
}

/**
 * Väljastab "Lae laod Meritist" nupu. Nupu skript lisatakse lehe jalusesse üks kord.
 */
function merit_aktiva_the_locations_loader() {
    echo '<button type="button" class="button button-secondary merit-load-locations" style="margin-left:8px;">' . esc_html__( 'Lae laod Meritist', 'synchronization-merit-aktiva' ) . '</button>';
    echo '<span class="merit-locations-status" style="margin-left:8px;color:#666;"></span>';
    if ( ! has_action( 'admin_footer', 'merit_aktiva_print_locations_script' ) ) {
        add_action( 'admin_footer', 'merit_aktiva_print_locations_script' );
    }
}

/**
 * Skript, mis laadib laod Meritist ja täidab kõik lehe lao valikud (.merit-location-select).
 */
function merit_aktiva_print_locations_script() {
    $nonce = wp_create_nonce( 'merit_locations_nonce' );
    ?>
    <script>
    jQuery(function($) {
        $(document).on('click', '.merit-load-locations', function() {
            var btn = $(this), status = btn.siblings('.merit-locations-status');
            btn.prop('disabled', true); status.css('color', '#666').text('Laadin...');
            $.post(ajaxurl, { action: 'merit_get_locations', nonce: <?php echo wp_json_encode( $nonce ); ?> }, function(r) {
                btn.prop('disabled', false);
                if (!r.success || !r.data || !r.data.length) {
                    status.css('color', '#b45309').text((r && typeof r.data === 'string') ? r.data : 'Ladusid ei leitud');
                    return;
                }
                $('.merit-location-select').each(function() {
                    var sel = $(this), cur = sel.val();
                    sel.find('option').not('[value=""]').remove();
                    $.each(r.data, function(i, l) {
                        sel.append($('<option>').val(l.Code).text(l.Code + (l.Name ? ' — ' + l.Name : '')));
                    });
                    sel.val(cur);
                });
                status.css('color', '#2e7d32').text('✓ ' + r.data.length + ' ladu laaditud — vali ja salvesta');
            }).fail(function() {
                btn.prop('disabled', false);
                status.css('color', '#d63638').text('Ühenduse viga');
            });
        });
    });
    </script>
    <?php
}

// ── Toote väli: Toote andmed → Laoseis ────────────────────────────────────────
add_action( 'woocommerce_product_options_inventory_product_data', function() {
    global $product_object;
    $value = $product_object ? (string) $product_object->get_meta( MERIT_AKTIVA_LOCATION_META, true ) : '';
    echo '<div class="options_group"><p class="form-field"><label for="merit_location_code">Merit Aktiva ladu</label>';
    merit_aktiva_the_location_select( 'merit_location_code', $value, '— Kategooria / vaikeladu —', 'merit_location_code' );
    merit_aktiva_the_locations_loader();
    echo '<span class="description" style="display:block;clear:both;">Ladu, kust selle toote müük Merit Aktivas maha läheb. Tühi = kategooria ladu või plugina vaikeladu.</span>';
    echo '</p></div>';
} );

add_action( 'woocommerce_admin_process_product_object', function( $product ) {
    // Nonce (woocommerce_meta_nonce) on kontrollitud WC_Admin_Meta_Boxes::save_meta_boxes() poolt enne seda hooki.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['merit_location_code'] ) ) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $value = sanitize_text_field( wp_unslash( $_POST['merit_location_code'] ) );
    if ( $value === '' ) {
        $product->delete_meta_data( MERIT_AKTIVA_LOCATION_META );
    } else {
        $product->update_meta_data( MERIT_AKTIVA_LOCATION_META, $value );
    }
} );

// ── Kategooria väli: Tooted → Kategooriad ────────────────────────────────────
add_action( 'product_cat_add_form_fields', function() {
    echo '<div class="form-field"><label for="merit_location_code">Merit Aktiva ladu</label>';
    merit_aktiva_the_location_select( 'merit_location_code', '', '— Ülemkategooria / vaikeladu —', 'merit_location_code' );
    merit_aktiva_the_locations_loader();
    echo '<p>Selle kategooria toodete ladu Merit Aktivas, kui tootel endal ladu pole määratud.</p></div>';
} );

add_action( 'product_cat_edit_form_fields', function( $term ) {
    $value = (string) get_term_meta( $term->term_id, MERIT_AKTIVA_LOCATION_META, true );
    echo '<tr class="form-field"><th scope="row"><label for="merit_location_code">Merit Aktiva ladu</label></th><td>';
    merit_aktiva_the_location_select( 'merit_location_code', $value, '— Ülemkategooria / vaikeladu —', 'merit_location_code' );
    merit_aktiva_the_locations_loader();
    echo '<p class="description">Selle kategooria toodete ladu Merit Aktivas, kui tootel endal ladu pole määratud.</p></td></tr>';
} );

$merit_aktiva_save_cat_location = function( $term_id ) {
    // Nonce (add-tag / update-tag_<id>) on kontrollitud WordPressi termini salvestamisel enne seda hooki.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! isset( $_POST['merit_location_code'] ) || ! current_user_can( 'manage_product_terms' ) ) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $value = sanitize_text_field( wp_unslash( $_POST['merit_location_code'] ) );
    if ( $value === '' ) {
        delete_term_meta( $term_id, MERIT_AKTIVA_LOCATION_META );
    } else {
        update_term_meta( $term_id, MERIT_AKTIVA_LOCATION_META, $value );
    }
};
add_action( 'created_product_cat', $merit_aktiva_save_cat_location );
add_action( 'edited_product_cat', $merit_aktiva_save_cat_location );
