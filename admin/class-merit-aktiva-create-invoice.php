<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Genereerib kehtiva eesti pangaliidu 7-3-1 viitenumbri tellimuse ID-st.
 * Merit API nõuab RefNo väljal alati kehtivat viitenumbrit.
 */
function merit_aktiva_make_refno( $order_id ) {
    $prefix  = preg_replace( '/[^0-9]/', '', get_option('refno_prefix_field', '') );
    $base    = $prefix . intval($order_id);
    $digits  = array_reverse( str_split($base) );
    $weights = [7, 3, 1];
    $sum     = 0;
    foreach ( $digits as $i => $d ) {
        $sum += (int) $d * $weights[ $i % 3 ];
    }
    return $base . ( (10 - ($sum % 10)) % 10 );
}

/**
 * Vastutab WooCommerce tellimuste saatmise eest Merit Aktiva süsteemi.
 *
 * Arve saadetakse kolmel viisil:
 *  1. Automaatselt, kui tellimuse staatus muutub seadistatud staatuseks (woocommerce_order_status_changed)
 *  2. Käsitsi AJAX kaudu ühe tellimuse kaupa (admin panel)
 *  3. Bulk sünk kõigile saatmata tellimustele (Merit AktivaAPI_Filter_Invocies kaudu)
 */
class Merit_AktivaAPI_Create_Invoice {

    private $api_url = 'https://aktiva.merit.ee/api/v2/sendinvoice';
    private $api_id;
    private $api_secret;
    private $tax_field;
    private $payment_deadline;
    private $refno_prefix;
    private $invoice_prefix;
    private $default_unit;
    private $income_account;
    private $contact_person;
    private $invoice_header_comment;
    private $payment_method_map;

    // UI hookid registreeritakse ainult üks kord — klass instantseeritakse mitu korda
    // (global + cron run_auto_sync + AJAX), iga uus instants ei tohi hookke uuesti lisada.
    private static $ui_hooks_registered = false;

    // Pärast seda katsetuste arvu loobub cron arve saatmisest (vigane tellimus/toode)
    const MAX_RETRY_ATTEMPTS = 3;

    public function __construct() {
        add_action('wp_ajax_sync_invoice',          array($this, 'ajax_create_invoice'));
        add_action('wp_ajax_merit_force_resend',     array($this, 'ajax_force_resend'));
        add_action('wp_ajax_merit_download_pdf',     array($this, 'ajax_download_pdf'));
        add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 3);

        if ( !self::$ui_hooks_registered ) {
            self::$ui_hooks_registered = true;

            add_action('add_meta_boxes', array($this, 'register_order_meta_box'));
            add_action('admin_footer',   array($this, 'maybe_render_order_script'));

            // Tellimuste nimekirja veerg — mõlemad HPOS ja legacy
            add_filter('manage_edit-shop_order_columns',                  array($this, 'add_merit_column'));
            add_filter('manage_woocommerce_page_wc-orders_columns',       array($this, 'add_merit_column'));
            add_action('manage_shop_order_posts_custom_column',           array($this, 'render_merit_column_legacy'), 10, 2);
            add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_merit_column_hpos'),   10, 2);

            // Tellimuste filter — mõlemad HPOS ja legacy
            add_action('restrict_manage_posts',                               array($this, 'add_merit_filter_legacy'));
            add_action('woocommerce_order_list_table_restrict_manage_orders', array($this, 'add_merit_filter_hpos'));
            add_filter('parse_query',                                         array($this, 'apply_merit_filter_legacy'));
            add_filter('woocommerce_order_query_args',                        array($this, 'apply_merit_filter_hpos'));

            // Bulk action — mõlemad HPOS ja legacy
            add_filter('bulk_actions-edit-shop_order',                              array($this, 'add_bulk_action'));
            add_filter('bulk_actions-woocommerce_page_wc-orders',                   array($this, 'add_bulk_action'));
            add_filter('handle_bulk_actions-edit-shop_order',                       array($this, 'handle_bulk_action'), 10, 3);
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders',            array($this, 'handle_bulk_action'), 10, 3);
            add_action('admin_notices',                                             array($this, 'show_bulk_action_notice'));

            // Kliendi kinnituskirjale arve märkus
            add_action('woocommerce_email_after_order_table', array($this, 'add_invoice_email_note'), 20, 4);
        }

