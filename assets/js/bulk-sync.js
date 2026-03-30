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
                    '<td><code style="font-size:12px">' + row.timestamp + '</code></td>' +
                    '<td><span style="background:' + color + ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600">' + row.level.toUpperCase() + '</span></td>' +
                    '<td><code>' + (row.context || '') + '</code></td>' +
                    '<td>' + row.message + '</td>' +
                    '<td>' + (row.product_id || '-') + '</td>' +
                    '<td style="font-size:11px">' + (row.store_url || '-') + '</td>' +
                    '<td>' + (row.user_id || '-') + '</td>' +
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

    // Auto-load logs if on log page
    if ($('#rps-log-table').length) {
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

})(jQuery);
