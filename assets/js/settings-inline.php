<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
(function($){
                var nonce = '<?php echo esc_js($nonce_val); ?>';
                var ajaxUrl = '<?php echo esc_js($ajax_url); ?>';

                // ── CSV Import ──────────────────────────────────
                $('#folderify-import-csv-btn').on('click', function () {
                    var file = document.getElementById('folderify-import-csv-file').files[0];
                    if (!file) { alert('<?php esc_html_e('Please choose a CSV file first.', 'folderify'); ?>'); return; }
                    var mode = $('#folderify-import-csv-mode').val();
                    var fd = new FormData();
                    fd.append('action', 'folderify_import_csv');
                    fd.append('nonce', nonce);
                    fd.append('import_mode', mode);
                    fd.append('csv_file', file);
                    var $s = $('#folderify-import-csv-status').text('Importing…').removeClass('ok err').show();
                    $.ajax({
                        url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false,
                        success: function (r) {
                            $s.addClass(r.success ? 'ok' : 'err')
                                .text(r.success ? '✓ Imported ' + r.data.imported + ' folders.' : '✗ ' + r.data);
                        },
                        error: function () { $s.addClass('err').text('Request failed. Please try again.'); }
                    });
                });

                // ── Load backups ────────────────────────────────
                $('#folderify-load-backups-btn').on('click', function () {
                    var $btn = $(this).text('Loading…').prop('disabled', true);
                    $.post(ajaxUrl, { action: 'folderify_list_backups', nonce: nonce }, function (r) {
                        $btn.text('<?php esc_html_e('Load backups', 'folderify'); ?>').prop('disabled', false);
                        var $list = $('#folderify-backup-list');
                        if (!r.success || !r.data.length) {
                            $list.html('<p class="folderify-backup-empty"><?php esc_html_e('No backups found yet. Backups are created automatically every day.', 'folderify'); ?></p>');
                            return;
                        }
                        var html = '<table class="folderify-backup-table"><thead><tr>'
                            + '<th><?php esc_html_e('File', 'folderify'); ?></th>'
                            + '<th><?php esc_html_e('Date', 'folderify'); ?></th>'
                            + '<th><?php esc_html_e('Size', 'folderify'); ?></th>'
                            + '<th></th></tr></thead><tbody>';
                        $.each(r.data, function (i, b) {
                            html += '<tr>'
                                + '<td class="user-col" title="' + b.filename + '">' + b.display_name + '</td>'
                                + '<td style="color:#646970;">' + b.date + '</td>'
                                + '<td style="color:#646970;">' + b.size + '</td>'
                                + '<td><button class="button button-small folderify-restore-btn" data-file="' + b.filename + '"><?php esc_html_e('Restore', 'folderify'); ?></button></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        $list.html(html);
                    });
                });

                // ── Restore backup ──────────────────────────────
                $(document).on('click', '.folderify-restore-btn', function () {
                    if (!confirm('<?php esc_html_e('Restore this backup? The current folders for that user will be replaced.', 'folderify'); ?>')) return;
                    var file = $(this).data('file');
                    var $btn = $(this).text('…').prop('disabled', true);
                    $.post(ajaxUrl, { action: 'folderify_restore_backup', nonce: nonce, filename: file }, function (r) {
                        $btn.text('<?php esc_html_e('Restore', 'folderify'); ?>').prop('disabled', false);
                        alert(r.success ? '✓ Restored ' + r.data.restored + ' folders.' : '✗ ' + r.data);
                    });
                });
            }) (jQuery);