        $this->api_id           = get_option('apikey_text_field');
        $this->api_secret       = get_option('apisecret_text_field');
        $this->tax_field        = get_option('tax_select_field');
        $this->payment_deadline = get_option('payment_dead_line');
        $this->refno_prefix           = get_option('refno_prefix_field', '');
        $this->invoice_prefix         = get_option('invoice_prefix_field', '');
        $this->default_unit           = get_option('default_unit_field', 'tk');
        $this->income_account         = get_option('income_account_field', '');
        $this->contact_person         = get_option('contact_person_field', '');
        $this->invoice_header_comment = get_option('invoice_header_comment_field', '');
        $this->payment_method_map     = get_option('payment_method_mapping', array());
    }

    /**
     * Registreerib meta box tellimuse vaatesse.
     * Lisatakse mõlemale ekraanile: legacy (shop_order) ja HPOS (wc-orders).
     */
    public function register_order_meta_box() {
        foreach ( array('shop_order', 'woocommerce_page_wc-orders') as $screen ) {
            add_meta_box(
                'merit_aktiva_invoice',
                'Merit Aktiva',
                array($this, 'render_order_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Renderdab meta box sisu tellimuse vaates.
     * HPOS edastab WC_Order objekti, legacy edastab WP_Post — käsitleme mõlemat.
     */
    public function render_order_meta_box( $post_or_order ) {
        $order        = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order($post_or_order->ID);
        $sent_at      = $order->get_meta('_merit_invoice_sent_at');
        $credit_at    = $order->get_meta('_merit_credit_invoice_sent_at');
        $sih_id       = $order->get_meta('_merit_invoice_sih_id');
        $nonce        = wp_create_nonce('merit_invoice_nonce');
        ?>
        <div id="merit-metabox-wrap">
            <?php if ( $sent_at ): ?>
                <p style="color:#2e7d32;margin:0 0 6px;">
                    &#10003; Arve saadetud: <strong><?php echo esc_html(date('d.m.Y H:i', strtotime($sent_at))); ?></strong>
                </p>
            <?php else: ?>
                <p style="color:#666;margin:0 0 6px;">Arvet pole saadetud.</p>
            <?php endif; ?>
            <?php if ( $credit_at ): ?>
                <p style="color:#666;margin:0 0 6px;font-size:12px;">
                    &#8617; Krediitarve: <strong><?php echo esc_html(date('d.m.Y H:i', strtotime($credit_at))); ?></strong>
                </p>
            <?php endif; ?>
            <button type="button" class="button button-primary" style="width:100%;"
                    id="merit-send-invoice-btn"
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php echo $sent_at ? 'Saada uuesti' : 'Saada arve Merit Aktivasse'; ?>
            </button>
            <?php if ( $sent_at && $sih_id ): ?>
            <button type="button" class="button" style="width:100%;margin-top:4px;"
                    id="merit-pdf-btn"
                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Laadi alla Merit PDF
            </button>
            <?php endif; ?>
            <div id="merit-invoice-result" style="margin-top:8px;font-size:13px;"></div>
        </div>
        <?php
    }

    /**
     * Lisab JS ainult tellimuse vaate lehele, mitte kõigile admin lehtedele.
     */
    public function maybe_render_order_script() {
        $screen = get_current_screen();
        if ( !$screen ) return;
        if ( $screen->id !== 'shop_order' && $screen->base !== 'woocommerce_page_wc-orders' ) return;
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#merit-send-invoice-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#merit-invoice-result');
                $btn.prop('disabled', true).text('Saadan...');
                $result.html('');

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data: {
                        action:   'sync_invoice',
                        nonce:    $btn.data('nonce'),
                        order_id: $btn.data('order-id')
                    },
                    success: function(r) {
                        if (r.success) {
                            $result.html('<span style="color:#2e7d32;">&#10003; Arve saadetud edukalt.</span>');
                            $btn.text('Saada uuesti').prop('disabled', false);
                            $('#merit-metabox-wrap p:first').replaceWith(
                                '<p style="color:#2e7d32;margin:0 0 8px;">&#10003; Arve saadetud: <strong>just nüüd</strong></p>'
                            );
                        } else {
                            $result.html('<span style="color:#d63638;">&#10007; ' + (r.data.message || r.data) + '</span>');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        $result.html('<span style="color:#d63638;">&#10007; Ühenduse viga.</span>');
                        $btn.prop('disabled', false);
                    }
                });
            });

            $('#merit-pdf-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#merit-invoice-result');
                $btn.prop('disabled', true).text('Laadin...');
                $.ajax({
                    url: ajaxurl, method: 'POST',
                    data: { action: 'merit_download_pdf', nonce: $btn.data('nonce'), order_id: $btn.data('order-id') },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Laadi alla Merit PDF');
                        if (r.success) {
                            var link = document.createElement('a');
                            link.href = 'data:application/pdf;base64,' + r.data.pdf;
                            link.download = r.data.filename;
                            link.click();
                        } else {
                            $result.html('<span style="color:#d63638;">&#10007; ' + r.data + '</span>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Laadi alla Merit PDF');
                        $result.html('<span style="color:#d63638;">&#10007; Ühenduse viga.</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Lisab "Merit" veeru WooCommerce tellimuste nimekirja.
     */
    public function add_merit_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[$key] = $label;
            if ( $key === 'order_status' ) {
                $new['merit_aktiva'] = 'Merit';
            }
        }
        return $new;
    }

    /**
     * Renderdab Merit veeru sisu legacy (post-põhine) tellimuste nimekirjas.
     */
    public function render_merit_column_legacy( $column, $post_id ) {
        if ( $column !== 'merit_aktiva' ) return;
        $order   = wc_get_order($post_id);
        $sent_at = $order ? $order->get_meta('_merit_invoice_sent_at') : false;
        $this->render_merit_column_badge($sent_at);
    }

    /**
     * Renderdab Merit veeru sisu HPOS tellimuste nimekirjas.
     */
    public function render_merit_column_hpos( $column, $order ) {
        if ( $column !== 'merit_aktiva' ) return;
        $sent_at = $order->get_meta('_merit_invoice_sent_at');
        $this->render_merit_column_badge($sent_at);
    }

    private function render_merit_column_badge( $sent_at ) {
        if ( $sent_at ) {
            $label = date('d.m.Y', strtotime($sent_at));
            echo '<span title="Saadetud: ' . esc_attr($label) . '" style="color:#2e7d32;font-weight:600;" aria-label="Arve saadetud">&#10003;</span>';
        } else {
            echo '<span title="Arvet pole saadetud" style="color:#999;" aria-label="Arvet pole saadetud">&ndash;</span>';
        }
    }

    /**
     * Lisab "Merit Aktiva" filtri dropdown-i legacy tellimuste nimekirja.
     */
    public function add_merit_filter_legacy( $post_type ) {
        if ( $post_type !== 'shop_order' ) return;
        $this->render_merit_filter_dropdown();
    }

    /**
     * Lisab "Merit Aktiva" filtri dropdown-i HPOS tellimuste nimekirja.
     */
    public function add_merit_filter_hpos() {
        $this->render_merit_filter_dropdown();
    }

    private function render_merit_filter_dropdown() {
        $current = isset($_GET['merit_filter']) ? sanitize_key($_GET['merit_filter']) : '';
        echo '<select name="merit_filter">';
        echo '<option value="">Merit Aktiva: kõik</option>';
        echo '<option value="sent"     ' . selected($current, 'sent',     false) . '>Saadetud</option>';
        echo '<option value="not_sent" ' . selected($current, 'not_sent', false) . '>Saatmata</option>';
        echo '</select>';
    }

    /**
     * Rakendab filtri legacy WP_Query-le.
     */
    public function apply_merit_filter_legacy( $query ) {
        global $pagenow;
        if ( $pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order' ) return;
        if ( empty($_GET['merit_filter']) ) return;

        $filter = sanitize_key($_GET['merit_filter']);
        $meta   = array('key' => '_merit_invoice_sent_at', 'compare' => $filter === 'sent' ? 'EXISTS' : 'NOT EXISTS');

        $existing = (array) $query->get('meta_query');
        $existing[] = $meta;
        $query->set('meta_query', $existing);
    }

    /**
     * Rakendab filtri HPOS wc_get_orders() päringule.
     */
    public function apply_merit_filter_hpos( $args ) {
        if ( empty($_GET['merit_filter']) ) return $args;

        $filter = sanitize_key($_GET['merit_filter']);
        $args['meta_query'][] = array(
            'key'     => '_merit_invoice_sent_at',
            'compare' => $filter === 'sent' ? 'EXISTS' : 'NOT EXISTS',
        );
        return $args;
    }

    /**
     * Käivitub automaatselt, kui tellimuse staatus muutub.
     * Saadab arve ainult siis, kui uus staatus vastab plugin seadistuses olevale staatusele.
     */
    public function on_order_status_changed( $order_id, $old_status, $new_status ) {
        if ( $new_status === get_option('payment_status_select_field') ) {
            $this->create_invoice($order_id);
        }
        if ( $new_status === 'refunded' ) {
            $this->create_credit_invoice($order_id);
        }
    }

    /**
     * AJAX handler ühe tellimuse käsitsisaatmiseks.
     * Nõuab nonce kontrolli ja manage_woocommerce õigust.
     */
    public function ajax_create_invoice() {
        check_ajax_referer('merit_invoice_nonce', 'nonce');
        if ( !current_user_can('manage_woocommerce') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        if ( !$order_id ) {
            wp_send_json_error('Invalid order ID', 400);
            return;
        }
        $result = $this->create_invoice($order_id);
        if ( $result['success'] ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Saadab ühe tellimuse arve Merit Aktivasse.
     *
     * Tagastab massiivi kujul ['success' => bool, 'message' => string, 'order_id' => int].
     * Eduka saadetise korral salvestatakse '_merit_invoice_sent_at' tellimuse meta-sse
     * ning lisatakse order note — see on ainus mehhanism topeltsaatmise vältimiseks.
     *
     * @param int $order_id WooCommerce tellimuse ID
     * @return array
     */
    public function create_invoice( $order_id ) {
        if ( empty($this->api_id) || empty($this->api_secret) ) {
            return ['success' => false, 'message' => 'Merit Aktiva API ID või API Key on seadistamata (plugin seadistused)'];
        }

        $order = wc_get_order($order_id);
        if ( !$order ) {
            return ['success' => false, 'message' => 'Tellimust ei leitud: ' . $order_id];
        }

        // Peata korduvkatsed pärast MAX_RETRY_ATTEMPTS ebaõnnestumist
        $failed_attempts = (int) $order->get_meta('_merit_invoice_failed_attempts');
        if ( $failed_attempts >= self::MAX_RETRY_ATTEMPTS ) {
            return ['success' => false, 'message' => 'Arve saatmine peatatud pärast ' . self::MAX_RETRY_ATTEMPTS . ' ebaõnnestunud katset. Paranda tellimus ja saada käsitsi.', 'order_id' => $order_id];
        }

        $timestamp = gmdate('YmdHis');

        // Blocks checkout: merit-aktiva/is_company checkbox; fallback billing_company klassikalisele kassale
        $is_company   = (bool) $order->get_meta('merit-aktiva/is_company') || !empty($order->get_billing_company());
        $company_name = $is_company ? $order->get_billing_company() : '';
        $customer_name = $is_company && !empty($company_name)
            ? $company_name
            : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        // Registrikood ja KMKR loetakse Blocks checkout lisaväljadest (woocommerce-extra-fields.php)
        $reg_no = $order->get_meta('merit-aktiva/registration_number') ?: null;
        $vat_no = $order->get_meta('merit-aktiva/kmkr_number') ?: 0;

        if ( $is_company ) {
            $json_payload = array(
                "Customer" => array(
                    "Name"            => $customer_name,
                    "RegNo"           => $reg_no,
                    "NotTDCustomer"   => false,
                    "VatRegNo"        => $vat_no,
                    "CurrencyCode"    => $order->get_currency(),
                    "PaymentDeadLine" => $this->payment_deadline,
                    "OverDueCharge"   => 0,
                    "RefNoBase"       => 1,
                    "Address"         => $order->get_billing_address_1(),
                    "CountryCode"     => $order->get_billing_country(),
                    "County"          => $order->get_shipping_state(),
                    "City"            => $order->get_billing_city(),
                    "PostalCode"      => $order->get_shipping_postcode(),
                    "PhoneNo"         => $order->get_billing_phone(),
                    "Email"           => $order->get_billing_email(),
                ),
                "DocDate"       => $order->get_date_created()->date("YmdGis"),
                "DueDate"       => date("YmdGis", strtotime("+" . intval($this->payment_deadline) . " days")),
                "InvoiceNo"     => $this->invoice_prefix . $order->get_id(),
                "RefNo"         => merit_aktiva_make_refno( $order->get_id() ),
                "ContactName"   => $this->contact_person,
                "PaymentMethod" => $this->get_payment_method_code($order),
                "HComment"      => $this->invoice_header_comment,
                "InvoiceRow" => $this->create_invoice_items_array($order),
                "TotalAmount" => $order->get_total(),
                "TaxAmount"  => [["TaxId" => $this->tax_field, "Amount" => 0]],
            );
        } else {
            $json_payload = array(
                "Customer" => array(
                    "Name"            => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    "RegNo"           => null,
                    "NotTDCustomer"   => true,
                    "VatRegNo"        => 0,
                    "CurrencyCode"    => $order->get_currency(),
                    "PaymentDeadLine" => $this->payment_deadline,
                    "OverDueCharge"   => 0,
                    "RefNoBase"       => 1,
                    "Address"         => $order->get_billing_address_1(),
                    "CountryCode"     => $order->get_billing_country(),
                    "County"          => $order->get_shipping_state(),
                    "City"            => $order->get_billing_city(),
                    "PostalCode"      => $order->get_shipping_postcode(),
                    "PhoneNo"         => $order->get_billing_phone(),
                    "Email"           => $order->get_billing_email(),
                ),
                "DocDate"       => $order->get_date_created()->date("YmdGis"),
                "DueDate"       => date("YmdGis", strtotime("+" . intval($this->payment_deadline) . " days")),
                "InvoiceNo"     => $this->invoice_prefix . $order->get_id(),
                "RefNo"         => merit_aktiva_make_refno( $order->get_id() ),
                "ContactName"   => $this->contact_person,
                "PaymentMethod" => $this->get_payment_method_code($order),
                "HComment"      => $this->invoice_header_comment,
                "InvoiceRow" => $this->create_invoice_items_array($order),
                "TotalAmount" => $order->get_total(),
                "TaxAmount"  => [["TaxId" => $this->tax_field, "Amount" => 0]],
            );
        }

        // Merit Aktiva API v2 allkiri: HMAC-SHA256(ApiId + timestamp + httpBody, ApiKey)
        // Tulemus peab olema Base64 kodeeritud ja URL-enkodeeritud, kuna Base64 sisaldab '+' ja '='.
        // Vt: https://api.merit.ee/connecting-robots/reference-manual/authentication/
        $json_body   = json_encode($json_payload);
        $signature   = base64_encode(hash_hmac('sha256', $this->api_id . $timestamp . $json_body, $this->api_secret, true));
        $request_url = $this->api_url . '?ApiId=' . $this->api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($request_url, array(
            'body'    => $json_body,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 15,
        ));

        if ( is_wp_error($response) ) {
            $message = $response->get_error_message();
            error_log('Merit Aktiva arve viga tellimusel #' . $order_id . ': ' . $message);
            $order->update_meta_data('_merit_invoice_failed_attempts', $failed_attempts + 1);
            $order->add_order_note('Merit Aktiva: arve saatmine ebaõnnestus — ' . $message);
            $order->save();
            $this->log_sync_event($order_id, false, $message);
            return ['success' => false, 'message' => $message, 'order_id' => $order_id];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        if ( $status_code < 200 || $status_code >= 300 ) {
            $message = $this->translate_api_error($body, $status_code);
            error_log('Merit Aktiva arve viga tellimusel #' . $order_id . ': ' . $message);
            $order->update_meta_data('_merit_invoice_failed_attempts', $failed_attempts + 1);
            $order->add_order_note('Merit Aktiva: arve saatmine ebaõnnestus — ' . $message);
            $order->save();
            $this->log_sync_event($order_id, false, $message);
            return ['success' => false, 'message' => $message, 'order_id' => $order_id];
        }

        $result_data = json_decode($body, true);
        if ( !empty($result_data['SIHId']) ) {
            $order->update_meta_data('_merit_invoice_sih_id', $result_data['SIHId']);
        }
        $order->update_meta_data('_merit_invoice_sent_at', current_time('mysql'));
        $order->delete_meta_data('_merit_invoice_failed_attempts');
        $order->add_order_note('Merit Aktiva: arve edukalt saadetud ' . current_time('d.m.Y H:i') . '.');
        $order->save();
        $this->log_sync_event($order_id, true, 'Arve saadetud');

        return ['success' => true, 'message' => 'Arve saadetud', 'order_id' => $order_id];
    }

    /**
     * Ehitab Merit Aktiva API formaadis toodete ja transpordi massiivi.
     * Transpordirida lisatakse arvele ainult siis, kui tarnekulud on > 0.
     *
     * @param WC_Order $order
     * @return array
     */
    public function create_invoice_items_array( $order ) {
        $payload_arrays = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            // Merit API lubab kaubakoodi max 20 tähemärki — pikemad SKU-d lõigatakse
            $sku     = $product ? substr($product->get_sku(), 0, 20) : '';

            $row = array(
                "Item" => array(
                    "Code"        => $sku,
                    "Description" => $item->get_name(),
                    "Type"        => 1,
                    "UOMName"     => $this->default_unit ?: 'tk',
                ),
                "Quantity"       => $item->get_quantity(),
                "Price"          => $product->get_price(),
                "DiscountPct"    => 0,
                "DiscountAmount" => 0,
                "TaxId"          => $this->tax_field,
                "LocationCode"   => "1",
            );
            if ( !empty($this->income_account) ) {
                $row['AccountCode'] = $this->income_account;
            }
            $payload_arrays[] = $row;
        }

        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = floatval($shipping_item->get_total());

            // Tasuta transport jäetakse arvelt välja
            if ( $shipping_total <= 0 ) continue;

            $shipping_row = array(
                'Item' => array(
                    'Code'        => 'TRANSPORT',
                    'Description' => $shipping_item->get_name(),
                    'Type'        => 1,
                    'UOMName'     => $this->default_unit ?: 'tk',
                ),
                'Quantity'       => 1,
                'Price'          => $shipping_total,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $this->tax_field,
                'LocationCode'   => '1',
            );
            if ( !empty($this->income_account) ) {
                $shipping_row['AccountCode'] = $this->income_account;
            }
            $payload_arrays[] = $shipping_row;
        }

        // Allahindlus — lisatakse eraldi reana kui kasutati kupongi
        $discount_total = (float) $order->get_discount_total();
        if ( $discount_total > 0 ) {
            $coupon_codes = implode(', ', $order->get_coupon_codes());
            $discount_row = array(
                'Item' => array(
                    'Code'        => 'DISCOUNT',
                    'Description' => 'Allahindlus' . ($coupon_codes ? ': ' . $coupon_codes : ''),
                    'Type'        => 1,
                    'UOMName'     => $this->default_unit ?: 'tk',
                ),
                'Quantity'       => 1,
                'Price'          => -$discount_total,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $this->tax_field,
                'LocationCode'   => '1',
            );
            if ( !empty($this->income_account) ) {
                $discount_row['AccountCode'] = $this->income_account;
            }
            $payload_arrays[] = $discount_row;
        }

        return $payload_arrays;
    }

    /**
     * AJAX handler sundmiseks — kustutab vana meta ja saadab uuesti.
     * Kasutatakse arve-võrdluse vaates kui arve puudub Merit Aktivast.
     */
    public function ajax_force_resend() {
        check_ajax_referer('merit_invoice_nonce', 'nonce');
        if ( !current_user_can('manage_woocommerce') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        if ( !$order_id ) {
            wp_send_json_error('Vigane tellimuse ID');
            return;
        }
        $order = wc_get_order($order_id);
        if ( !$order ) {
            wp_send_json_error('Tellimust ei leitud');
            return;
        }
        // Kustuta eelmine saatmise info et lubada uuesti saatmine
        $order->delete_meta_data('_merit_invoice_sent_at');
        $order->delete_meta_data('_merit_invoice_failed_attempts');
        $order->save();

        $result = $this->create_invoice($order_id);
        if ( $result['success'] ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Saadab krediitarve Merit Aktivasse kui tellimus tagastatakse täielikult.
     * Käivitub automaatselt staatuse 'refunded' korral või käsitsi meta box nupust.
     */
    public function create_credit_invoice( $order_id ) {
        if ( empty($this->api_id) || empty($this->api_secret) ) {
            return ['success' => false, 'message' => 'API võtmed puuduvad'];
        }
        $order = wc_get_order($order_id);
        if ( !$order ) return ['success' => false, 'message' => 'Tellimust ei leitud'];

        if ( !$order->get_meta('_merit_invoice_sent_at') ) {
            return ['success' => false, 'message' => 'Originaaalarvet pole Merit Aktivasse saadetud'];
        }
        if ( $order->get_meta('_merit_credit_invoice_sent_at') ) {
            return ['success' => false, 'message' => 'Krediitarve on juba saadetud'];
        }

        $timestamp    = gmdate('YmdHis');
        $is_company   = (bool) $order->get_meta('merit-aktiva/is_company') || !empty($order->get_billing_company());
        $company_name = $is_company ? $order->get_billing_company() : '';
        $customer_name = $is_company && !empty($company_name)
            ? $company_name
            : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        $json_payload = array(
            "Customer" => array(
                "Name"            => $customer_name,
                "RegNo"           => $order->get_meta('merit-aktiva/registration_number') ?: null,
                "NotTDCustomer"   => !$is_company,
                "VatRegNo"        => $order->get_meta('merit-aktiva/kmkr_number') ?: 0,
                "CurrencyCode"    => $order->get_currency(),
                "PaymentDeadLine" => $this->payment_deadline,
                "OverDueCharge"   => 0,
                "RefNoBase"       => 1,
                "Address"         => $order->get_billing_address_1(),
                "CountryCode"     => $order->get_billing_country(),
                "County"          => $order->get_shipping_state(),
                "City"            => $order->get_billing_city(),
                "PostalCode"      => $order->get_shipping_postcode(),
                "PhoneNo"         => $order->get_billing_phone(),
                "Email"           => $order->get_billing_email(),
            ),
            "DocDate"       => date("YmdGis"),
            "DueDate"       => date("YmdGis"),
            "InvoiceNo"     => 'K' . $this->invoice_prefix . $order->get_id(),
            "RefNo"         => merit_aktiva_make_refno( $order->get_id() ),
            "ContactName"   => $this->contact_person,
            "HComment"      => 'Krediitarve tellimusele #' . $order->get_id(),
            "InvoiceRow"    => $this->create_credit_items_array($order),
            "TotalAmount"   => -$order->get_total(),
            "TaxAmount"     => [["TaxId" => $this->tax_field, "Amount" => 0]],
        );

        $json_body   = json_encode($json_payload);
        $signature   = base64_encode(hash_hmac('sha256', $this->api_id . $timestamp . $json_body, $this->api_secret, true));
        $request_url = $this->api_url . '?ApiId=' . $this->api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($request_url, array(
            'body'    => $json_body,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 15,
        ));

        if ( is_wp_error($response) ) {
            $message = $response->get_error_message();
            $order->add_order_note('Merit Aktiva: krediitarve saatmine ebaõnnestus — ' . $message);
            $order->save();
            return ['success' => false, 'message' => $message];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);

        if ( $status_code < 200 || $status_code >= 300 ) {
            $message = $this->translate_api_error($body, $status_code);
            $order->add_order_note('Merit Aktiva: krediitarve saatmine ebaõnnestus — ' . $message);
            $order->save();
            return ['success' => false, 'message' => $message];
        }

        $order->update_meta_data('_merit_credit_invoice_sent_at', current_time('mysql'));
        $order->add_order_note('Merit Aktiva: krediitarve edukalt saadetud ' . current_time('d.m.Y H:i') . '.');
        $order->save();
        $this->log_sync_event($order_id, true, 'Krediitarve saadetud');
        return ['success' => true, 'message' => 'Krediitarve saadetud'];
    }

    /**
     * Ehitab krediitarve read — samad tooted aga negatiivsete kogustega.
     */
    private function create_credit_items_array( $order ) {
        $rows = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku     = $product ? substr($product->get_sku(), 0, 20) : '';
            $row     = array(
                'Item' => array(
                    'Code'        => $sku,
                    'Description' => $item->get_name(),
                    'Type'        => 1,
                    'UOMName'     => $this->default_unit ?: 'tk',
                ),
                'Quantity'       => -$item->get_quantity(),
                'Price'          => $product ? (float) $product->get_price() : 0,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $this->tax_field,
                'LocationCode'   => '1',
            );
            if ( !empty($this->income_account) ) $row['AccountCode'] = $this->income_account;
            $rows[] = $row;
        }
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $shipping_total = floatval($shipping_item->get_total());
            if ( $shipping_total <= 0 ) continue;
            $row = array(
                'Item' => array(
                    'Code'        => 'TRANSPORT',
                    'Description' => $shipping_item->get_name(),
                    'Type'        => 1,
                    'UOMName'     => $this->default_unit ?: 'tk',
                ),
                'Quantity'       => -1,
                'Price'          => $shipping_total,
                'DiscountPct'    => 0,
                'DiscountAmount' => 0,
                'TaxId'          => $this->tax_field,
                'LocationCode'   => '1',
            );
            if ( !empty($this->income_account) ) $row['AccountCode'] = $this->income_account;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * AJAX handler Merit arve PDF allalaadimiseks.
     * Nõuab SIHId metavälja mis salvestatakse arve saatmisel.
     */
    public function ajax_download_pdf() {
        check_ajax_referer('merit_invoice_nonce', 'nonce');
        if ( !current_user_can('manage_woocommerce') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;
        if ( !$order ) { wp_send_json_error('Tellimust ei leitud'); return; }

        $sih_id = $order->get_meta('_merit_invoice_sih_id');
        if ( !$sih_id ) {
            wp_send_json_error('Merit arve ID puudub — saada arve esmalt Merit Aktivasse');
            return;
        }

        $api_id     = get_option('apikey_text_field');
        $api_secret = get_option('apisecret_text_field');
        $timestamp  = gmdate('YmdHis');
        $payload    = json_encode(['Id' => $sih_id]);
        $signature  = base64_encode(hash_hmac('sha256', $api_id . $timestamp . $payload, $api_secret, true));
        $url        = 'https://aktiva.merit.ee/api/v2/getpdf?ApiId=' . $api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($url, array(
            'body'    => $payload,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 20,
        ));

        if ( is_wp_error($response) ) {
            wp_send_json_error($response->get_error_message());
            return;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);

        if ( $http_code !== 200 ) {
            $err = json_decode($body, true);
            wp_send_json_error('Merit API viga: ' . ($err['Message'] ?? 'HTTP ' . $http_code));
            return;
        }

        wp_send_json_success(array(
            'pdf'      => base64_encode($body),
            'filename' => 'arve-' . $order_id . '.pdf',
        ));
    }

    /**
     * Tõlgib WooCommerce makseviisi Merit Aktiva koodiks.
     * Kasutab seadistustes defineeritud kaardistust, fallback on makseviisi pealkiri.
     */
    private function get_payment_method_code( $order ) {
        $wc_method = $order->get_payment_method();
        if ( !empty($this->payment_method_map[$wc_method]) ) {
            return $this->payment_method_map[$wc_method];
        }
        return $order->get_payment_method_title() ?: '';
    }

    /**
     * Tõlgib Merit API tuntud veakoodid inimloetavateks eesti keelseteks sõnumiteks.
     */
    private function translate_api_error( $body, $status_code ) {
        $known_errors = array(
            'liiga pikk kaubakoodiks' => 'Toote SKU on liiga pikk (max 20 tähemärki) — lühenda toodet WooCommerce-is.',
            'invalidSignature'        => 'API allkiri on vale — kontrolli API ID ja API Key seadistustes.',
            'ApiId is required'       => 'API ID puudub — kontrolli plugin seadistusi.',
            'not found'               => 'Ressurssi ei leitud Merit Aktivas.',
        );
        foreach ( $known_errors as $pattern => $human ) {
            if ( stripos($body, $pattern) !== false ) {
                return $human . ' [HTTP ' . $status_code . ']';
            }
        }
        return 'HTTP ' . $status_code . ': ' . $body;
    }

    /**
     * Salvestab sünkrooni sündmuse logisse (viimased 50 kirjet wp_options-is).
     */
    private function log_sync_event( $order_id, $success, $message ) {
        $log = get_option('merit_aktiva_sync_log', array());
        array_unshift($log, array(
            't' => current_time('mysql'),
            'o' => $order_id,
            's' => (bool) $success,
            'm' => substr($message, 0, 200),
        ));
        update_option('merit_aktiva_sync_log', array_slice($log, 0, 50), false);
    }

    /**
     * Lisab "Saada arve Merit Aktivasse" bulk action tellimuste nimekirja.
     */
    public function add_bulk_action( $actions ) {
        $actions['merit_send_invoice'] = 'Saada arve Merit Aktivasse';
        return $actions;
    }

    /**
     * Töötleb bulk action valiku — saadab valitud tellimuste arved.
     */
    public function handle_bulk_action( $redirect_url, $action, $order_ids ) {
        if ( $action !== 'merit_send_invoice' ) return $redirect_url;

        $synced = 0;
        $errors = 0;
        foreach ( $order_ids as $order_id ) {
            $result = $this->create_invoice(absint($order_id));
            $result['success'] ? $synced++ : $errors++;
        }

        return add_query_arg(array(
            'merit_bulk_synced' => $synced,
            'merit_bulk_errors' => $errors,
        ), $redirect_url);
    }

    /**
     * Kuvab bulk action tulemuse admin notice-na.
     */
    public function show_bulk_action_notice() {
        if ( !isset($_GET['merit_bulk_synced']) ) return;
        $synced = intval($_GET['merit_bulk_synced']);
        $errors = intval($_GET['merit_bulk_errors']);
        $type   = $errors > 0 ? 'warning' : 'success';
        echo '<div class="notice notice-' . $type . ' is-dismissible"><p>';
        echo 'Merit Aktiva: <strong>' . $synced . ' arvet</strong> saadetud';
        if ( $errors > 0 ) echo ', <strong>' . $errors . '</strong> ebaõnnestus';
        echo '.</p></div>';
    }

    /**
     * Lisab kliendi tellimuse kinnituskirjale märkuse arve kohta.
     * Ei lisata admin teavituskirjadele ega tagastusekirjadele.
     */
    public function add_invoice_email_note( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin ) return;
        if ( !in_array($email->id, array('customer_processing_order', 'customer_completed_order'), true) ) return;

        if ( $plain_text ) {
            echo "\nArve väljastatakse eraldi Merit Aktiva kaudu.\n";
        } else {
            echo '<p style="color:#555;font-size:13px;margin-top:16px;">Arve väljastatakse eraldi Merit Aktiva kaudu.</p>';
        }
    }
}

new Merit_AktivaAPI_Create_Invoice();
