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
                var T = {
                    analyzing: '<?php echo esc_js(__('Scanning uploads and analyzing files and folders…', 'folderify')); ?>',
                    analyzed: '<?php echo esc_js(__('Analysis complete', 'folderify')); ?>',
                    syncDone: '<?php echo esc_js(__('Sync complete', 'folderify')); ?>',
                    syncing: '<?php echo esc_js(__('Syncing…', 'folderify')); ?>',
                    failed: '<?php echo esc_js(__('Request failed. Please try again.', 'folderify')); ?>',
                    insync: '<?php echo esc_js(__('Everything is already in sync. Nothing to add.', 'folderify')); ?>',
                    start: '<?php echo esc_js(__('Start sync', 'folderify')); ?>',
                    cancel: '<?php echo esc_js(__('Cancel', 'folderify')); ?>',
                    scannedAt: '<?php echo esc_js(__('Finished at', 'folderify')); ?>',
                    found: '<?php echo esc_js(__('Found in uploads', 'folderify')); ?>',
                    foldersWord: '<?php echo esc_js(__('folders', 'folderify')); ?>',
                    filesWord: '<?php echo esc_js(__('files', 'folderify')); ?>',
                    inLibrary: '<?php echo esc_js(__('already in the Media Library', 'folderify')); ?>',
                    skippedGen: '<?php echo esc_js(__('thumbnails / generated sizes (ignored)', 'folderify')); ?>',
                    skippedType: '<?php echo esc_js(__('files of unsupported types (ignored)', 'folderify')); ?>',
                    willAdd: '<?php echo esc_js(__('To do', 'folderify')); ?>',
                    newFiles: '<?php echo esc_js(__('new files to add to the Media Library', 'folderify')); ?>',
                    newFolders: '<?php echo esc_js(__('new folders to create', 'folderify')); ?>',
                    linkFiles: '<?php echo esc_js(__('existing files to link to folders', 'folderify')); ?>',
                    renameDirs: '<?php echo esc_js(__('folders will be renamed to Latin on disk (display names stay Russian)', 'folderify')); ?>',
                    renameFiles: '<?php echo esc_js(__('files will be renamed to Latin (titles stay Russian, links in posts are updated)', 'folderify')); ?>',
                    renamedFiles: '<?php echo esc_js(__('files renamed to Latin', 'folderify')); ?>',
                    renamedDirs: '<?php echo esc_js(__('directories renamed to Latin', 'folderify')); ?>',
                    toMove: '<?php echo esc_js(__('files to move into their folder directories', 'folderify')); ?>',
                    dirsCheck: '<?php echo esc_js(__('folder directories to check', 'folderify')); ?>',
                    registered: '<?php echo esc_js(__('files added to the Media Library', 'folderify')); ?>',
                    createdFolders: '<?php echo esc_js(__('folders created', 'folderify')); ?>',
                    linked: '<?php echo esc_js(__('files linked to folders', 'folderify')); ?>',
                    moved: '<?php echo esc_js(__('files moved', 'folderify')); ?>',
                    errors: '<?php echo esc_js(__('errors', 'folderify')); ?>',
                    openMedia: '<?php echo esc_js(__('Open Media Library', 'folderify')); ?>',
                    mediaUrl: '<?php echo esc_js(admin_url('upload.php')); ?>'
                };

                function esc(s) { return $('<div>').text(String(s)).html(); }

                function box($res, kind, inner) {
                    $res.removeClass('ok err busy').addClass(kind || '').html(inner).show();
                }

                function head(kind, title, time) {
                    var icon = kind === 'busy' ? '<span class="folderify-spinner"></span>' : (kind === 'err' ? '<span class="folderify-sync-icon">!</span>' : '<span class="folderify-sync-icon">✓</span>');
                    return '<div class="folderify-sync-head">' + icon + '<strong>' + esc(title) + '</strong>'
                        + (time ? '<span class="folderify-sync-time">' + esc(T.scannedAt) + ' ' + esc(time) + '</span>' : '') + '</div>';
                }

                function li(n, text) { return n ? '<li><strong>' + n + '</strong> ' + esc(text) + '</li>' : ''; }

                function doneList(d) {
                    var h = '<ul class="folderify-sync-list">'
                        + li(d.registered, T.registered) + li(d.folders, T.createdFolders)
                        + li(d.linked, T.linked) + li(d.renamed, T.renamedFiles) + li(d.renamed_dirs, T.renamedDirs)
                        + li(d.moved, T.moved) + '</ul>';
                    if (d.errors && d.errors.length) {
                        h += '<details class="folderify-sync-errors" open><summary>' + d.errors.length + ' ' + esc(T.errors) + '</summary><ul>';
                        $.each(d.errors, function (i, e) { h += '<li>' + esc(e) + '</li>'; });
                        h += '</ul></details>';
                    }
                    return h;
                }

                function runSteps($res, mode) {
                    box($res, 'busy', head('busy', T.syncing)
                        + '<div class="folderify-sync-bar"><span style="width:0%"></span></div>'
                        + '<div class="folderify-sync-line">0%</div>');

                    (function step() {
                        $.post(ajaxUrl, { action: 'folderify_sync_step', nonce: nonce }, function (r) {
                            if (!r.success) { box($res, 'err', head('err', T.failed) + '<div>' + esc(r.data) + '</div>'); return; }
                            var d = r.data, pct = d.total ? Math.round(d.processed / d.total * 100) : 100;
                            $res.find('.folderify-sync-bar span').css('width', pct + '%');
                            $res.find('.folderify-sync-line').text(pct + '%  (' + d.processed + ' / ' + d.total + ')');
                            if (!d.finished) { step(); return; }

                            var hasErr = d.done.errors && d.done.errors.length;
                            var extra = (mode === 'scan' && d.done.registered)
                                ? '<p><a class="button" href="' + esc(T.mediaUrl) + '">' + esc(T.openMedia) + '</a></p>' : '';
                            box($res, hasErr ? 'err' : 'ok', head(hasErr ? 'err' : 'ok', T.syncDone, d.time) + doneList(d.done) + extra);
                        }).fail(function () { box($res, 'err', head('err', T.failed)); });
                    })();
                }

                $(document).on('click', '.folderify-sync-analyze', function () {
                    var $btn = $(this), mode = $btn.data('mode');
                    var $card = $btn.closest('.folderify-card'), $res = $card.find('.folderify-sync-result');
                    $btn.prop('disabled', true);
                    box($res, 'busy', head('busy', T.analyzing));

                    $.post(ajaxUrl, {
                        action: 'folderify_sync_start',
                        nonce: nonce,
                        mode: mode,
                        include_date_dirs: $card.find('.folderify-sync-date-dirs').is(':checked') ? 1 : 0,
                        transliterate: $card.find('.folderify-sync-translit').is(':checked') ? 1 : 0,
                        replace_urls: $card.find('.folderify-sync-replace-urls').length
                            ? ($card.find('.folderify-sync-replace-urls').is(':checked') ? 1 : 0) : 1
                    }, function (r) {
                        $btn.prop('disabled', false);
                        if (!r.success) { box($res, 'err', head('err', T.failed) + '<div>' + esc(r.data) + '</div>'); return; }

                        var s = r.data.summary, found = '', todo = '';
                        if (mode === 'scan') {
                            found = '<p class="folderify-sync-sub">' + esc(T.found) + ': <strong>' + s.dirs_found + '</strong> ' + esc(T.foldersWord)
                                + ', <strong>' + s.files_found + '</strong> ' + esc(T.filesWord) + '</p>'
                                + '<ul class="folderify-sync-list muted">' + li(s.already_in_library, T.inLibrary)
                                + li(s.skipped_generated, T.skippedGen) + li(s.skipped_unsupported, T.skippedType) + '</ul>';
                            todo = li(s.new_files, T.newFiles) + li(s.new_folders, T.newFolders) + li(s.link_files, T.linkFiles)
                                + li(s.rename_dirs, T.renameDirs) + li(s.rename_files, T.renameFiles);
                        } else {
                            todo = li(s.folders, T.dirsCheck) + li(s.to_move, T.toMove);
                        }

                        var nothing = !r.data.total || !todo;
                        var html = head('ok', T.analyzed, r.data.time) + found;
                        if (nothing) {
                            html += '<p class="folderify-sync-nothing">' + esc(T.insync) + '</p>';
                        } else {
                            html += '<p class="folderify-sync-sub">' + esc(T.willAdd) + ':</p><ul class="folderify-sync-list">' + todo + '</ul>'
                                + '<p><button type="button" class="button button-primary folderify-sync-go">' + esc(T.start) + '</button> '
                                + '<button type="button" class="button folderify-sync-cancel">' + esc(T.cancel) + '</button></p>';
                        }
                        box($res, 'ok', html);
                        $res.data('mode', mode);
                    }).fail(function (xhr) {
                        $btn.prop('disabled', false);
                        var hint = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
                        box($res, 'err', head('err', T.failed + hint));
                    });
                });

                $(document).on('click', '.folderify-sync-go', function () {
                    var $res = $(this).closest('.folderify-sync-result');
                    runSteps($res, $res.data('mode'));
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