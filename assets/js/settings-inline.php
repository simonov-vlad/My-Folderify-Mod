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

                // ── Uploads sync (scan / apply) ─────────────────
                var syncI18n = {
                    working: '<?php echo esc_js(__('Analyzing…', 'folderify')); ?>',
                    failed: '<?php echo esc_js(__('Request failed. Please try again.', 'folderify')); ?>',
                    insync: '<?php echo esc_js(__('Everything is already in sync. Nothing to do.', 'folderify')); ?>',
                    start: '<?php echo esc_js(__('Start sync', 'folderify')); ?>',
                    cancel: '<?php echo esc_js(__('Cancel', 'folderify')); ?>',
                    running: '<?php echo esc_js(__('Syncing…', 'folderify')); ?>',
                    finished: '<?php echo esc_js(__('Done.', 'folderify')); ?>',
                    newFiles: '<?php echo esc_js(__('new files to add to the Media Library', 'folderify')); ?>',
                    newFolders: '<?php echo esc_js(__('new folders to create', 'folderify')); ?>',
                    linkFiles: '<?php echo esc_js(__('existing files to link to folders', 'folderify')); ?>',
                    toMove: '<?php echo esc_js(__('files to move into their folder directories', 'folderify')); ?>',
                    folders: '<?php echo esc_js(__('folder directories to check', 'folderify')); ?>',
                    registered: '<?php echo esc_js(__('files added', 'folderify')); ?>',
                    createdFolders: '<?php echo esc_js(__('folders created', 'folderify')); ?>',
                    linked: '<?php echo esc_js(__('files linked', 'folderify')); ?>',
                    moved: '<?php echo esc_js(__('files moved', 'folderify')); ?>',
                    errors: '<?php echo esc_js(__('errors', 'folderify')); ?>'
                };

                function syncEsc(s) { return $('<div>').text(s).html(); }

                function syncMessage($res, kind, html) {
                    $res.removeClass('ok err').addClass(kind || '').html(html).show();
                }

                function syncDoneText(d) {
                    var parts = [];
                    if (d.registered) parts.push(d.registered + ' ' + syncI18n.registered);
                    if (d.folders) parts.push(d.folders + ' ' + syncI18n.createdFolders);
                    if (d.linked) parts.push(d.linked + ' ' + syncI18n.linked);
                    if (d.moved) parts.push(d.moved + ' ' + syncI18n.moved);
                    if (d.errors && d.errors.length) parts.push(d.errors.length + ' ' + syncI18n.errors);
                    return parts.join(' · ');
                }

                function syncRunSteps($card, $res, mode) {
                    var html = '<div class="folderify-sync-bar"><span style="width:0%"></span></div>'
                        + '<div class="folderify-sync-line">' + syncI18n.running + '</div>';
                    syncMessage($res, '', html);

                    (function step() {
                        $.post(ajaxUrl, { action: 'folderify_sync_step', nonce: nonce }, function (r) {
                            if (!r.success) {
                                syncMessage($res, 'err', '✗ ' + syncEsc(r.data));
                                return;
                            }
                            var d = r.data, pct = d.total ? Math.round(d.processed / d.total * 100) : 100;
                            $res.find('.folderify-sync-bar span').css('width', pct + '%');
                            $res.find('.folderify-sync-line').text(pct + '% (' + d.processed + ' / ' + d.total + ') — ' + syncDoneText(d.done));
                            if (!d.finished) { step(); return; }

                            var out = '✓ ' + syncI18n.finished + ' ' + syncEsc(syncDoneText(d.done));
                            if (d.done.errors && d.done.errors.length) {
                                out += '<details style="margin-top:8px;"><summary>' + d.done.errors.length + ' ' + syncI18n.errors + '</summary><ul style="margin:6px 0 0 18px;list-style:disc;">';
                                $.each(d.done.errors, function (i, e) { out += '<li>' + syncEsc(e) + '</li>'; });
                                out += '</ul></details>';
                            }
                            syncMessage($res, d.done.errors && d.done.errors.length ? 'err' : 'ok', out);
                        }).fail(function () { syncMessage($res, 'err', syncI18n.failed); });
                    })();
                }

                $(document).on('click', '.folderify-sync-analyze', function () {
                    var $btn = $(this), mode = $btn.data('mode');
                    var $card = $btn.closest('.folderify-card'), $res = $card.find('.folderify-sync-result');
                    $btn.prop('disabled', true);
                    syncMessage($res, '', syncI18n.working);

                    $.post(ajaxUrl, {
                        action: 'folderify_sync_start',
                        nonce: nonce,
                        mode: mode,
                        include_date_dirs: $card.find('.folderify-sync-date-dirs').is(':checked') ? 1 : 0,
                        replace_urls: $card.find('.folderify-sync-replace-urls').length
                            ? ($card.find('.folderify-sync-replace-urls').is(':checked') ? 1 : 0) : 1
                    }, function (r) {
                        $btn.prop('disabled', false);
                        if (!r.success) { syncMessage($res, 'err', '✗ ' + syncEsc(r.data)); return; }

                        var s = r.data.summary, lines = [];
                        if (mode === 'scan') {
                            if (s.new_files) lines.push('<strong>' + s.new_files + '</strong> ' + syncI18n.newFiles);
                            if (s.new_folders) lines.push('<strong>' + s.new_folders + '</strong> ' + syncI18n.newFolders);
                            if (s.link_files) lines.push('<strong>' + s.link_files + '</strong> ' + syncI18n.linkFiles);
                        } else {
                            if (s.folders) lines.push('<strong>' + s.folders + '</strong> ' + syncI18n.folders);
                            if (s.to_move) lines.push('<strong>' + s.to_move + '</strong> ' + syncI18n.toMove);
                        }

                        var nothing = (mode === 'scan') ? (!s.new_files && !s.new_folders && !s.link_files) : !s.to_move && !s.folders;
                        if (nothing || !r.data.total) { syncMessage($res, 'ok', syncI18n.insync); return; }

                        var html = '<ul style="margin:0 0 10px 18px;list-style:disc;"><li>' + lines.join('</li><li>') + '</li></ul>'
                            + '<button type="button" class="button button-primary folderify-sync-go">' + syncI18n.start + '</button> '
                            + '<button type="button" class="button folderify-sync-cancel">' + syncI18n.cancel + '</button>';
                        syncMessage($res, '', html);
                        $res.data('mode', mode);
                    }).fail(function () {
                        $btn.prop('disabled', false);
                        syncMessage($res, 'err', syncI18n.failed);
                    });
                });

                $(document).on('click', '.folderify-sync-go', function () {
                    var $card = $(this).closest('.folderify-card'), $res = $card.find('.folderify-sync-result');
                    syncRunSteps($card, $res, $res.data('mode'));
                });

                $(document).on('click', '.folderify-sync-cancel', function () {
                    var $res = $(this).closest('.folderify-sync-result');
                    $.post(ajaxUrl, { action: 'folderify_sync_cancel', nonce: nonce });
                    $res.hide().empty();
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