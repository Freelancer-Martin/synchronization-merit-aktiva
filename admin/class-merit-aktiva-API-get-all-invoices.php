<?php
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Laeb arveid Merit Aktiva API-st ja salvestab need lokaalses andmebaasi tabelis.
 *
 * Tabel wp_merit_invoices sisaldab viimase 3 kuu maksmata arveid.
 * Dashboard ekraanil toimub sünkroon automaatselt PHP-s ning iga 5 minuti
 * tagant AJAX kaudu (JS setInterval), et tabel oleks alati ajakohane.
 */
class Get_All_Merit_Invoices {
    private $api_url = 'https://aktiva.merit.ee/api/v2/getinvoices';
    private $api_id;
    private $api_secret;

    public function __construct() {
        add_action('wp_ajax_sync_merit_invoices', array($this, 'handle_ajax_request'));
        $this->api_id     = get_option('apikey_text_field');
        $this->api_secret = get_option('apisecret_text_field');
        $this->create_database_table();
        // API päring ja JS käivitatakse ainult dashboard ekraanil
        add_action('current_screen', array($this, 'maybe_run_on_dashboard'));
    }

    /**
     * Käivitab API päringu ja registreerib JS-i ainult dashboard ekraanil.
     * Teistel lehtedel pole arvetelaadimine vajalik ega soovitav.
     */
    public function maybe_run_on_dashboard() {
        $screen = get_current_screen();
        if (isset($screen->base) && $screen->base === 'dashboard') {
            $this->make_api_request();
            add_action('admin_footer', array($this, 'RunJavascript'));
        }
    }

    /**
     * Loob andmebaasi tabeli, kui see veel ei eksisteeri.
     * dbDelta on ohutu korduvkutsumiseks — muudab tabelit ainult vajaduse korral.
     */
    private function create_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'merit_invoices';

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_no VARCHAR(50) NOT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            sih_id BIGINT(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY invoice_no (invoice_no)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * AJAX handler käsitsi sünkrooniks.
     * Nõuab manage_options õigust (sait admin, mitte ainult poe admin).
     */
    public function handle_ajax_request() {
        check_ajax_referer('merit_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $this->make_api_request();
        wp_send_json_success('Invoices synchronized successfully');
    }

    /**
     * Pärib Merit Aktivast viimase 3 kuu maksmata arved ja uuendab kohalikku tabelit.
     */
    private function make_api_request() {
        $timestamp = gmdate('YmdHis');

        $today = new DateTime();
        $threeMonthsAgo = (new DateTime())->modify('-3 months');

        $payload  = array(
            'Periodstart' => intval($threeMonthsAgo->format('Ymd')),
            'PeriodEnd'   => intval($today->format('Ymd')),
            'UnPaid'      => true,
        );

        // Allkiri: HMAC-SHA256(ApiId + timestamp + body, ApiKey), Base64 kodeeritud
        // Merit Aktiva API v2 allkiri: HMAC-SHA256(ApiId + timestamp + body, ApiKey), Base64 kodeeritud
        $json_body   = json_encode($payload);
        $signature   = base64_encode(hash_hmac('sha256', $this->api_id . $timestamp . $json_body, $this->api_secret, true));
        $request_url = $this->api_url . '?ApiId=' . $this->api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($request_url, array(
            'body'    => $json_body,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching invoices: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $decode_json = json_decode($body);
        
        if (!empty($decode_json)) {
            $this->update_invoices_in_database($decode_json);
        }
    }

    /**
     * Uuendab andmebaasi tabelit Merit Aktivast tulnud arvetega.
     *
     * Lisaks Merit arvetele sünkroniseeritakse ka WooCommerce tellimused tabelisse,
     * et saaks hiljem kuvada tellimuse olekut arve vaates.
     *
     * @param array $invoices Merit API vastusest json_decode() tulemus
     */
    private function update_invoices_in_database($invoices) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'merit_invoices';

        foreach ($invoices as $invoice) {
            $invoice_no = $invoice->InvoiceNo;
            $total_amount = $invoice->TotalAmount;

            $existing_invoice = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE invoice_no = %s",
                $invoice_no
            ));

            if ($existing_invoice) {
                if ($existing_invoice->total_amount != $total_amount || $existing_invoice->sih_id != $invoice->SIHId) {
                    $wpdb->update(
                        $table_name,
                        array('total_amount' => $total_amount, 'sih_id' => $invoice_no),
                        array('invoice_no' => $invoice_no),
                        array('%f', '%d'),
                        array('%s')
                    );
                }
            } else {
                $wpdb->insert(
                    $table_name,
                    array('invoice_no' => $invoice_no, 'total_amount' => $total_amount, 'sih_id' => $invoice_no),
                    array('%s', '%f', '%d')
                );
            }
        }

        if (class_exists('WooCommerce')) {
            $order_query = new WC_Order_Query(array('limit' => -1));
            $woocommerce_orders = $order_query->get_orders();

            foreach ($woocommerce_orders as $order) {
                $order_id = $order->get_id();
                $order_total = $order->get_total();
                $sih_id = $order->get_meta('SIHId');

                $existing_order = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_name WHERE invoice_no = %s",
                    $order_id
                ));

                if ($existing_order) {
                    if ($existing_order->total_amount != $order_total || $existing_order->sih_id != $sih_id) {
                        $wpdb->update(
                            $table_name,
                            array('total_amount' => $order_total, 'sih_id' => $sih_id),
                            array('invoice_no' => $order_id),
                            array('%f', '%d'),
                            array('%s')
                        );
                    }
                } else {
                    $wpdb->insert(
                        $table_name,
                        array('invoice_no' => $order_id, 'total_amount' => $order_total, 'sih_id' => $sih_id),
                        array('%s', '%f', '%d')
                    );
                }
            }
        }
    }

    /**
     * Lisab dashboard leheküljele JS-i, mis teeb esialgse sünkrooni ja kordab seda iga 5 min.
     * Kutsutakse admin_footer hook-ist, seepärast on admin_url() juba kättesaadav.
     */
    public function RunJavascript()
    {
        $script  = '<script>';
        $script .= "jQuery(document).ready(function($) {";
        $script .= "  var ajaxurl = '" . esc_js(admin_url('admin-ajax.php')) . "';";
        $script .= "  function runInvoiceSync() {";
        $script .= "    jQuery.ajax({";
        $script .= "      url: ajaxurl, method: 'POST',";
        $script .= "      data: { action: 'sync_merit_invoices', nonce: '" . wp_create_nonce('merit_sync_nonce') . "' },";
        $script .= "      error: function() { console.error('Merit Aktiva invoice sync failed.'); }";
        $script .= "    });";
        $script .= "  }";
        $script .= "  runInvoiceSync();";
        $script .= "  setInterval(runInvoiceSync, 5 * 60 * 1000);";
        $script .= "});";
        $script .= '</script>';

        echo $script;
    }
}

// Initialize the class
new Get_All_Merit_Invoices();

