(function($) {
    'use strict';

    // ── Log viewer ──
    var logPage = 1;

    function loadLogs(page) {
        logPage = page || 1;
        $.post(rps_ajax.ajax_url, {
            action: 'rps_get_logs',
            nonce: rps_ajax.nonce,
            level: $('#rps-log-level').val(),
            search: $('#rps-log-search').val(),
            product_id: $('#rps-log-product-id').val(),
            date_from: $('#rps-log-date-from').val(),
            date_to: $('#rps-log-date-to').val(),
            per_page: 50,
            page: logPage
        }, function(resp) {
            if (!resp.success) return;
            var data = resp.data;
            var tbody = $('#rps-log-tbody');
            tbody.empty();

            if (!data.items.length) {
                tbody.append('<tr><td colspan="7">Nessun log trovato.</td></tr>');
                return;
            }

            $.each(data.items, function(i, row) {
                var levelColors = { error: '#dc3232', warning: '#dba617', info: '#0073aa', debug: '#6c757d' };
                var color = levelColors[row.level] || '#333';
                var hasData = row.request_data || row.response_data;
                var toggleBtn = hasData ? '<button type="button" class="button button-small rps-toggle-context" data-target="rps-ctx-' + row.id + '">&darr;</button>' : '';

                tbody.append(
                    '<tr>' +
                    '<td><code style="font-size:12px">' + $('<div>').text(row.timestamp).html() + '</code></td>' +
                    '<td><span style="background:' + color + ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600">' + row.level.toUpperCase() + '</span></td>' +
                    '<td><code>' + $('<div>').text(row.context || '').html() + '</code></td>' +
                    '<td>' + $('<div>').text(row.message).html().replace(/(https?:\/\/[^\s&<]+)/g, '<a href="$1" target="_blank" style="font-size:11px">$1</a>') + '</td>' +
                    '<td>' + $('<div>').text(row.product_id || '-').html() + '</td>' +
                    '<td style="font-size:11px">' + $('<div>').text(row.store_url || '-').html() + '</td>' +
                    '<td>' + $('<div>').text(row.user_id || '-').html() + '</td>' +
                    '<td>' + toggleBtn + '</td>' +
                    '</tr>'
                );

                // Riga espandibile con request/response JSON
                if (hasData) {
                    var reqHtml = '', resHtml = '';
                    if (row.request_data) {
                        try { reqHtml = JSON.stringify(JSON.parse(row.request_data), null, 2); } catch(e) { reqHtml = row.request_data; }
                    }
                    if (row.response_data) {
                        try { resHtml = JSON.stringify(JSON.parse(row.response_data), null, 2); } catch(e) { resHtml = row.response_data; }
                    }
                    var details = '';
                    if (reqHtml) details += '<strong>Request:</strong><pre style="background:#f5f5f5;padding:10px;overflow-x:auto;font-size:11px;border-radius:4px;max-height:300px">' + $('<div>').text(reqHtml).html() + '</pre>';
                    if (resHtml) details += '<strong>Response:</strong><pre style="background:#f5f5f5;padding:10px;overflow-x:auto;font-size:11px;border-radius:4px;max-height:300px">' + $('<div>').text(resHtml).html() + '</pre>';

                    tbody.append('<tr id="rps-ctx-' + row.id + '" style="display:none"><td colspan="8">' + details + '</td></tr>');
                }
            });

            // Pagination
            var nav = $('#rps-log-pagination .tablenav-pages');
            nav.empty();
            if (data.pages > 1) {
                for (var p = 1; p <= data.pages; p++) {
                    if (p === data.page) {
                        nav.append('<span class="page-numbers current">' + p + '</span> ');
                    } else {
                        nav.append('<a href="#" class="page-numbers" data-page="' + p + '">' + p + '</a> ');
                    }
                }
            }
        });
    }

    // Toggle context rows
    $(document).on('click', '.rps-toggle-context', function() {
        var targetId = $(this).data('target');
        var $row = $('#' + targetId);
        var isVisible = $row.is(':visible');
        $row.toggle();
        $(this).text(isVisible ? '\u2193' : '\u2191');
    });

    $(document).on('click', '#rps-log-pagination a.page-numbers', function(e) {
        e.preventDefault();
        loadLogs($(this).data('page'));
    });

    $(document).on('click', '#rps-log-filter-btn', function() { loadLogs(1); });
    $(document).on('click', '#rps-log-clear-btn', function() {
        if (!confirm('Sei sicuro di voler svuotare tutti i log?')) return;
        $.post(rps_ajax.ajax_url, { action: 'rps_clear_logs', nonce: rps_ajax.nonce }, function() { loadLogs(1); });
    });
    $(document).on('click', '#rps-log-export-btn', function(e) {
        e.preventDefault();
        window.location = rps_ajax.ajax_url + '?action=rps_export_logs&nonce=' + rps_ajax.nonce +
            '&level=' + $('#rps-log-level').val();
    });

    // Auto-load logs if on log page, con pre-filtro da URL
    if ($('#rps-log-table').length) {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('level')) {
            $('#rps-log-level').val(urlParams.get('level'));
        }
        loadLogs(1);
    }

    // ── Batch progress polling ──
    function pollBatch(batchId) {
        var $container = $('#rps-batch-progress');
        $container.show();

        var interval = setInterval(function() {
            $.post(rps_ajax.ajax_url, {
                action: 'rps_batch_status',
                nonce: rps_ajax.nonce,
                batch_id: batchId
            }, function(resp) {
                if (!resp.success) { clearInterval(interval); return; }
                var d = resp.data;
                var done = d.completed + d.failed;
                var pct = d.total > 0 ? Math.round((done / d.total) * 100) : 0;

                $('#rps-progress-bar').css('width', pct + '%');
                $('#rps-progress-text').text(done + ' / ' + d.total + ' completati (' + pct + '%)');

                if (d.failed > 0) {
                    $('#rps-progress-errors').text(d.failed + ' errori');
                }

                if (d.status === 'completed' || d.status === 'cancelled') {
                    clearInterval(interval);
                    var msg = d.status === 'completed' ? 'Sync completato!' : 'Sync annullato.';
                    $('#rps-progress-text').text(msg + ' ' + d.completed + ' ok, ' + d.failed + ' errori su ' + d.total);
                    $('#rps-cancel-batch').hide();

                    // Riepilogo con link al Log
                    var logUrl = rps_ajax.log_page_url || (window.location.origin + '/wp-admin/admin.php?page=wc_api_mps_sync_log');
                    var summary = '<div style="margin-top:12px;">';
                    summary += '<a href="' + logUrl + '" class="button">Vedi Log completo</a>';
                    if (d.failed > 0) {
                        summary += ' <a href="' + logUrl + '&level=error" class="button" style="color:#dc3232;">Vedi ' + d.failed + ' errori</a>';
                    }
                    summary += '</div>';
                    $('#rps-batch-summary').html(summary);
                }
            });
        }, 3000);

        $('#rps-cancel-batch').off('click').on('click', function() {
            $.post(rps_ajax.ajax_url, { action: 'rps_cancel_batch', nonce: rps_ajax.nonce, batch_id: batchId });
        });
    }

    // Check for active batches on page load
    if ($('#rps-batch-progress').length) {
        $.post(rps_ajax.ajax_url, { action: 'rps_get_active_batches', nonce: rps_ajax.nonce }, function(resp) {
            if (resp.success && resp.data.length) {
                var latest = resp.data[0];
                if (latest.status === 'running') {
                    pollBatch(latest.id);
                }
            }
        });
    }

    // ── Select all checkboxes ──
    $('.rps-check-all').on('change', function() {
        var checked = $(this).prop('checked');
        $('.rps-sites input[type="checkbox"]').prop('checked', checked);
    });

    // ── Force Sync ──
    $('#rps-force-sync-count-btn').on('click', function() {
        var store = $('#rps-force-sync-store').val();
        var mode = $('input[name="rps_force_mode"]:checked').val();
        $(this).prop('disabled', true).text('Conteggio...');
        $.post(rps_ajax.ajax_url, {
            action: 'rps_force_sync_count',
            nonce: rps_ajax.nonce,
            store_url: store,
            mode: mode
        }, function(resp) {
            $('#rps-force-sync-count-btn').prop('disabled', false).text('Conta prodotti');
            if (resp.success) {
                var count = resp.data.count;
                $('#rps-force-sync-count').text(count + ' prodotti');
                $('#rps-force-sync-start').prop('disabled', count === 0);
            }
        });
    });

    $('#rps-force-sync-start').on('click', function() {
        var mode = $('input[name="rps_force_mode"]:checked').val();
        var modeLabel = mode === 'all' ? 'TUTTI i prodotti (anche già sincronizzati)' : 'solo i prodotti non ancora sincronizzati';
        if (!confirm('Avviare il sync di ' + modeLabel + '?')) return;
        var store = $('#rps-force-sync-store').val();
        $(this).prop('disabled', true);
        $.post(rps_ajax.ajax_url, {
            action: 'rps_force_sync_start',
            nonce: rps_ajax.nonce,
            store_url: store,
            mode: mode
        }, function(resp) {
            if (resp.success) {
                pollBatch(resp.data.batch_id);
            } else {
                alert(resp.data || 'Errore');
                $('#rps-force-sync-start').prop('disabled', false);
            }
        });
    });

    // Reset count when store or mode changes
    $('#rps-force-sync-store, input[name="rps_force_mode"]').on('change', function() {
        $('#rps-force-sync-count').text('');
        $('#rps-force-sync-start').prop('disabled', true);
    });

    // ── Sync All Filtered ──
    $('#rps-sync-all-filtered').on('click', function() {
        var total = $(this).data('total');
        var stores = [];
        $('.rps-sites input[type="checkbox"]:checked').each(function() { stores.push($(this).val()); });
        var msg = stores.length
            ? 'Sync in background di ' + total + ' prodotti verso ' + stores.length + ' store selezionati.'
            : 'Sync in background di ' + total + ' prodotti verso gli store già configurati per ciascun prodotto (campo ACF).';
        if (!confirm(msg + '\n\nContinuare?')) return;

        // Raccogli i parametri filtro dal form
        var filterForm = $(this).closest('form').prev().find('form');
        $(this).prop('disabled', true);

        $.post(rps_ajax.ajax_url, {
            action: 'rps_sync_all_filtered',
            nonce: rps_ajax.nonce,
            stores: stores,
            s: $('input[name="s"]').val() || '',
            product_cat: $('select[name="product_cat"]').val() || 0,
            product_brand: $('select[name="product_brand"]').val() || 0,
            product_tag: $('select[name="product_tag"]').val() || 0,
            sync_status: $('input[name="wc_api_mps_status"]:checked').val() || '',
            store_filter: (function() { var sf = []; $('input[name="wc_api_mps_store[]"]:checked').each(function(){ sf.push($(this).val()); }); return sf; })()
        }, function(resp) {
            if (resp.success) {
                pollBatch(resp.data.batch_id);
            } else {
                alert(resp.data || 'Errore');
                $('#rps-sync-all-filtered').prop('disabled', false);
            }
        });
    });

    // ── URL Migration ──
    $('#rps-migrate-btn').on('click', function() {
        var oldUrl = $('#rps-migrate-old-url').val().replace(/\/+$/, '');
        var newUrl = $('#rps-migrate-new-url').val().replace(/\/+$/, '');
        if (!oldUrl || !newUrl) {
            alert('Inserisci entrambi gli URL');
            return;
        }
        if (oldUrl === newUrl) {
            alert('Gli URL sono identici');
            return;
        }
        if (!confirm('Attenzione: questa operazione modificherà i dati in wp_options, wp_postmeta e wp_termmeta.\n\nMigrare da:\n' + oldUrl + '\na:\n' + newUrl + '\n\nContinuare?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Migrazione in corso...');
        $('#rps-migrate-result').html('<span style="color:#666;">Attendere, potrebbe richiedere qualche secondo...</span>');

        $.ajax({
            url: rps_ajax.ajax_url,
            type: 'POST',
            timeout: 120000,
            data: {
                action: 'rps_migrate_urls',
                nonce: rps_ajax.nonce,
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(resp) {
                $btn.prop('disabled', false).text('Esegui Migrazione');
                if (resp.success) {
                    var html = '<div class="notice notice-success inline" style="padding:8px 12px;"><strong>Migrazione completata:</strong><ul style="margin:5px 0 0 20px;">';
                    resp.data.changes.forEach(function(c) { html += '<li>' + $('<div>').text(c).html() + '</li>'; });
                    html += '</ul></div>';
                    $('#rps-migrate-result').html(html);
                } else {
                    $('#rps-migrate-result').html('<div class="notice notice-error inline" style="padding:8px 12px;">' + $('<div>').text(resp.data || 'Errore sconosciuto').html() + '</div>');
                }
            },
            error: function(xhr, status) {
                $btn.prop('disabled', false).text('Esegui Migrazione');
                var msg = status === 'timeout' ? 'Timeout: la migrazione ha impiegato troppo. Controlla il Log per verificare se è stata completata.' : 'Errore di connessione (' + status + '). Controlla il Log.';
                $('#rps-migrate-result').html('<div class="notice notice-warning inline" style="padding:8px 12px;">' + msg + ' <a href="' + rps_ajax.log_page_url + '">Vai al Log</a></div>');
            }
        });
    });

    // ── Test Suite ──
    $('#rps-test-run').on('click', function() {
        var store = $('#rps-test-store').val();
        if (!confirm('Eseguire la test suite su ' + store + '?\n\nVerranno creati prodotti di test temporanei che saranno eliminati al termine.')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Test in corso...');
        var $results = $('#rps-test-results');
        $results.html('<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>Esecuzione test in corso, attendere...</p>');

        $.ajax({
            url: rps_ajax.ajax_url,
            type: 'POST',
            timeout: 300000,
            data: {
                action: 'rps_run_tests',
                nonce: rps_ajax.nonce,
                store_url: store
            },
            success: function(resp) {
                $btn.prop('disabled', false).text('Esegui Test Suite');
                if (!resp.success) {
                    $results.html('<div class="notice notice-error"><p>' + (resp.data || 'Errore') + '</p></div>');
                    return;
                }

                var html = '<table class="widefat striped"><thead><tr><th style="width:30px">#</th><th style="width:40px">Esito</th><th style="width:250px">Test</th><th>Dettagli</th></tr></thead><tbody>';
                var pass = 0, fail = 0, warn = 0;

                $.each(resp.data.results, function(i, r) {
                    var icon = r.status === 'pass' ? '<span style="color:#46b450;">&#10004;</span>' :
                               r.status === 'fail' ? '<span style="color:#dc3232;">&#10008;</span>' :
                               r.status === 'warn' ? '<span style="color:#dba617;">&#9888;</span>' :
                               '<span style="color:#999;">&#8212;</span>';
                    if (r.status === 'pass') pass++;
                    else if (r.status === 'fail') fail++;
                    else if (r.status === 'warn') warn++;

                    html += '<tr><td>' + (i+1) + '</td><td>' + icon + '</td>';
                    html += '<td><strong>' + $('<div>').text(r.name).html() + '</strong></td>';
                    html += '<td>' + $('<div>').text(r.message).html() + '</td></tr>';
                });

                html += '</tbody></table>';

                var summary = '<div style="margin:15px 0;padding:12px 15px;border-radius:4px;' +
                    (fail > 0 ? 'background:#fbeaea;border:1px solid #dc3232;' : 'background:#ecf7ed;border:1px solid #46b450;') + '">';
                summary += '<strong>' + pass + ' passati</strong>';
                if (warn > 0) summary += ', <strong style="color:#dba617;">' + warn + ' warning</strong>';
                if (fail > 0) summary += ', <strong style="color:#dc3232;">' + fail + ' falliti</strong>';
                summary += '</div>';

                $results.html(summary + html);
            },
            error: function(xhr, status) {
                $btn.prop('disabled', false).text('Esegui Test Suite');
                $results.html('<div class="notice notice-error"><p>Errore: ' + status + '. I test potrebbero aver creato dati residui. Usa "Pulizia di emergenza".</p></div>');
            }
        });
    });

    $('#rps-test-cleanup').on('click', function() {
        if (!confirm('Rimuovere tutti i prodotti/categorie con prefisso "[RPS TEST]"?')) return;
        $(this).prop('disabled', true);
        $.post(rps_ajax.ajax_url, { action: 'rps_cleanup_tests', nonce: rps_ajax.nonce }, function(resp) {
            $('#rps-test-cleanup').prop('disabled', false);
            if (resp.success) {
                alert('Pulizia completata: ' + resp.data.cleaned + ' elementi rimossi.');
            }
        });
    });

})(jQuery);
