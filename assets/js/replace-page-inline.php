<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
(function () {
    const fileInput = document.getElementById('folderify-file-input');
    const uploadContainer = document.getElementById('folderify-upload-container');
    const uploadZone = document.getElementById('folderify-upload-zone');
    const emptyState = document.getElementById('folderify-empty-state');
    const filePreview = document.getElementById('folderify-file-preview');
    const uploadOverlay = document.getElementById('folderify-upload-overlay');
    const newFileInfo = document.getElementById('folderify-new-file-info');
    const newFilenameDisplay = document.getElementById('folderify-new-filename');
    const newFilesizeDisplay = document.getElementById('folderify-new-filesize');
    const choices = document.querySelectorAll('.folderify-choice');
    const replaceForm = document.getElementById('folderify-replace-form');

    if (!fileInput || !uploadContainer) return;

    /**
     * Process the selected file and update UI.
     */
    const processFile = (file) => {
        if (!file) return;

        // Update UI state
        if (uploadZone) uploadZone.classList.add('has-file');
        if (emptyState) emptyState.style.display = 'none';
        if (filePreview) filePreview.style.display = 'flex';
        if (uploadOverlay) uploadOverlay.classList.add('active');

        // Update info display
        if (newFilenameDisplay) newFilenameDisplay.textContent = file.name;
        if (newFilesizeDisplay) newFilesizeDisplay.textContent = (file.size / 1024).toFixed(2) + ' KB';
        if (newFileInfo) newFileInfo.style.visibility = 'visible';

        // Update preview
        if (filePreview) {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function (re) {
                    filePreview.innerHTML = `<img src="${re.target.result}" style="max-width:100%; max-height:100%; object-fit:contain;" alt="Preview">`;
                };
                reader.readAsDataURL(file);
            } else {
                filePreview.innerHTML = `
                    <div class="folderify-preview-placeholder">
                        <span class="dashicons dashicons-media-default" style="font-size: 48px; width: 48px; height: 48px;"></span>
                        <span>${file.type || 'unknown'}</span>
                    </div>`;
            }
        }
    };

    // 1. File Input Change Handling
    fileInput.addEventListener('change', function (e) {
        processFile(e.target.files[0]);
    });

    // 2. Drag and Drop Handling
    const preventDefaults = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadContainer.addEventListener(eventName, preventDefaults, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        uploadContainer.addEventListener(eventName, () => {
            uploadZone.classList.add('is-dragging');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        uploadContainer.addEventListener(eventName, () => {
            uploadZone.classList.remove('is-dragging');
        }, false);
    });

    uploadContainer.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;

        if (files && files.length > 0) {
            fileInput.files = files; // Sync with hidden input
            processFile(files[0]);
        }
    }, false);

    // 3. Radio choice UI handling
    choices.forEach(choice => {
        const radio = choice.querySelector('input[type="radio"]');
        if (!radio) return;

        choice.addEventListener('click', function (e) {
            // Don't trigger if clicked on input itself (avoid double toggle)
            if (e.target !== radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        radio.addEventListener('change', function () {
            const name = this.name;
            document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                const label = r.closest('.folderify-choice');
                if (label) label.classList.remove('active');
            });
            if (this.checked) {
                choice.classList.add('active');
            }
        });
    });

    const choiceRadios = document.querySelectorAll('input[name="timestamp_replace"]');
    const updateCustomState = () => {
        choiceRadios.forEach(r => {
            const label = r.closest('label');
            if (!label) return;
            if (r.value === 'custom' && r.checked) {
                label.classList.add('is-custom-active');
            } else {
                label.classList.remove('is-custom-active');
            }
        });
    };

    choiceRadios.forEach(radio => {
        radio.addEventListener('change', updateCustomState);
    });

    // Init state
    updateCustomState();

    // 4. Form submission visual
    if (replaceForm) {
        replaceForm.addEventListener('submit', function () {
            const btn = this.querySelector('.folderify-btn-primary');
            if (btn) {
                btn.style.opacity = '0.7';
                btn.style.pointerEvents = 'none';
                btn.innerHTML = '<?php esc_html_e('Processing...', 'folderify'); ?>';
            }
        });
    }
})();