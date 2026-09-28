/* ============================================================
   Radha Rani Hotel Portal — Bill upload (hand-written)
   Drag & drop, client-side validation, AJAX with progress
   ============================================================ */
(function () {
    'use strict';

    const dropZone      = document.getElementById('dropZone');
    const fileInput     = document.getElementById('fileInput');
    const browseBtn     = document.getElementById('browseBtn');
    const filePreview   = document.getElementById('filePreview');
    const filePreviewName  = document.getElementById('filePreviewName');
    const filePreviewSize  = document.getElementById('filePreviewSize');
    const fileRemoveBtn = document.getElementById('fileRemoveBtn');

    const form           = document.getElementById('uploadForm');
    const uploadBtn      = document.getElementById('uploadBtn');
    const formWrap       = document.getElementById('uploadFormWrap');
    const progressWrap   = document.getElementById('uploadProgressWrap');
    const resultWrap     = document.getElementById('uploadResultWrap');
    const errorWrap      = document.getElementById('uploadErrorWrap');
    const progressBar    = document.getElementById('progressBar');
    const progressLabel  = document.getElementById('progressLabel');
    const progressPct    = document.getElementById('progressPercent');
    const resultTitle    = document.getElementById('resultTitle');
    const resultMessage  = document.getElementById('resultMessage');
    const resultActions  = document.getElementById('resultActions');
    const errorMessage   = document.getElementById('errorMessage');
    const errorRetryBtn  = document.getElementById('errorRetryBtn');

    const ptErr = document.getElementById('ptErr');
    const dateErr = document.getElementById('dateErr');
    const fileErr = document.getElementById('fileErr');

    if (!form) return;

    // Server-rendered catalogue subset (window.APP.i18n). English values are
    // used as the fallback so a missing key degrades to readable text rather
    // than an empty element.
    const S = (window.APP || {}).i18n || {};
    const txt = (key, fallback) => S[key] || fallback;
    const cashLabel = txt('common.cash', 'Cash');
    const cardLabel = txt('common.card', 'Card');

    let selectedFile = null;
    let busy = false;

    // Robust MAX_FILE_SIZE from server is already enforced; mirror the client rule here.
    // Client-side cap mirrors the server-side configured maximum.
    const MAX_BYTES = (parseInt((window.APP||{}).MAX_UPLOAD_MB || '25', 10) || 25) * 1024 * 1024;

    // ---------- drag & drop ----------
    browseBtn.addEventListener('click', function (e) {
        e.preventDefault();
        fileInput.click();
    });

    fileInput.addEventListener('change', function () {
        if (fileInput.files.length) onFileSelected(fileInput.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (evtName) {
        dropZone.addEventListener(evtName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evtName) {
        dropZone.addEventListener(evtName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
        });
    });
    dropZone.addEventListener('drop', function (e) {
        const files = e.dataTransfer.files;
        if (files.length) onFileSelected(files[0]);
    });

    fileRemoveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        clearFileSelection();
    });

    function onFileSelected(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();

        if (ext !== 'pdf' || file.type !== 'application/pdf') {
            setFileError(txt('js.only_pdf', 'Only PDF files are allowed.'));
            clearFileSelection();
            return;
        }
        if (file.size === 0) {
            setFileError(txt('js.empty_file', 'Empty files are not allowed.'));
            clearFileSelection();
            return;
        }
        if (file.size > MAX_BYTES) {
            setFileError(txt('js.too_large', 'PDF file is larger than the allowed limit.'));
            clearFileSelection();
            return;
        }

        selectedFile = file;
        filePreview.classList.remove('d-none');
        document.querySelector('.dz-inner').classList.add('d-none');
        filePreviewName.textContent = file.name;
        filePreviewSize.textContent = formatSize(file.size);
        clearFileError();
    }

    function clearFileSelection() {
        selectedFile = null;
        fileInput.value = '';
        filePreview.classList.add('d-none');
        document.querySelector('.dz-inner').classList.remove('d-none');
    }

    // ---------- validation UI helpers ----------
    function setFieldError(el, msg) {
        if (el) el.textContent = msg || '';
    }
    function clearFieldErrors() {
        setFieldError(ptErr);
        setFieldError(dateErr);
        setFieldError(fileErr);
    }

    // ---------- submit ----------
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;

        clearFieldErrors();
        let valid = true;

        const paymentType = form.querySelector('input[name="payment_type"]:checked');
        if (!paymentType || !paymentType.value) {
            setFieldError(ptErr, txt('js.select_cash_card', 'Please select Cash or Card.'));
            valid = false;
        }

        const dateVal = form.querySelector('input[name="business_date"]').value;
        if (!dateVal) {
            setFieldError(dateErr, txt('js.select_date', 'Please select the business date.'));
            valid = false;
        } else if (dateVal > todayISO()) {
            setFieldError(dateErr, txt('js.future_date', 'Business date cannot be in the future.'));
            valid = false;
        }

        if (!selectedFile) {
            setFieldError(fileErr, txt('js.choose_pdf', 'Please choose a PDF file.'));
            valid = false;
        } else if (selectedFile.type !== 'application/pdf' || (selectedFile.name.split('.').pop() || '').toLowerCase() !== 'pdf') {
            setFieldError(fileErr, txt('js.only_pdf', 'Only PDF files are allowed.'));
            valid = false;
        }

        if (!valid) return;

        busy = true;
        uploadBtn.disabled = true;
        formWrap.classList.add('d-none');
        progressWrap.classList.remove('d-none');
        progressLabel.textContent = txt('js.uploading', 'Uploading…');
        progressBar.style.width = '0%';
        progressPct.textContent = '0%';

        const fd = new FormData(form);
        fd.set('pdf_file', selectedFile, selectedFile.name);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action'));
        xhr.responseType = 'json';

        xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) {
                const pct = Math.round((ev.loaded / ev.total) * 100);
                progressBar.style.width = pct + '%';
                progressPct.textContent = pct + '%';
                if (pct === 100) {
                    progressLabel.textContent = txt('js.processing', 'Processing…');
                }
            }
        });

        xhr.addEventListener('load', function () {
            busy = false;
            uploadBtn.disabled = false;

            let data = xhr.response;
            try {
                if (!data) data = JSON.parse(xhr.responseText);
            } catch (ex) {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
                progressWrap.classList.add('d-none');
                resultWrap.classList.remove('d-none');
                resultTitle.textContent = txt('js.upload_ok', 'Bill uploaded successfully.');
                resultMessage.textContent = (data.data.filename || '') + ' · ' + (data.data.payment === 'cash' ? cashLabel : cardLabel) + ' · ' + data.data.biz_date;
                // Labels are inserted with textContent-built strings from the
                // catalogue only; no user data is concatenated into markup.
                resultActions.textContent = '';
                const viewLink = document.createElement('a');
                viewLink.href = data.data.view_url;
                viewLink.className = 'btn btn-outline-primary btn-sm';
                viewLink.appendChild(icon('bi-eye'));
                viewLink.appendChild(document.createTextNode(
                    ' ' + txt('js.view_document', 'View Document')
                ));

                const againBtn = document.createElement('button');
                againBtn.type = 'button';
                againBtn.className = 'btn btn-light btn-sm';
                againBtn.appendChild(icon('bi-plus-lg'));
                againBtn.appendChild(document.createTextNode(
                    ' ' + txt('js.upload_another', 'Upload Another')
                ));
                againBtn.addEventListener('click', function () { window.location.reload(); });

                resultActions.appendChild(viewLink);
                resultActions.appendChild(againBtn);
            } else {
                showError(data && data.message ? data.message : txt('js.failed_retry', 'Upload failed — please retry.'));
            }
        });

        xhr.addEventListener('error', function () {
            busy = false;
            uploadBtn.disabled = false;
            showError(txt('js.network_error', 'Network error — upload failed. Please retry.'));
        });

        xhr.send(fd);
    });

    function showError(message) {
        progressWrap.classList.add('d-none');
        resultWrap.classList.add('d-none');
        errorWrap.classList.remove('d-none');
        errorMessage.textContent = message;
    }

    if (errorRetryBtn) {
        errorRetryBtn.addEventListener('click', function () {
            errorWrap.classList.add('d-none');
            formWrap.classList.remove('d-none');
            clearFileSelection();
        });
    }

    // ---------- helpers ----------
    function icon(cls) {
        const el = document.createElement('i');
        el.className = 'bi ' + cls + ' me-1';
        return el;
    }

    function formatSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function todayISO() {
        const d = new Date();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }
})();