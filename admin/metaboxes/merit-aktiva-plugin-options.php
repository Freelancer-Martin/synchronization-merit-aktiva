<?php

/**
 * Registreerib plugin seadistuste lehe WordPress admin menüüs.
 *
 * Seadistused salvestatakse wp_options tabelisse ja kasutatakse
 * class-merit-aktiva-create-invoice.php-s arve saatmisel.
 */
class Merit_Aktiva_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_theme_options_page'));
        add_action('admin_init', array($this, 'setup_theme_options'));
        add_action('wp_ajax_merit_test_connection',   array($this, 'ajax_test_connection'));
        add_action('wp_ajax_merit_reconcile',         array($this, 'ajax_reconcile'));
        add_action('wp_ajax_merit_mark_as_sent',      array($this, 'ajax_mark_as_sent'));
        add_action('wp_ajax_merit_export_settings',   array($this, 'ajax_export_settings'));
        add_action('wp_ajax_merit_import_settings',   array($this, 'ajax_import_settings'));
        add_action('wp_ajax_merit_get_tax_types',     array($this, 'ajax_get_tax_types'));
        add_action('admin_notices', array($this, 'show_missing_credentials_notice'));
    }

    public function add_theme_options_page() {
        add_menu_page(
            'Merit Aktiva Seadistused',
            'Merit Aktiva',
            'manage_options',
            'merit-aktiva-options',
            array($this, 'render_theme_options_page')
        );
    }

    /**
     * Kuvab punase hoiatuse kõigil admin lehtedel kui API võtmed on seadistamata.
     * Näidatakse ainult kasutajatele kellel on manage_options õigus.
     */
    public function show_missing_credentials_notice() {
        if (!current_user_can('manage_options')) return;

        $api_id     = get_option('apikey_text_field');
        $api_secret = get_option('apisecret_text_field');

        if (empty($api_id) || empty($api_secret)) {
            $url = admin_url('admin.php?page=merit-aktiva-options');
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Merit Aktiva:</strong> API ID või API Key on seadistamata. ';
            echo '<a href="' . esc_url($url) . '">Ava seadistused</a>';
            echo '</p></div>';
        }
    }

    /**
     * Renderdab seadistuste lehe koos staatuse sektsiooni ja käsitsi sünkroniseerimisega.
     */
    public function render_theme_options_page() {
        $last_sync      = get_option('merit_aktiva_last_sync');
        $next_cron      = wp_next_scheduled('merit_aktiva_auto_sync');
        $test_nonce     = wp_create_nonce('merit_test_connection_nonce');
        $auto_test      = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';

        // Sünkroonimata tellimuste arv — PHP-poolne filter HPOS ühilduvuse tõttu
        $pending_count = 0;
        if ( class_exists('WooCommerce') ) {
            $payment_status = get_option('payment_status_select_field');
            $all_orders     = wc_get_orders(array('status' => $payment_status, 'limit' => -1));
            foreach ( $all_orders as $o ) {
                if ( !$o->get_meta('_merit_invoice_sent_at') ) $pending_count++;
            }
        }
        ?>
        <style>
        #merit-page{margin:-10px -20px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
        #merit-header{background:#1a232e;padding:0 20px;height:46px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:32px;z-index:999;box-shadow:0 2px 8px rgba(0,0,0,.35);}
        .merit-logo{color:#fff;font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;}
        .merit-logo .dashicons{color:#0073aa;font-size:20px;width:20px;height:20px;}
        #merit-header-save{background:#0073aa;color:#fff;border:none;padding:6px 16px;border-radius:3px;cursor:pointer;font-size:13px;font-weight:500;line-height:1.5;}
        #merit-header-save:hover{background:#006799;}
        #merit-layout{display:flex;min-height:calc(100vh - 78px);}
        #merit-sidebar{width:210px;min-width:210px;background:#1a232e;padding:12px 0;}
        .merit-nav-item{display:flex;align-items:center;gap:10px;padding:10px 16px;color:#9bb0bf;text-decoration:none;font-size:13px;cursor:pointer;border-left:3px solid transparent;transition:background .12s,color .12s;}
        .merit-nav-item:hover{background:#1e2d3d;color:#c8dae4;text-decoration:none;}
        .merit-nav-item.active{background:#1e2d3d;color:#fff;border-left-color:#0073aa;}
        .merit-nav-item .dashicons{font-size:15px;width:15px;height:15px;flex-shrink:0;}
        #merit-content{flex:1;padding:24px;background:#f0f0f1;overflow-x:auto;}
        .merit-section{display:none;}
        .merit-section.merit-active{display:block;}
        .merit-card{background:#fff;border:1px solid #dcdcde;border-radius:3px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
        .merit-card-header{padding:11px 20px;border-bottom:1px solid #dcdcde;display:flex;align-items:center;gap:8px;background:#f6f7f7;}
        .merit-card-header h3{margin:0;font-size:13px;font-weight:600;color:#1d2327;}
        .merit-card-header .dashicons{color:#0073aa;font-size:16px;width:16px;height:16px;}
        .merit-card-body{padding:20px 24px;}
        .merit-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;}
        .merit-stat{background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
        .merit-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#787c82;margin-bottom:8px;}
        .merit-stat-value{font-size:22px;font-weight:700;color:#1d2327;line-height:1.2;}
        .merit-stat-value.s-ok{color:#2e7d32;}
        .merit-stat-value.s-warn{color:#d63638;}
        .merit-stat-value.s-info{font-size:14px;font-weight:600;}
        .merit-card-body .form-table th{padding:12px 10px 12px 0;width:210px;vertical-align:middle;}
        .merit-card-body .form-table td{padding:10px 0;}
        .merit-card-body h2{display:none;}
        </style>

        <div id="merit-page">
            <div id="merit-header">
                <div class="merit-logo">
                    <span class="dashicons dashicons-chart-line"></span>
                    Merit Aktiva
                </div>
                <button id="merit-header-save">Salvesta muudatused</button>
            </div>
            <div id="merit-layout">
                <div id="merit-sidebar">
                    <a class="merit-nav-item active" data-section="merit-sec-seadistused">
                        <span class="dashicons dashicons-admin-settings"></span> Seadistused
                    </a>
                    <a class="merit-nav-item" data-section="merit-sec-ylevaade">
                        <span class="dashicons dashicons-dashboard"></span> Ülevaade
                    </a>
                    <a class="merit-nav-item" data-section="merit-sec-sunk">
                        <span class="dashicons dashicons-update"></span> Sünkroniseerimine
                    </a>
                    <a class="merit-nav-item" data-section="merit-sec-tooriistad">
                        <span class="dashicons dashicons-admin-tools"></span> Tööriistad
                    </a>
                </div>
                <div id="merit-content">

                    <!-- Ülevaade -->
                    <div id="merit-sec-ylevaade" class="merit-section">
                        <div class="merit-stat-grid">
                            <div class="merit-stat">
                                <div class="merit-stat-label">Saatmata tellimused</div>
                                <div class="merit-stat-value <?php echo $pending_count > 0 ? 's-warn' : 's-ok'; ?>">
                                    <?php echo $pending_count > 0 ? intval($pending_count) : '&#10003; 0'; ?>
                                </div>
                            </div>
                            <div class="merit-stat">
                                <div class="merit-stat-label">Viimane sünk</div>
                                <div class="merit-stat-value s-info">
                                    <?php if ($last_sync): ?>
                                        <?php echo esc_html(date('d.m H:i', strtotime($last_sync['time']))); ?>
                                        &mdash; <?php echo intval($last_sync['synced']); ?> arvet
                                        <?php if ($last_sync['errors'] > 0): ?><br><span style="color:#d63638;font-size:12px;"><?php echo intval($last_sync['errors']); ?> viga</span><?php endif; ?>
                                    <?php else: ?><span style="color:#787c82;">Pole toimunud</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="merit-stat">
                                <div class="merit-stat-label">Järgmine sünk</div>
                                <div class="merit-stat-value s-info">
                                    <?php if ($next_cron):
                                        $diff = $next_cron - time();
                                        echo $diff <= 0 ? 'Varsti' : ($diff < 60 ? $diff . ' s' : '~' . ceil($diff/60) . ' min');
                                        echo ' <span style="color:#787c82;font-size:12px;">(' . date('H:i:s', $next_cron) . ')</span>';
                                    else: ?><span class="s-warn">Cron puudub</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($pending_count > 0): ?>
                        <div class="merit-card">
                            <div class="merit-card-body">
                                <div class="notice notice-warning inline" style="margin:0;">
                                    <p><strong><?php echo intval($pending_count); ?> tellimust</strong> ootab Merit Aktivasse saatmist.
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&merit_filter=not_sent')); ?>">Vaata tellimusi</a>
                                    &nbsp;|&nbsp;
                                    <a href="#" id="merit-pending-sync-link">Sünkrooni kohe</a></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Seadistused -->
                    <div id="merit-sec-seadistused" class="merit-section merit-active">
                        <div id="merit-autotest-result" style="margin-bottom:12px;"></div>
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-admin-settings"></span>
                                <h3>Üldised seadistused</h3>
                            </div>
                            <div class="merit-card-body">
                                <form method="post" action="options.php" id="merit-settings-form">
                                    <?php settings_fields('theme_options_group'); ?>
                                    <?php do_settings_sections('theme_options'); ?>
                                    <p style="margin-top:16px;"><input type="submit" class="button button-primary" value="Salvesta muudatused"></p>
                                </form>
                            </div>
                        </div>
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-update"></span>
                                <h3>Käsitsi sünkroniseerimine</h3>
                            </div>
                            <div class="merit-card-body">
                                <p>Saada kõik <strong><?php echo esc_html(get_option('payment_status_select_field', 'completed')); ?></strong> staatusega tellimused Merit Aktivasse.</p>
                                <button id="merit-sync-all-btn" class="button button-primary">Sünkrooni kõik tellimused</button>
                                <div id="merit-sync-result" style="margin-top:12px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Sünkroniseerimine -->
                    <div id="merit-sec-sunk" class="merit-section">
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-search"></span>
                                <h3>Arve võrdlus Merit Aktivaga</h3>
                            </div>
                            <div class="merit-card-body">
                                <p>Kontrollib viimase 3 kuu arveid — leiab lahknevused WooCommerce ja Merit Aktiva vahel.</p>
                                <button id="merit-reconcile-btn" class="button button-secondary"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('merit_reconcile_nonce')); ?>"
                                        data-invoice-nonce="<?php echo esc_attr(wp_create_nonce('merit_invoice_nonce')); ?>">
                                    Kontrolli Merit Aktivaga
                                </button>
                                <div id="merit-reconcile-result" style="margin-top:12px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Tööriistad -->
                    <div id="merit-sec-tooriistad" class="merit-section">
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-superhero"></span>
                                <h3>API ühenduse test</h3>
                            </div>
                            <div class="merit-card-body">
                                <p>Kontrolli kas API ID ja API Key on õiged enne esimest sünkroniseerimist.</p>
                                <button id="merit-test-conn-btn" class="button button-secondary"
                                        data-nonce="<?php echo esc_attr($test_nonce); ?>">
                                    Testi ühendust
                                </button>
                                <div id="merit-test-result" style="margin-top:10px;"></div>
                            </div>
                        </div>
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-download"></span>
                                <h3>Seadistuste eksport / import</h3>
                            </div>
                            <div class="merit-card-body">
                                <p>Varunda seadistused JSON-failina või taasta teiselt serverilt.</p>
                                <button id="merit-export-btn" class="button" data-nonce="<?php echo esc_attr(wp_create_nonce('merit_settings_io_nonce')); ?>">Ekspordi seadistused</button>
                                &nbsp;
                                <label class="button" style="cursor:pointer;">
                                    Impordi seadistused
                                    <input type="file" id="merit-import-file" accept=".json" style="display:none;" data-nonce="<?php echo esc_attr(wp_create_nonce('merit_settings_io_nonce')); ?>">
                                </label>
                                <div id="merit-io-result" style="margin-top:8px;"></div>
                            </div>
                        </div>
                        <div class="merit-card">
                            <div class="merit-card-header">
                                <span class="dashicons dashicons-list-view"></span>
                                <h3>Sünkrooni ajalugu <small style="font-weight:normal;color:#787c82;">(viimased 50)</small></h3>
                            </div>
                            <div class="merit-card-body" style="padding:0;">
                                <?php
                                $sync_log = get_option('merit_aktiva_sync_log', array());
                                if (empty($sync_log)): ?>
                                    <p style="padding:16px 24px;margin:0;"><em>Veel pole ühtegi sünkrooni toimunud.</em></p>
                                <?php else: ?>
                                    <table id="merit-log-table" class="widefat striped" style="border:none;">
                                        <thead><tr>
                                            <th style="width:140px;">Aeg</th>
                                            <th style="width:100px;">Tellimus</th>
                                            <th style="width:70px;">Tulemus</th>
                                            <th>Sõnum</th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ($sync_log as $entry): ?>
                                            <tr>
                                                <td><?php echo esc_html(date('d.m.Y H:i', strtotime($entry['t']))); ?></td>
                                                <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $entry['o'] . '&action=edit')); ?>">#<?php echo intval($entry['o']); ?></a></td>
                                                <td><?php echo $entry['s'] ? '<span style="color:#2e7d32;">&#10003; OK</span>' : '<span style="color:#d63638;">&#10007; Viga</span>'; ?></td>
                                                <td><?php echo esc_html($entry['m']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div id="merit-log-pagination" style="padding:10px 16px;display:flex;align-items:center;gap:6px;border-top:1px solid #dcdcde;flex-wrap:wrap;"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- #merit-content -->
            </div><!-- #merit-layout -->
        </div><!-- #merit-page -->

        <script>
        jQuery(document).ready(function($) {
            // Sidebar navigatsioon
            function activateSection(sectionId) {
                $('.merit-nav-item').removeClass('active');
                $('.merit-nav-item[data-section="' + sectionId + '"]').addClass('active');
                $('.merit-section').removeClass('merit-active');
                $('#' + sectionId).addClass('merit-active');
                sessionStorage.setItem('merit_active_section', sectionId);
            }
            $('.merit-nav-item').on('click', function(e) {
                e.preventDefault();
                activateSection($(this).data('section'));
            });
            // Taasta viimane tab
            var savedSection = sessionStorage.getItem('merit_active_section');
            if (savedSection && $('#' + savedSection).length) activateSection(savedSection);

            // Päise "Salvesta" nupp → avab Seadistused ja saadab vormi
            $('#merit-header-save').on('click', function() {
                activateSection('merit-sec-seadistused');
                setTimeout(function() { $('#merit-settings-form').submit(); }, 50);
            });

            // Pärast seadistuste salvestamist testi ühendust taustal — ei vaheta tabi
            <?php if ( $auto_test ): ?>
            $.ajax({
                url: ajaxurl, method: 'POST',
                data: { action: 'merit_test_connection', nonce: '<?php echo esc_js(wp_create_nonce('merit_test_connection_nonce')); ?>' },
                success: function(r) {
                    var cls = r.success ? 'notice-success' : 'notice-error';
                    var msg = r.success ? '&#10003; ' + r.data : '&#10007; ' + r.data;
                    $('#merit-autotest-result').html('<div class="notice ' + cls + ' inline"><p>' + msg + '</p></div>');
                },
                error: function() {
                    $('#merit-autotest-result').html('<div class="notice notice-warning inline"><p>API ühenduse test ebaõnnestus.</p></div>');
                }
            });
            <?php endif; ?>

            // "Sünkrooni kohe" — lülita Sünkroniseerimine sektsiooni ja käivita
            $('#merit-pending-sync-link').on('click', function(e) {
                e.preventDefault();
                activateSection('merit-sec-sunk');
                setTimeout(function() { $('#merit-sync-all-btn').trigger('click'); }, 100);
            });

            // Arve võrdlus
            $('#merit-reconcile-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#merit-reconcile-result');
                var nonce   = $btn.data('nonce');
                var invNonce = $btn.data('invoice-nonce');

                $btn.prop('disabled', true).text('Kontrollin...');
                $result.html('<p><em>Tõmban andmeid Merit Aktivast...</em></p>');

                $.ajax({
                    url: ajaxurl, method: 'POST',
                    data: { action: 'merit_reconcile', nonce: nonce },
                    timeout: 35000,
                    success: function(r) {
                        $btn.prop('disabled', false).text('Kontrolli Merit Aktivaga');
                        if (!r.success) {
                            $result.html('<div class="notice notice-error inline"><p>' + r.data + '</p></div>');
                            return;
                        }
                        var d = r.data;
                        var html = '<p>Merit Aktivas: <strong>' + d.merit_total + '</strong> arvet | WooCommerce saadetud: <strong>' + d.wc_total + '</strong></p>';

                        // Puudub Meritis
                        html += '<h4 style="margin:12px 0 6px;">';
                        html += d.missing_in_merit.length === 0
                            ? '<span style="color:#2e7d32;">&#10003; Kõik WooCommerce arved on Merit Aktivas olemas</span>'
                            : '<span style="color:#d63638;">&#9888; ' + d.missing_in_merit.length + ' arvet puudub Merit Aktivast</span>';
                        html += '</h4>';
                        if (d.missing_in_merit.length > 0) {
                            html += '<table class="widefat striped" style="max-width:700px;margin-bottom:12px;"><thead><tr><th>Tellimus</th><th>Klient</th><th>Summa</th><th>Saadetud</th><th></th></tr></thead><tbody>';
                            $.each(d.missing_in_merit, function(i, row) {
                                html += '<tr><td>#' + row.order_id + '</td><td>' + $('<div>').text(row.customer).html() + '</td><td>' + row.total + '</td><td>' + row.sent_at + '</td>';
                                html += '<td><button class="button button-small merit-force-resend" data-order-id="' + row.order_id + '" data-nonce="' + invNonce + '">Saada uuesti</button> <span class="merit-resend-status"></span></td></tr>';
                            });
                            html += '</tbody></table>';
                        }

                        // Puudub WooCommerce-is
                        html += '<h4 style="margin:12px 0 6px;">';
                        html += d.missing_in_wc.length === 0
                            ? '<span style="color:#2e7d32;">&#10003; Kõik Merit Aktiva arved on WooCommerce-is märgitud</span>'
                            : '<span style="color:#f0a500;">&#9432; ' + d.missing_in_wc.length + ' Merit arvet pole WooCommerce-is märgitud saadetuna</span>';
                        html += '</h4>';
                        if (d.missing_in_wc.length > 0) {
                            html += '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Arve nr</th><th>Klient</th><th>Summa</th><th>WC staatus</th><th></th></tr></thead><tbody>';
                            $.each(d.missing_in_wc, function(i, row) {
                                var status = row.wc_status ? row.wc_status : '–';
                                var actionBtn = row.exists_in_wc
                                    ? '<button class="button button-small merit-mark-sent" data-order-id="' + row.invoice_no + '" data-nonce="' + nonce + '">Märgi saadetuna</button> <span class="merit-mark-status"></span>'
                                    : '<em style="color:#999;">Tellimust pole WC-s</em>';
                                html += '<tr><td>#' + row.invoice_no + '</td><td>' + $('<div>').text(row.customer).html() + '</td><td>' + row.total + '</td><td>' + status + '</td><td>' + actionBtn + '</td></tr>';
                            });
                            html += '</tbody></table>';
                        }

                        $result.html(html);

                        // Saada uuesti nupp
                        $result.on('click', '.merit-force-resend', function() {
                            var $b = $(this);
                            $b.prop('disabled', true).text('Saadan...');
                            $.post(ajaxurl, { action: 'merit_force_resend', nonce: $b.data('nonce'), order_id: $b.data('order-id') }, function(res) {
                                if (res.success) {
                                    $b.closest('tr').find('.merit-resend-status').html('<span style="color:#2e7d32;">&#10003; Saadetud</span>');
                                    $b.remove();
                                } else {
                                    $b.prop('disabled', false).text('Saada uuesti');
                                    $b.closest('tr').find('.merit-resend-status').html('<span style="color:#d63638;">&#10007; ' + (res.data.message || res.data) + '</span>');
                                }
                            });
                        });

                        // Märgi saadetuna nupp
                        $result.on('click', '.merit-mark-sent', function() {
                            var $b = $(this);
                            $b.prop('disabled', true).text('Märgin...');
                            $.post(ajaxurl, { action: 'merit_mark_as_sent', nonce: $b.data('nonce'), order_id: $b.data('order-id') }, function(res) {
                                if (res.success) {
                                    $b.closest('tr').find('.merit-mark-status').html('<span style="color:#2e7d32;">&#10003; Märgitud</span>');
                                    $b.remove();
                                } else {
                                    $b.prop('disabled', false).text('Märgi saadetuna');
                                }
                            });
                        });
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Kontrolli Merit Aktivaga');
                        $result.html('<div class="notice notice-error inline"><p>Ühenduse viga või timeout.</p></div>');
                    }
                });
            });

            // Eksport
            $('#merit-export-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);
                $.post(ajaxurl, { action: 'merit_export_settings', nonce: $btn.data('nonce') }, function(r) {
                    $btn.prop('disabled', false);
                    if (r.success) {
                        var blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' });
                        var link = document.createElement('a');
                        link.href = URL.createObjectURL(blob);
                        link.download = 'merit-aktiva-seadistused.json';
                        link.click();
                    } else {
                        $('#merit-io-result').html('<span style="color:#d63638;">' + r.data + '</span>');
                    }
                });
            });

            // Import
            $('#merit-import-file').on('change', function() {
                var file  = this.files[0];
                var nonce = $(this).data('nonce');
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function(e) {
                    $.post(ajaxurl, { action: 'merit_import_settings', nonce: nonce, settings: e.target.result }, function(r) {
                        if (r.success) {
                            $('#merit-io-result').html('<span style="color:#2e7d32;">&#10003; ' + r.data + '. Leht laetakse uuesti...</span>');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            $('#merit-io-result').html('<span style="color:#d63638;">&#10007; ' + r.data + '</span>');
                        }
                    });
                };
                reader.readAsText(file);
            });

            $('#merit-toggle-secret').on('click', function() {
                var $input = $('#merit-api-secret');
                var isPassword = $input.attr('type') === 'password';
                $input.attr('type', isPassword ? 'text' : 'password');
                $(this).text(isPassword ? 'Peida' : 'Näita');
            });

            $('#merit-test-conn-btn').on('click', function() {
                var $btn    = $(this);
                var $result = $('#merit-test-result');
                $btn.prop('disabled', true).text('Kontrollin...');
                $result.html('');

                $.ajax({
                    url:    ajaxurl,
                    method: 'POST',
                    data:   { action: 'merit_test_connection', nonce: $btn.data('nonce') },
                    success: function(r) {
                        $btn.prop('disabled', false).text('Testi ühendust');
                        if (r.success) {
                            $result.html('<div class="notice notice-success inline"><p>&#10003; ' + r.data + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>&#10007; ' + r.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Testi ühendust');
                        $result.html('<div class="notice notice-error inline"><p>Ühenduse viga.</p></div>');
                    }
                });
            });

            // Sünkrooni ajaloo paginatsioon
            (function() {
                var $rows = $('#merit-log-table tbody tr');
                var perPage = 15;
                var total = $rows.length;
                var pages = Math.ceil(total / perPage);
                if (pages <= 1) return;

                function showPage(p) {
                    $rows.hide();
                    $rows.slice((p - 1) * perPage, p * perPage).show();
                    $('#merit-log-pagination .merit-page-btn').removeClass('active').css({'background':'','color':'','fontWeight':''});
                    $('#merit-log-pagination .merit-page-btn[data-p="' + p + '"]').addClass('active').css({'background':'#0073aa','color':'#fff','fontWeight':'600'});
                }

                var $pag = $('#merit-log-pagination');
                for (var i = 1; i <= pages; i++) {
                    $pag.append('<button class="button merit-page-btn" data-p="' + i + '" style="min-width:34px;">' + i + '</button>');
                }
                $pag.append('<span style="color:#787c82;font-size:12px;margin-left:6px;">' + total + ' kirjet</span>');

                $pag.on('click', '.merit-page-btn', function() {
                    showPage(parseInt($(this).data('p')));
                });

                showPage(1);
            })();
        });
        </script>
        <?php
    }

    /**
     * AJAX handler API ühenduse testimiseks.
     * Teeb päringu Merit Aktiva getinvoices endpoint-ile tänase kuupäevaga.
     * HTTP 200 = võtmed on õiged, HTTP 401 = valed võtmed.
     */
    public function ajax_test_connection() {
        check_ajax_referer('merit_test_connection_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $api_id     = get_option('apikey_text_field');
        $api_secret = get_option('apisecret_text_field');

        if (empty($api_id) || empty($api_secret)) {
            wp_send_json_error('API ID või API Key on seadistamata');
            return;
        }

        $timestamp = gmdate('YmdHis');
        $today     = intval(date('Ymd'));
        $payload   = array('Periodstart' => $today, 'PeriodEnd' => $today, 'UnPaid' => false);

        $json_body   = json_encode($payload);
        $signature   = base64_encode(hash_hmac('sha256', $api_id . $timestamp . $json_body, $api_secret, true));
        $request_url = 'https://aktiva.merit.ee/api/v2/getinvoices?ApiId=' . $api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($request_url, array(
            'body'    => $json_body,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            $message = 'Ühenduse viga: ' . $response->get_error_message();
            error_log('Merit Aktiva ühenduse test: ' . $message);
            wp_send_json_error($message);
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 401) {
            $message = 'Autentimine ebaõnnestus (401) — kontrolli API ID ja API Key';
            error_log('Merit Aktiva ühenduse test: ' . $message);
            wp_send_json_error($message);
            return;
        }

        if ($status_code >= 200 && $status_code < 300) {
            $message = 'Ühendus OK (HTTP ' . $status_code . ')';
            error_log('Merit Aktiva ühenduse test: ' . $message);
            wp_send_json_success($message);
            return;
        }

        $message = 'API tagastas staatuse ' . $status_code;
        error_log('Merit Aktiva ühenduse test: ' . $message);
        wp_send_json_error($message);
    }

    public function setup_theme_options() {
        add_settings_section(
            'theme_options_section',
            'Üldised seadistused',
            array($this, 'section_callback'),
            'theme_options'
        );

        add_settings_field('apikey_text_field', 'API ID', array($this, 'text_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'apikey_text_field', array($this, 'sanitize_text_field'));

        add_settings_field('apisecret_text_field', 'API Key (salajane võti)', array($this, 'apisecret_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'apisecret_text_field', array($this, 'sanitize_text_field'));

        add_settings_field('payment_dead_line', 'Maksetähtaeg päevades', array($this, 'payment_text_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'payment_dead_line', array($this, 'validate_payment_deadline'));

        // Käibemaksu tüüp — UUID-d on Merit Aktiva süsteemist (muutuvad harva)
        add_settings_field('tax_select_field', 'Käibemaksu tüüp', array($this, 'select_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'tax_select_field', array($this, 'validate_tax_field'));

        add_settings_field('payment_status_select_field', 'Arve saatmise tellimuse staatus', array($this, 'payment_status_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'payment_status_select_field', array($this, 'validate_payment_status'));

        add_settings_field('invoice_prefix_field', 'Arve numbri prefiks', array($this, 'invoice_prefix_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'invoice_prefix_field', array($this, 'sanitize_text_field'));

        add_settings_field('refno_prefix_field', 'Viitenumbri prefiks', array($this, 'refno_prefix_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'refno_prefix_field', array($this, 'sanitize_text_field'));

        add_settings_field('default_unit_field', 'Vaikimisi ühik', array($this, 'default_unit_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'default_unit_field', array($this, 'sanitize_text_field'));

        add_settings_field('income_account_field', 'Tulude konto', array($this, 'income_account_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'income_account_field', array($this, 'sanitize_text_field'));

        add_settings_field('contact_person_field', 'Kontaktisik arvel', array($this, 'contact_person_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'contact_person_field', array($this, 'sanitize_text_field'));

        add_settings_field('invoice_header_comment_field', 'Arve päisekommentaar', array($this, 'invoice_header_comment_field_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'invoice_header_comment_field', array($this, 'sanitize_text_field'));

        add_settings_field('payment_method_mapping', 'Makseviisi kaardistus', array($this, 'payment_method_mapping_callback'), 'theme_options', 'theme_options_section');
        register_setting('theme_options_group', 'payment_method_mapping');
    }

    public function section_callback() {
        echo '<p>Siin saad seadistada Merit Aktiva sünkroniseerimise plugin-i valikud.</p>';
    }

    public function text_field_callback() {
        $value = get_option('apikey_text_field');
        echo '<input type="text" name="apikey_text_field" value="' . esc_attr($value) . '" style="width:320px;" />';
        echo '<p class="description">Leiad Merit Aktiva portaalist: <strong>Seaded → Ettevõte → API seaded → API ID</strong></p>';
    }

    public function apisecret_field_callback() {
        $value = get_option('apisecret_text_field');
        // autocomplete="new-password" takistab brauseril parooli automaatset täitmist
        echo '<input type="password" id="merit-api-secret" name="apisecret_text_field" value="' . esc_attr($value) . '" style="width:320px;" autocomplete="new-password" />';
        echo ' <button type="button" id="merit-toggle-secret" class="button button-small">Näita</button>';
        echo '<p class="description">Leiad Merit Aktiva portaalist: <strong>Seaded → Ettevõte → API seaded → API Key</strong>. Hoia salajasena.</p>';
    }

    public function payment_text_field_callback() {
        $value = absint(get_option('payment_dead_line'));
        echo '<input type="number" min="1" name="payment_dead_line" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Mitu päeva pärast arve väljastamist on maksetähtaeg. Näiteks <strong>14</strong> = 14 päeva.</p>';
    }

    public function select_field_callback() {
        $value = get_option('tax_select_field');
        // Merit Aktiva Eesti standardsed käibemaksuliigid
        $static_taxes = array(
            array('TaxId' => '973a4395-665f-47a6-a5b6-5384dd24f8d0', 'Code' => '',     'Name' => '0% — Ei ole käive / maksuvaba'),
            array('TaxId' => 'fd050f9b-f376-40fb-aee5-af2d1f04970f', 'Code' => '3040', 'Name' => '9% — Kauba, teenuse müük Eestis 9%'),
            array('TaxId' => 'b9b25735-6a15-4d4e-8720-25b254ae3d21', 'Code' => '3000', 'Name' => '22% — Kauba, teenuse müük Eestis 22%'),
            array('TaxId' => '1e420e04-3dd7-46a5-b71f-0490779c2638', 'Code' => '3000', 'Name' => '24% — Kauba, teenuse müük Eestis 24%'),
        );
        $nonce = wp_create_nonce('merit_tax_types_nonce');
        echo '<select name="tax_select_field" id="merit-tax-select">';
        foreach ( $static_taxes as $t ) {
            $label = ( $t['Code'] ? $t['Code'] . ' — ' : '' ) . $t['Name'];
            echo '<option value="' . esc_attr($t['TaxId']) . '" ' . selected($value, $t['TaxId'], false) . '>' . esc_html($t['Name']) . '</option>';
        }
        if ( $value && ! in_array($value, array_column($static_taxes, 'TaxId')) ) {
            echo '<option value="' . esc_attr($value) . '" selected="selected">' . esc_html($value) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" id="merit-load-taxes" class="button button-secondary" style="margin-left:8px;">Lae Merit API-st</button>';
        echo '<span id="merit-tax-status" style="margin-left:8px;color:#666;"></span>';
        echo '<p class="description">Käibemaksumäär arveridadele. Eesti standard on <strong>22%</strong>. UUID-d on orientiirsed — kasuta "Lae Merit API-st" täpsustamiseks.</p>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            var savedVal = <?php echo json_encode($value); ?>;

            $('#merit-load-taxes').on('click', function() {
                $('#merit-tax-status').text('Laadin...');
                $(this).prop('disabled', true);
                var btn = this;
                $.post(ajaxurl, {
                    action: 'merit_get_tax_types',
                    nonce:  <?php echo json_encode($nonce); ?>
                }, function(r) {
                    if (!r.success || !r.data || !r.data.length) {
                        $('#merit-tax-status').css('color','#b45309').text('Merit API ei tagasta maksutüüpe — kasuta eellaadituid valikuid');
                        $(btn).prop('disabled', false);
                        return;
                    }
                    var sel = $('#merit-tax-select');
                    sel.empty();
                    $.each(r.data, function(i, t) {
                        var label = (t.Code ? t.Code + ' — ' : '') + (t.Name || t.TaxId);
                        var opt = $('<option>').val(t.TaxId).text(label);
                        if (t.TaxId === savedVal) opt.prop('selected', true);
                        sel.append(opt);
                    });
                    $('#merit-tax-status').css('color','#2e7d32').text('✓ ' + r.data.length + ' maksutüüpi laaditud');
                    $(btn).prop('disabled', false);
                }).fail(function() {
                    $('#merit-tax-status').css('color','#d63638').text('Ühenduse viga');
                    $(btn).prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    public function payment_status_field_callback() {
        $value = get_option('payment_status_select_field');
        echo '<select name="payment_status_select_field">';
        echo '<option value="processing" ' . selected($value, 'processing', false) . '>Processing (töötlemisel)</option>';
        echo '<option value="on-hold" '    . selected($value, 'on-hold', false)    . '>On hold (ootel)</option>';
        echo '<option value="completed" '  . selected($value, 'completed', true)   . '>Completed (lõpetatud)</option>';
        echo '<option value="cancelled" '  . selected($value, 'cancelled', false)  . '>Cancelled (tühistatud)</option>';
        echo '<option value="refunded" '   . selected($value, 'refunded', false)   . '>Refunded (tagastatud)</option>';
        echo '<option value="failed" '     . selected($value, 'failed', false)     . '>Failed (ebaõnnestunud)</option>';
        echo '<option value="draft" '      . selected($value, 'draft', false)      . '>Draft (mustand)</option>';
        echo '</select>';
        echo '<p class="description">Arve saadetakse Merit Aktivasse automaatselt kui tellimuse staatus jõuab sellesse olekusse. Tavaliselt <strong>Completed</strong>.</p>';
    }

    public function invoice_prefix_field_callback() {
        $value = get_option('invoice_prefix_field', '');
        echo '<input type="text" name="invoice_prefix_field" value="' . esc_attr($value) . '" style="width:120px;" placeholder="nt WC" />';
        echo '<p class="description">Prefiks arve numbri ette. Nt <strong>WC</strong> + tellimus #49 → arve number <strong>WC49</strong>. Tühjaks jättes kasutatakse ainult tellimuse ID-d.</p>';
    }

    public function refno_prefix_field_callback() {
        $value = get_option('refno_prefix_field', '');
        echo '<input type="text" name="refno_prefix_field" value="' . esc_attr($value) . '" style="width:120px;" placeholder="nt 1" />';
        echo '<p class="description">Numbriline prefiks viitenumbri arvutamisel (ainult numbrid — tähed eemaldatakse). Nt prefiks <strong>1</strong> + tellimus #34 → viitenumber <strong>1342</strong>. Tühjaks jättes kasutatakse ainult tellimuse ID-d.</p>';
    }

    public function default_unit_field_callback() {
        $value = get_option('default_unit_field', 'tk');
        $units = array('tk' => 'tk (tükk)', 'kg' => 'kg (kilogramm)', 'l' => 'l (liiter)', 'h' => 'h (tund)', 'm' => 'm (meeter)', 'm²' => 'm² (ruutmeeter)');
        echo '<select name="default_unit_field">';
        foreach ( $units as $key => $label ) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Mõõtühik mis kuvatakse arve ridadel. Enamasti <strong>tk</strong>.</p>';
    }

    public function income_account_field_callback() {
        $value = get_option('income_account_field', '');
        echo '<input type="text" name="income_account_field" value="' . esc_attr($value) . '" style="width:160px;" placeholder="nt 3000" />';
        echo '<p class="description">Merit Aktiva raamatupidamiskonto number müügituludele (nt <strong>3000</strong>). Tühjaks jättes Merit määrab konto automaatselt.</p>';
    }

    public function contact_person_field_callback() {
        $value = get_option('contact_person_field', '');
        echo '<input type="text" name="contact_person_field" value="' . esc_attr($value) . '" style="width:240px;" placeholder="nt Eesnimi Perenimi" />';
        echo '<p class="description">Kontaktisiku nimi mis kuvatakse arvel. Jäta tühjaks kui pole vajalik.</p>';
    }

    public function invoice_header_comment_field_callback() {
        $value = get_option('invoice_header_comment_field', '');
        echo '<input type="text" name="invoice_header_comment_field" value="' . esc_attr($value) . '" style="width:320px;" placeholder="nt Tasuda pangaülekandega" />';
        echo '<p class="description">Tekst mis kuvatakse arve päises. Krediitarvetel täiendatakse automaatselt viitega originaalarvele.</p>';
    }

    public function payment_method_mapping_callback() {
        $mapping = get_option('payment_method_mapping', array());
        if ( ! function_exists('WC') || ! WC()->payment_gateways() ) {
            echo '<p><em>WooCommerce pole aktiivne.</em></p>';
            return;
        }
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if ( empty($gateways) ) {
            echo '<p><em>Aktiivseid makseviise ei leitud.</em></p>';
            return;
        }
        echo '<table class="widefat" style="max-width:500px;"><thead><tr>';
        echo '<th>WooCommerce makseviis</th><th>Merit kood</th>';
        echo '</tr></thead><tbody>';
        foreach ( $gateways as $id => $gateway ) {
            $val = isset($mapping[$id]) ? esc_attr($mapping[$id]) : '';
            echo '<tr>';
            echo '<td>' . esc_html($gateway->get_method_title()) . ' <small style="color:#999;">(' . esc_html($id) . ')</small></td>';
            echo '<td><input type="text" name="payment_method_mapping[' . esc_attr($id) . ']" value="' . $val . '" style="width:150px;" placeholder="nt Transfer" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">Sisesta Merit Aktiva makseviisi kood: <strong>Transfer</strong>, <strong>Card</strong>, <strong>Cash</strong> vms.</p>';
    }

    public function ajax_get_tax_types() {
        check_ajax_referer('merit_tax_types_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Unauthorized', 403);
        }
        $api_id     = get_option('apikey_text_field');
        $api_secret = get_option('apisecret_text_field');
        if ( ! $api_id || ! $api_secret ) {
            wp_send_json_error('API seadistused puuduvad');
        }
        $timestamp = gmdate('YmdHis');
        $payload   = json_encode([]);
        $signature = base64_encode(hash_hmac('sha256', $api_id . $timestamp . $payload, $api_secret, true));
        $url       = 'https://aktiva.merit.ee/api/v2/gettaxes?ApiId=' . urlencode($api_id) . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);
        $response  = wp_remote_post($url, [
            'body'    => $payload,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);
        if ( is_wp_error($response) ) {
            wp_send_json_error($response->get_error_message());
        }
        $taxes = json_decode(wp_remote_retrieve_body($response), true);
        if ( ! is_array($taxes) || empty($taxes) ) {
            wp_send_json_error('Merit API ei tagastanud maksutüüpe');
        }
        wp_send_json_success($taxes);
    }

    /**
     * Ekspordib kõik plugin seadistused JSON-failina allalaadimiseks.
     */
    public function ajax_export_settings() {
        check_ajax_referer('merit_settings_io_nonce', 'nonce');
        if ( !current_user_can('manage_options') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $keys = array(
            'apikey_text_field', 'apisecret_text_field', 'payment_dead_line',
            'tax_select_field', 'payment_status_select_field', 'refno_prefix_field',
            'invoice_prefix_field', 'default_unit_field', 'income_account_field',
            'contact_person_field', 'invoice_header_comment_field', 'payment_method_mapping',
        );
        $export = array();
        foreach ( $keys as $key ) {
            $export[$key] = get_option($key);
        }
        wp_send_json_success($export);
    }

    /**
     * Impordib seadistused JSON-objektist.
     * Aktsepteerib ainult teadaolevaid seadistusvõtmeid — tundmatud ignoreeritakse.
     */
    public function ajax_import_settings() {
        check_ajax_referer('merit_settings_io_nonce', 'nonce');
        if ( !current_user_can('manage_options') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $allowed_keys = array(
            'apikey_text_field', 'apisecret_text_field', 'payment_dead_line',
            'tax_select_field', 'payment_status_select_field', 'refno_prefix_field',
            'invoice_prefix_field', 'default_unit_field', 'income_account_field',
            'contact_person_field', 'invoice_header_comment_field', 'payment_method_mapping',
        );
        $raw  = stripslashes($_POST['settings'] ?? '');
        $data = json_decode($raw, true);
        if ( !is_array($data) ) {
            wp_send_json_error('Vigane JSON vorming');
            return;
        }
        $imported = 0;
        foreach ( $allowed_keys as $key ) {
            if ( isset($data[$key]) ) {
                update_option($key, $data[$key]);
                $imported++;
            }
        }
        wp_send_json_success($imported . ' seadistust imporditud');
    }

    public function sanitize_text_field( $input ) {
        return sanitize_text_field($input);
    }

    /**
     * Teeb ühe getinvoices päringu Merit API-sse.
     * Merit nõuab UnPaid parameetrit — ilma selleta tagastatakse tühi massiiv.
     *
     * @param string $api_id
     * @param string $api_secret
     * @param bool   $unpaid     true = maksmata, false = makstud
     * @param int    $start      Ymd formaadis (nt 20250101)
     * @param int    $end        Ymd formaadis (nt 20260618)
     * @return array|WP_Error
     */
    private function fetch_merit_invoices( $api_id, $api_secret, $unpaid, $start, $end ) {
        $timestamp = gmdate('YmdHis');
        $payload   = array(
            'Periodstart' => $start,
            'PeriodEnd'   => $end,
            'UnPaid'      => $unpaid,
        );
        $json_body   = json_encode($payload);
        $signature   = base64_encode(hash_hmac('sha256', $api_id . $timestamp . $json_body, $api_secret, true));
        $request_url = 'https://aktiva.merit.ee/api/v2/getinvoices?ApiId=' . $api_id . '&timestamp=' . $timestamp . '&signature=' . urlencode($signature);

        $response = wp_remote_post($request_url, array(
            'body'    => $json_body,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 30,
        ));

        if ( is_wp_error($response) ) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);

        $data = json_decode($raw_body, true);
        // Indekseeritud massiiv = arveloend; assotsiatiivne = veaobjekt
        if ( !is_array($data) || ( !empty($data) && !isset($data[0]) ) ) {
            return array();
        }
        return $data;
    }

    /**
     * Võrdleb WooCommerce "saadetud" tellimusi Merit Aktiva tegelike arvetega.
     * Teeb kaks API päringut (maksmata + makstud) et saada kõik arved 3 kuu jooksul.
     * Tagastab kaks nimekirja: puudub Meritis, puudub WC-s.
     */
    public function ajax_reconcile() {
        check_ajax_referer('merit_reconcile_nonce', 'nonce');
        if ( !current_user_can('manage_options') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $api_id     = get_option('apikey_text_field');
        $api_secret = get_option('apisecret_text_field');
        if ( empty($api_id) || empty($api_secret) ) {
            wp_send_json_error('API ID või API Key on seadistamata');
            return;
        }

        // Merit API lubab maksimaalselt 3 kuud korraga
        $start = intval(date('Ymd', strtotime('-3 months')));
        $end   = intval(date('Ymd'));

        // Kaks päringut kuna Merit nõuab UnPaid parameetrit — ühe päringuga ei saa kõiki
        $unpaid = $this->fetch_merit_invoices($api_id, $api_secret, true,  $start, $end);
        $paid   = $this->fetch_merit_invoices($api_id, $api_secret, false, $start, $end);

        if ( is_wp_error($unpaid) ) {
            wp_send_json_error('Merit API viga: ' . $unpaid->get_error_message());
            return;
        }
        if ( is_wp_error($paid) ) {
            wp_send_json_error('Merit API viga: ' . $paid->get_error_message());
            return;
        }

        $merit_data = array_merge($unpaid, $paid);

        // Indekseeri Merit arved InvoiceNo järgi
        $merit_by_no = array();
        foreach ( $merit_data as $inv ) {
            if ( isset($inv['InvoiceNo']) ) {
                $merit_by_no[ (string) $inv['InvoiceNo'] ] = $inv;
            }
        }

        // WooCommerce tellimused millel on _merit_invoice_sent_at
        $wc_orders = wc_get_orders(array('limit' => -1));
        $wc_sent   = array();
        foreach ( $wc_orders as $order ) {
            if ( $order->get_meta('_merit_invoice_sent_at') ) {
                $wc_sent[ (string) $order->get_id() ] = $order;
            }
        }

        // Puudub Meritis — WC märgib saadetuna aga Merit ei tea sellest
        $missing_in_merit = array();
        foreach ( $wc_sent as $order_id => $order ) {
            if ( !isset($merit_by_no[$order_id]) ) {
                $missing_in_merit[] = array(
                    'order_id' => (int) $order_id,
                    'sent_at'  => $order->get_meta('_merit_invoice_sent_at'),
                    'total'    => $order->get_total(),
                    'customer' => $order->get_formatted_billing_full_name(),
                    'status'   => $order->get_status(),
                );
            }
        }

        // Puudub WooCommerce-ist — Merit Aktivas on arve aga WC-l pole "saadetud" märget
        $missing_in_wc = array();
        foreach ( $merit_by_no as $invoice_no => $inv ) {
            if ( !is_numeric($invoice_no) ) continue;
            if ( isset($wc_sent[$invoice_no]) ) continue;

            $wc_order = wc_get_order((int) $invoice_no);
            $missing_in_wc[] = array(
                'invoice_no' => $invoice_no,
                'total'      => $inv['TotalAmount'] ?? '',
                'exists_in_wc' => (bool) $wc_order,
                'wc_status'    => $wc_order ? $wc_order->get_status() : null,
                'customer'     => $wc_order ? $wc_order->get_formatted_billing_full_name() : 'Käsitsi loodud',
            );
        }

        wp_send_json_success(array(
            'missing_in_merit' => $missing_in_merit,
            'missing_in_wc'    => $missing_in_wc,
            'merit_total'      => count($merit_by_no),
            'wc_total'         => count($wc_sent),
        ));
    }

    /**
     * Märgib tellimuse Merit Aktivas saadetuna ilma API päringuta.
     * Kasutatakse kui arve on Meritis olemas aga WC-l puudub märge.
     */
    public function ajax_mark_as_sent() {
        check_ajax_referer('merit_reconcile_nonce', 'nonce');
        if ( !current_user_can('manage_woocommerce') ) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;
        if ( !$order ) {
            wp_send_json_error('Tellimust ei leitud');
            return;
        }
        $order->update_meta_data('_merit_invoice_sent_at', current_time('mysql'));
        $order->add_order_note('Merit Aktiva: märgitud käsitsi saadetuna (arve-võrdluse kaudu).');
        $order->save();
        wp_send_json_success('Märgitud saadetuna');
    }

    public function validate_payment_deadline( $input ) {
        $value = absint($input);
        if ( $value < 1 ) {
            add_settings_error('payment_dead_line', 'invalid', 'Maksetähtaeg peab olema vähemalt 1 päev.', 'error');
            return get_option('payment_dead_line', 14);
        }
        return $value;
    }

    public function validate_tax_field( $input ) {
        $value = sanitize_text_field($input);
        if ( empty($value) ) {
            add_settings_error('tax_select_field', 'empty', 'Käibemaksu tüüp on valimata.', 'error');
            return get_option('tax_select_field');
        }
        return $value;
    }

    public function validate_payment_status( $input ) {
        $value = sanitize_text_field($input);
        $risky = array('cancelled', 'failed', 'refunded');
        if ( in_array($value, $risky, true) ) {
            add_settings_error('payment_status_select_field', 'risky',
                'Hoiatus: arve saatmine "' . esc_html($value) . '" staatusel on ebatavaline. Tavaliselt kasutatakse "completed".',
                'warning');
        }
        return $value;
    }
}

new Merit_Aktiva_Settings();
