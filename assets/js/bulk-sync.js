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
                var levelClass = row.level === 'error' ? 'style="color:#dc3232;font-weight:bold"' :
                                 row.level === 'warning' ? 'style="color:#dba617;font-weight:bold"' : '';
                tbody.append(
                    '<tr>' +
                    '<td>' + row.timestamp + '</td>' +
                    '<td ' + levelClass + '>' + row.level.toUpperCase() + '</td>' +
                    '<td>' + (row.context || '') + '</td>' +
                    '<td>' + row.message + '</td>' +
                    '<td>' + (row.product_id || '-') + '</td>' +
                    '<td style="font-size:11px">' + (row.store_url || '-') + '</td>' +
                    '<td>' + (row.user_id || '-') + '</td>' +
                    '</tr>'
                );
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
