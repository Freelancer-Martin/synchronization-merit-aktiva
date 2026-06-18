<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Haldab automaatset ja käsitsi sünkroniseerimist Merit Aktivaga.
 *
 * Pakub kolm sünkroniseerimismehhanismi:
 *  1. WP cron (iga 5 min) — run_auto_sync() käivitub taustal
 *  2. "Sünkrooni kõik" nupp seadistuste lehel — ajax_sync_all_orders()
 *  3. Cron teavitab admini admin notice kaudu, kui midagi muutus
 */
class Merit_AktivaAPI_Filter_Invocies {

    private $payment_status;

    public function __construct() {
        $this->payment_status = get_option('payment_status_select_field');
        add_action('wp_ajax_merit_sync_all_orders', array($this, 'ajax_sync_all_orders'));
        add_action('admin_footer', array($this, 'maybe_render_sync_script'));
        add_action('merit_aktiva_auto_sync', array($this, 'run_auto_sync'));
        add_action('admin_notices', array($this, 'show_cron_notice'));
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
    }

    /**
     * Lisab JS ainult plugin seadistuste lehele, mitte kõikidele admin lehtedele.
     */
    public function maybe_render_sync_script() {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'toplevel_page_merit-aktiva-options' ) {
            $this->render_sync_script();
        }
    }

    /**
     * WP croni poolt iga 5 minuti tagant käivitatav automaatne sünkroon.
     *
     * Käib läbi kõik seadistatud staatusega tellimused ja saadab arve neile,
     * millel puudub '_merit_invoice_sent_at' meta. See meta on ainuke topeltsaatmise
     * filter — SQL meta_query ei tööta HPOS-iga, seepärast kontrollitakse PHP-s.
     *
     * Kui vähemalt üks tellimus töödeldi, salvestatakse tulemus transient-sse,
     * mida show_cron_notice() kuvab järgmisel admin lehelaadimiselt.
     */
    public function run_auto_sync() {
        if ( !class_exists('WooCommerce') ) return;

        // Tagasilöök — 3 täielikku ebaõnnestumist järjest peatab sünkrooni 1h
        if ( get_transient('merit_aktiva_backoff') ) {
            error_log('Merit Aktiva cron: tagasilöök aktiivne, sünk vahele jäetud.');
            return;
        }

        $orders      = wc_get_orders(array('status' => $this->payment_status, 'limit' => -1));
        $invoice_api = new Merit_AktivaAPI_Create_Invoice();
        $synced      = 0;
        $errors      = [];

        foreach ( $orders as $order ) {
            if ( $order->get_meta('_merit_invoice_sent_at') ) continue;
            $result = $invoice_api->create_invoice($order->get_id());
            if ( $result['success'] ) {
                $synced++;
            } else {
                $errors[] = 'Tellimus #' . $order->get_id() . ': ' . $result['message'];
            }
        }

        // Salvesta tulemus olenemata tulemusest — näidatakse seadistuste lehel
        update_option('merit_aktiva_last_sync', array(
            'time'   => current_time('mysql'),
            'synced' => $synced,
            'errors' => count($errors),
        ));

        error_log(sprintf(
            'Merit Aktiva cron sünk lõpetatud: %d arvet saadetud, %d viga. Järgmine: %s',
            $synced,
            count($errors),
            date('H:i:s', (int) wp_next_scheduled('merit_aktiva_auto_sync'))
        ));

        if ( !empty($errors) ) {
            foreach ($errors as $err) {
                error_log('Merit Aktiva cron viga: ' . $err);
            }
            wp_mail(
                get_option('admin_email'),
                'Merit Aktiva: ' . count($errors) . ' arve saatmine ebaõnnestus',
                "Automaatne sünkroniseerimine ebaõnnestus järgmistel tellimustel:\n\n"
                    . implode("\n", $errors)
                    . "\n\nKontrolli seadistusi: " . admin_url('admin.php?page=merit-aktiva-options')
            );
        }

        // Jälgi järjestikuseid täielikke ebaõnnestumisi — aktiveeri tagasilöök 1h
        if ( $synced === 0 && !empty($errors) ) {
            $consecutive = (int) get_option('merit_aktiva_consecutive_failures', 0) + 1;
            update_option('merit_aktiva_consecutive_failures', $consecutive);
            if ( $consecutive >= 3 ) {
                set_transient('merit_aktiva_backoff', true, HOUR_IN_SECONDS);
                update_option('merit_aktiva_consecutive_failures', 0);
                error_log('Merit Aktiva: 3 järjestikust täielikku ebaõnnestumist — tagasilöök 1 tunniks.');
                wp_mail(
                    get_option('admin_email'),
                    'Merit Aktiva: sünkroniseerimine peatatud 1 tunniks',
                    "3 järjestikust täielikku sünkroniseerimise ebaõnnestumist.\nSünkroniseerimine on peatatud 1 tunniks.\n\nKontrolli seadistusi: " . admin_url('admin.php?page=merit-aktiva-options')
                );
            }
        } else {
            update_option('merit_aktiva_consecutive_failures', 0);
        }

        // Teavitust ei salvestata, kui kõik tellimused olid juba saadetud
        if ( $synced > 0 || !empty($errors) ) {
            set_transient('merit_aktiva_cron_notice', array(
                'synced' => $synced,
                'errors' => $errors,
                'time'   => current_time('d.m.Y H:i'),
            ), HOUR_IN_SECONDS);
        }
    }

    /**
     * Kuvab croni sünkroonimise tulemuse admin notice-na.
     * Transient kustutatakse kohe pärast kuvamist, et teadet ei korrataks.
     */
    public function show_cron_notice() {
        $notice = get_transient('merit_aktiva_cron_notice');
        if ( !$notice ) return;
        delete_transient('merit_aktiva_cron_notice');

        $type = empty($notice['errors']) ? 'success' : 'warning';
        $msg  = 'Merit Aktiva automaatne sünk (' . $notice['time'] . '): ' . $notice['synced'] . ' arvet saadetud.';
        if ( !empty($notice['errors']) ) {
            $msg .= '<br>' . implode('<br>', array_map('esc_html', $notice['errors']));
        }
        echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . $msg . '</p></div>';
    }

    /**
     * AJAX handler "Sünkrooni kõik" nupu jaoks.
     * Erinevalt run_auto_sync()-ist saadetakse KÕIK sellel staatusel olevad
     * tellimused, sealhulgas need, mis on juba saadetud (kasutaja tahab uuesti saata).
     */
    public function ajax_sync_all_orders() {
        check_ajax_referer('merit_sync_all_nonce', 'nonce');
        if ( !current_user_can('manage_woocommerce') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        if ( !class_exists('WooCommerce') ) {
            wp_send_json_error('WooCommerce ei ole aktiivne');
            return;
        }

        $orders = wc_get_orders(array(
            'status' => $this->payment_status,
            'limit'  => -1,
        ));

        $invoice_api = new Merit_AktivaAPI_Create_Invoice();
        $synced      = 0;
        $errors      = [];

        foreach ( $orders as $order ) {
            $result = $invoice_api->create_invoice($order->get_id());
            if ( $result['success'] ) {
                $synced++;
            } else {
                $errors[] = 'Tellimus #' . $order->get_id() . ': ' . $result['message'];
            }
        }

        wp_send_json_success(array(
            'synced' => $synced,
            'total'  => count($orders),
            'errors' => $errors,
        ));
    }

    /**
     * Renderdab seadistuste lehel "Sünkrooni kõik" nupu jaoks vajaliku JS-i.
     * ajaxurl on kättesaadav kuna WordPress lisab selle automaatselt admin lehtedele.
     */
    private function render_sync_script() {
        $nonce = wp_create_nonce('merit_sync_all_nonce');
        ?>
        <style>
        #merit-toast-container{position:fixed;top:52px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
        .merit-toast{padding:12px 18px;border-radius:4px;font-size:13px;line-height:1.5;max-width:380px;box-shadow:0 4px 16px rgba(0,0,0,.2);pointer-events:all;opacity:0;transform:translateX(20px);transition:opacity .25s,transform .25s;}
        .merit-toast.show{opacity:1;transform:translateX(0);}
        .merit-toast.merit-toast-success{background:#2e7d32;color:#fff;}
        .merit-toast.merit-toast-error{background:#d63638;color:#fff;}
        .merit-toast.merit-toast-warning{background:#b45309;color:#fff;}
        </style>
        <div id="merit-toast-container"></div>
        <script>
        function meritToast(msg, type, duration) {
            type = type || 'success';
            duration = duration || 5000;
            var $t = jQuery('<div class="merit-toast merit-toast-' + type + '">' + msg + '</div>');
            jQuery('#merit-toast-container').append($t);
            setTimeout(function() { $t.addClass('show'); }, 20);
            setTimeout(function() {
                $t.removeClass('show');
                setTimeout(function() { $t.remove(); }, 300);
            }, duration);
        }
        jQuery(document).ready(function($) {
            $('#merit-sync-all-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#merit-sync-result');

                $btn.prop('disabled', true).text('Sünkroonin...');
                $result.html('');

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data:   { action: 'merit_sync_all_orders', nonce: '<?php echo esc_js($nonce); ?>' },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Sünkrooni kõik tellimused');
                        if (r.success) {
                            var ok = r.data.synced + '/' + r.data.total + ' arvet edukalt saadetud.';
                            if (r.data.errors.length) {
                                meritToast(ok, 'warning');
                                $.each(r.data.errors, function(i, err) {
                                    meritToast(err, 'error');
                                });
                            } else {
                                meritToast(ok, 'success');
                            }
                        } else {
                            meritToast('Viga: ' + r.data, 'error');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Sünkrooni kõik tellimused');
                        meritToast('Ühenduse viga.', 'error');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Registreerib WordPress dashboard widget-i.
     */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'merit_aktiva_status',
            'Merit Aktiva sünkroonimine',
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Renderdab dashboard widget-i sisu — viimane sünk, järgmine sünk, saatmata tellimused.
     */
    public function render_dashboard_widget() {
        $last_sync = get_option('merit_aktiva_last_sync');
        $next_cron = wp_next_scheduled('merit_aktiva_auto_sync');
        $backoff   = get_transient('merit_aktiva_backoff');
        $pending   = 0;

        if ( class_exists('WooCommerce') && $this->payment_status ) {
            $orders = wc_get_orders(array('status' => $this->payment_status, 'limit' => -1));
            foreach ( $orders as $o ) {
                if ( !$o->get_meta('_merit_invoice_sent_at') ) $pending++;
            }
        }

        echo '<ul style="margin:4px 0 12px;padding:0;list-style:none;">';

        if ( $backoff ) {
            echo '<li style="color:#d63638;font-weight:600;">&#9888; Tagasilöök aktiivne — sünk peatatud 1h</li>';
        }

        if ( $last_sync ) {
            $err_html = $last_sync['errors'] > 0
                ? ' <span style="color:#d63638;">(' . $last_sync['errors'] . ' viga)</span>'
                : '';
            echo '<li>Viimane sünk: <strong>' . esc_html(date('d.m.Y H:i', strtotime($last_sync['time']))) . '</strong> &mdash; ' . intval($last_sync['synced']) . ' arvet' . $err_html . '</li>';
        } else {
            echo '<li style="color:#666;">Viimane sünk: <em>pole veel toimunud</em></li>';
        }

        if ( $next_cron ) {
            $diff = $next_cron - time();
            $str  = $diff <= 0 ? 'varsti' : ( $diff < 60 ? $diff . 's' : '~' . ceil($diff / 60) . ' min' );
            echo '<li>Järgmine sünk: <strong>' . $str . ' pärast</strong></li>';
        }

        if ( $pending > 0 ) {
            echo '<li style="color:#d63638;"><strong>' . $pending . ' tellimust</strong> ootab saatmist &mdash; <a href="' . esc_url(admin_url('admin.php?page=wc-orders&merit_filter=not_sent')) . '">vaata</a></li>';
        } else {
            echo '<li style="color:#2e7d32;">&#10003; Kõik tellimused saadetud</li>';
        }

        echo '</ul>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=merit-aktiva-options')) . '" class="button button-small">Seadistused</a>';
    }
}

new Merit_AktivaAPI_Filter_Invocies();
