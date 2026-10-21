/* ============================================================
   Radha Rani Hotel Portal — Bill upload (hand-written)
   PDF drag & drop, camera capture, client-side validation, AJAX
   ============================================================ */
(function () {
    'use strict';

    const dropZone      = document.getElementById('dropZone');
    const dzInner       = document.getElementById('dzInner');
    const fileInput     = document.getElementById('fileInput');
    const browseBtn     = document.getElementById('browseBtn');
    const filePreview   = document.getElementById('filePreview');
    const filePreviewName  = document.getElementById('filePreviewName');
    const filePreviewSize  = document.getElementById('filePreviewSize');
    const fileRemoveBtn = document.getElementById('fileRemoveBtn');

    const takePhotoBtn  = document.getElementById('takePhotoBtn');
    const galleryBtn    = document.getElementById('galleryBtn');
    const photoPreview  = document.getElementById('photoPreview');
    const photoPreviewImg   = document.getElementById('photoPreviewImg');
    const photoPreviewMeta  = document.getElementById('photoPreviewMeta');
    const photoGalleryBtn   = document.getElementById('photoGalleryBtn');
    const photoRetakeBtn    = document.getElementById('photoRetakeBtn');
    const photoRemoveBtn    = document.getElementById('photoRemoveBtn');
    const photoCaptureInput = document.getElementById('photoCaptureInput');
    const photoGalleryInput = document.getElementById('photoGalleryInput');

    const cameraModalEl   = document.getElementById('cameraModal');
    const cameraVideo     = document.getElementById('cameraVideo');
    const cameraStatus    = document.getElementById('cameraStatus');
    const cameraStatusText = document.getElementById('cameraStatusText');
    const cameraSpinner   = document.getElementById('cameraSpinner');
    const cameraRetryBtn  = document.getElementById('cameraRetryBtn');
    const cameraFallbackBtn = document.getElementById('cameraFallbackBtn');
    const cameraCaptureBtn = document.getElementById('cameraCaptureBtn');
    const cameraSwitchBtn = document.getElementById('cameraSwitchBtn');
    const cameraHint      = document.getElementById('cameraHint');

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
    let selectedPhoto = null;
    let photoUrl = null;
    let photoProcessing = false;
    let busy = false;
    let autoRetryCount = 0;

    let cameraModal = null;
    let cameraStream = null;
    let cameraDevices = [];
    let cameraActiveDeviceId = '';
    let cameraToken = 0;
    let cameraBusy = false;

    // Use the exact effective limit the server computed (PHP upload/post limits,
    // database packet size and the configured setting all included). The MB
    // value remains as a fallback for an older cached footer.
    const MAX_BYTES = parseInt((window.APP || {}).MAX_UPLOAD_BYTES || '0', 10) ||
        (parseInt((window.APP || {}).MAX_UPLOAD_MB || '20', 10) || 20) * 1024 * 1024;
    const RAW_PHOTO_MAX = 50 * 1024 * 1024;
    const MAX_PHOTO_EDGE = 2400;

    // ---------- view state ----------
    function refreshDocumentView() {
        const hasPhoto = !!selectedPhoto;
        const hasPdf = !!selectedFile;
        dzInner.classList.toggle('d-none', hasPhoto || hasPdf);
        filePreview.classList.toggle('d-none', !hasPdf);
        photoPreview.classList.toggle('d-none', !hasPhoto);
    }

    function resetPhotoState() {
        if (photoUrl) {
            URL.revokeObjectURL(photoUrl);
            photoUrl = null;
        }
        selectedPhoto = null;
        photoPreviewImg.removeAttribute('src');
        photoPreviewMeta.textContent = '';
        photoCaptureInput.value = '';
        photoGalleryInput.value = '';
    }

    function clearPhotoSelection() {
        resetPhotoState();
        refreshDocumentView();
    }

    function clearPdfSelection() {
        selectedFile = null;
        fileInput.value = '';
        filePreview.classList.add('d-none');
    }

    function clearFileSelection() {
        clearPdfSelection();
        refreshDocumentView();
    }

    function showPhoto(jpegFile) {
        clearPdfSelection();
        selectedPhoto = jpegFile;
        if (photoUrl) URL.revokeObjectURL(photoUrl);
        photoUrl = URL.createObjectURL(jpegFile);
        photoPreviewImg.src = photoUrl;
        photoPreviewMeta.textContent = formatSize(jpegFile.size) + ' · ' +
            txt('js.photo_ready', 'Photo ready — it will be saved as a PDF bill.');
        refreshDocumentView();
        clearFileError();
    }

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
        if (!files.length) return;
        if (files[0].type.indexOf('image/') === 0) {
            acceptPhotoFile(files[0]);
        } else {
            onFileSelected(files[0]);
        }
    });

    fileRemoveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        clearFileSelection();
        clearFileError();
    });

    function onFileSelected(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();

        if (ext !== 'pdf' || file.type !== 'application/pdf') {
            setFileError(txt('js.only_pdf', 'Only PDF files are allowed.'));
            fileInput.value = '';
            return;
        }
        if (file.size === 0) {
            setFileError(txt('js.empty_file', 'Empty files are not allowed.'));
            fileInput.value = '';
            return;
        }
        if (file.size > MAX_BYTES) {
            setFileError(txt('js.too_large', 'PDF file is larger than the allowed limit.'));
            fileInput.value = '';
            return;
        }

        resetPhotoState();
        selectedFile = file;
        filePreviewName.textContent = file.name;
        filePreviewSize.textContent = formatSize(file.size);
        refreshDocumentView();
        clearFileError();
    }

    // ---------- camera ----------
    // "Take Photo" opens the live device camera through getUserMedia, so a
    // laptop with a built-in webcam shows the camera instead of a file picker.
    // Phones and tablets get the same live preview with a switch button when
    // they have more than one camera. If the live camera cannot start (blocked
    // permission, insecure origin, camera busy or no camera at all) the native
    // capture input is offered as a fallback, which keeps the old behaviour.
    if (takePhotoBtn) {
        takePhotoBtn.addEventListener('click', openDeviceCamera);
    }
    if (photoRetakeBtn) {
        photoRetakeBtn.addEventListener('click', openDeviceCamera);
    }
    if (galleryBtn) {
        galleryBtn.addEventListener('click', function () { photoGalleryInput.click(); });
    }
    if (photoRemoveBtn) {
        photoRemoveBtn.addEventListener('click', function () {
            clearPhotoSelection();
            clearFileError();
        });
    }
    if (photoGalleryBtn) {
        photoGalleryBtn.addEventListener('click', function () { photoGalleryInput.click(); });
    }
    if (photoGalleryInput) {
        photoGalleryInput.addEventListener('change', function () {
            if (photoGalleryInput.files.length) acceptPhotoFile(photoGalleryInput.files[0]);
            photoGalleryInput.value = '';
        });
    }
    if (photoCaptureInput) {
        photoCaptureInput.addEventListener('change', function () {
            if (photoCaptureInput.files.length) acceptPhotoFile(photoCaptureInput.files[0]);
            photoCaptureInput.value = '';
        });
    }
    if (cameraModalEl) {
        cameraModalEl.addEventListener('hidden.bs.modal', stopCamera);
    }
    if (cameraCaptureBtn) {
        cameraCaptureBtn.addEventListener('click', captureCameraPhoto);
    }
    if (cameraRetryBtn) {
        cameraRetryBtn.addEventListener('click', function () { startCamera(cameraActiveDeviceId); });
    }
    if (cameraFallbackBtn) {
        cameraFallbackBtn.addEventListener('click', function () {
            closeCamera();
            openNativeCapture();
        });
    }
    if (cameraSwitchBtn) {
        cameraSwitchBtn.addEventListener('click', function () {
            if (cameraBusy || cameraDevices.length < 2) return;
            let index = 0;
            for (let i = 0; i < cameraDevices.length; i++) {
                if (cameraDevices[i].deviceId === cameraActiveDeviceId) { index = i; break; }
            }
            const next = cameraDevices[(index + 1) % cameraDevices.length];
            if (next) startCamera(next.deviceId);
        });
    }

    function cameraSupported() {
        return !!(navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === 'function');
    }

    function openDeviceCamera() {
        if (!cameraSupported() || !cameraModalEl || !cameraVideo ||
            !window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
            if (window.isSecureContext === false) {
                toast(txt('js.camera_insecure', 'The camera needs a secure connection (HTTPS or localhost). Opening the file picker instead.'), 'warning');
            }
            openNativeCapture();
            return;
        }

        if (!cameraModal) cameraModal = new window.bootstrap.Modal(cameraModalEl);
        showCameraStatus(txt('js.camera_starting', 'Starting camera…'), { spinner: true });
        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;
        cameraModal.show();
        startCamera('');
    }

    function openNativeCapture() {
        if (!photoCaptureInput) return;
        photoCaptureInput.value = '';
        photoCaptureInput.click();
    }

    async function startCamera(deviceId) {
        const token = ++cameraToken;
        releaseCameraStream();
        cameraBusy = true;
        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;
        if (cameraHint) cameraHint.classList.add('d-none');
        showCameraStatus(txt('js.camera_starting', 'Starting camera…'), { spinner: true });

        const videoConstraints = deviceId
            ? { deviceId: { exact: deviceId }, width: { ideal: 1920 }, height: { ideal: 1080 } }
            : { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } };

        try {
            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraints });
            } catch (err) {
                const retryable = err && [
                    'OverconstrainedError', 'ConstraintNotSatisfiedError',
                    'NotFoundError', 'DevicesNotFoundError', 'TypeError'
                ].indexOf(err.name) !== -1;
                if (!retryable) throw err;
                stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
            }

            if (token !== cameraToken) {
                stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
                return;
            }

            cameraStream = stream;
            cameraVideo.srcObject = stream;
            try { await cameraVideo.play(); } catch (e) {}

            const track = stream.getVideoTracks()[0];
            const settings = track && typeof track.getSettings === 'function' ? track.getSettings() : {};
            cameraActiveDeviceId = settings.deviceId || '';
            await refreshCameraDevices();

            cameraBusy = false;
            if (cameraCaptureBtn) cameraCaptureBtn.disabled = false;
            hideCameraStatus();
            if (cameraHint) cameraHint.classList.remove('d-none');
        } catch (err) {
            if (token !== cameraToken) return;
            cameraBusy = false;
            handleCameraError(err);
        }
    }

    async function refreshCameraDevices() {
        cameraDevices = [];
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') return;
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            cameraDevices = devices.filter(function (d) {
                return d.kind === 'videoinput' && d.deviceId;
            });
        } catch (e) {
            cameraDevices = [];
        }
        if (cameraSwitchBtn) {
            cameraSwitchBtn.classList.toggle('d-none', cameraDevices.length < 2);
        }
    }

    function handleCameraError(err) {
        const name = err && err.name ? err.name : '';
        let message;
        let canRetry = true;

        if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
            message = txt('js.camera_denied', 'Camera access was blocked. Allow camera permission for this site, then try again — or choose a file instead.');
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
            message = txt('js.camera_missing', 'No camera was found on this device. Choose a file instead.');
            canRetry = false;
        } else if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
            message = txt('js.camera_busy', 'The camera is in use by another app. Close it and try again — or choose a file instead.');
        } else {
            message = txt('js.camera_error', 'The camera could not be opened. Choose a file instead.');
        }

        showCameraStatus(message, { spinner: false, retry: canRetry, fallback: true });
        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;
        if (cameraHint) cameraHint.classList.add('d-none');
    }

    function captureCameraPhoto() {
        if (cameraBusy || !cameraStream || !cameraVideo) return;

        const w = cameraVideo.videoWidth || 0;
        const h = cameraVideo.videoHeight || 0;
        if (!w || !h) {
            showCameraStatus(txt('js.camera_capture_failed', 'The photo could not be captured. Please try again.'), { spinner: false, retry: true, fallback: true });
            return;
        }

        const scale = Math.min(1, MAX_PHOTO_EDGE / Math.max(w, h));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(cameraVideo, 0, 0, canvas.width, canvas.height);

        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;

        canvas.toBlob(function (blob) {
            if (!blob) {
                if (cameraCaptureBtn) cameraCaptureBtn.disabled = false;
                showCameraStatus(txt('js.camera_capture_failed', 'The photo could not be captured. Please try again.'), { spinner: false, retry: true, fallback: true });
                return;
            }
            const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            const file = new File([blob], 'camera_' + stamp + '.jpg', { type: 'image/jpeg' });
            closeCamera();
            acceptPhotoFile(file);
        }, 'image/jpeg', 0.92);
    }

    function closeCamera() {
        stopCamera();
        if (cameraModal) cameraModal.hide();
    }

    function stopCamera() {
        cameraToken++;
        cameraBusy = false;
        releaseCameraStream();
        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;
        if (cameraHint) cameraHint.classList.add('d-none');
    }

    function releaseCameraStream() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
            cameraStream = null;
        }
        if (cameraVideo) {
            try { cameraVideo.pause(); } catch (e) {}
            cameraVideo.srcObject = null;
        }
    }

    function showCameraStatus(message, opts) {
        if (!cameraStatus) return;
        opts = opts || {};
        cameraStatus.classList.remove('d-none');
        if (cameraStatusText) cameraStatusText.textContent = message;
        if (cameraSpinner) cameraSpinner.classList.toggle('d-none', !opts.spinner);
        if (cameraRetryBtn) cameraRetryBtn.classList.toggle('d-none', !opts.retry);
        if (cameraFallbackBtn) cameraFallbackBtn.classList.toggle('d-none', !opts.fallback);
        if (cameraHint) cameraHint.classList.add('d-none');
    }

    function hideCameraStatus() {
        if (cameraStatus) cameraStatus.classList.add('d-none');
    }

    // ---------- photo normalisation ----------
    async function acceptPhotoFile(file) {
        clearFileError();
        autoRetryCount = 0;

        if (!file || (file.type && file.type.indexOf('image/') !== 0)) {
            setFileError(txt('js.only_photo', 'Only camera photos (JPEG) are allowed.'));
            return;
        }
        if (file.size === 0) {
            setFileError(txt('js.empty_file', 'Empty files are not allowed.'));
            return;
        }
        // The original capture may be larger than the limit; the canvas
        // normalisation below shrinks it. Only reject files too large to decode
        // safely, then check the normalised JPEG against MAX_BYTES.
        if (file.size > RAW_PHOTO_MAX) {
            setFileError(txt('js.photo_too_large', 'The photo is larger than the allowed limit.'));
            return;
        }
        if (photoProcessing) return;

        const previousMeta = photoPreviewMeta.textContent;
        photoProcessing = true;
        setPhotoControls(false);
        photoPreviewMeta.textContent = txt('js.photo_processing', 'Preparing photo…');

        try {
            const jpeg = await normalizePhoto(file);
            showPhoto(jpeg);
        } catch (err) {
            photoPreviewMeta.textContent = previousMeta;
            if (err && err.message === 'too-large') {
                setFileError(txt('js.photo_too_large', 'The photo is larger than the allowed limit.'));
            } else {
                setFileError(txt('js.photo_failed', 'The photo could not be read. Please try another one.'));
            }
        } finally {
            photoProcessing = false;
            setPhotoControls(true);
            if (!selectedPhoto) {
                photoPreviewMeta.textContent = '';
                refreshDocumentView();
            }
        }
    }

    function setPhotoControls(enabled) {
        [takePhotoBtn, galleryBtn, photoGalleryBtn, photoRetakeBtn, photoRemoveBtn].forEach(function (el) {
            if (el) el.disabled = !enabled;
        });
        uploadBtn.disabled = !enabled || busy;
    }

    async function normalizePhoto(file, options) {
        options = options || {};
        const maxBytes = options.maxBytes || MAX_BYTES;
        const maxEdge = options.maxEdge || MAX_PHOTO_EDGE;
        const image = await decodeImage(file);
        try {
            let w = image.width || image.naturalWidth;
            let h = image.height || image.naturalHeight;
            if (!w || !h) throw new Error('decode');

            const scale = Math.min(1, maxEdge / Math.max(w, h));
            let tw = Math.max(1, Math.round(w * scale));
            let th = Math.max(1, Math.round(h * scale));
            let quality = options.quality || 0.9;
            let blob = null;

            for (let attempt = 0; attempt < 6; attempt++) {
                const canvas = document.createElement('canvas');
                canvas.width = tw;
                canvas.height = th;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, tw, th);
                ctx.drawImage(image, 0, 0, tw, th);

                blob = await canvasToBlob(canvas, quality);
                if (blob && blob.size <= maxBytes - 65536) break;
                if (attempt === 5) throw new Error('too-large');

                tw = Math.max(1, Math.round(tw * 0.75));
                th = Math.max(1, Math.round(th * 0.75));
                if (Math.max(tw, th) < 480) throw new Error('too-large');
                quality = Math.max(0.5, quality - 0.1);
            }

            if (!blob || blob.size === 0) throw new Error('decode');

            const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            return new File([blob], 'photo_' + stamp + '.jpg', { type: 'image/jpeg' });
        } finally {
            if (typeof image.close === 'function') image.close();
        }
    }

    function decodeImage(file) {
        if (typeof window.createImageBitmap === 'function') {
            return window.createImageBitmap(file, { imageOrientation: 'from-image' })
                .catch(function () { return loadImageElement(file); });
        }
        return loadImageElement(file);
    }

    function loadImageElement(file) {
        return new Promise(function (resolve, reject) {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                resolve(img);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('decode'));
            };
            img.src = url;
        });
    }

    function canvasToBlob(canvas, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error('decode'));
            }, 'image/jpeg', quality);
        });
    }

    // ---------- validation UI helpers ----------
    function setFileError(msg) {
        if (fileErr) fileErr.textContent = msg || '';
    }
    function clearFileError() {
        setFileError('');
    }
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
        if (busy || photoProcessing) return;

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

        if (!selectedFile && !selectedPhoto) {
            setFileError(txt('js.choose_document', 'Please choose a PDF or take a photo.'));
            valid = false;
        } else if (selectedFile) {
            const ext = (selectedFile.name.split('.').pop() || '').toLowerCase();
            if (selectedFile.type !== 'application/pdf' || ext !== 'pdf') {
                setFileError(txt('js.only_pdf', 'Only PDF files are allowed.'));
                valid = false;
            }
        } else if (!selectedPhoto || selectedPhoto.type !== 'image/jpeg') {
            setFileError(txt('js.only_photo', 'Only camera photos (JPEG) are allowed.'));
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
        if (selectedPhoto) {
            fd.delete('pdf_file');
            fd.set('upload_mode', 'photo');
            fd.set('photo_file', selectedPhoto, selectedPhoto.name);
        } else {
            fd.delete('photo_file');
            fd.set('upload_mode', 'pdf');
            fd.set('pdf_file', selectedFile, selectedFile.name);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action'));
        xhr.responseType = 'json';
        xhr.timeout = 120000;

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

        xhr.addEventListener('load', async function () {
            busy = false;
            uploadBtn.disabled = false;

            let data = xhr.response;
            try {
                if (!data) data = JSON.parse(xhr.responseText);
            } catch (ex) {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
                autoRetryCount = 0;
                progressWrap.classList.add('d-none');
                resultWrap.classList.remove('d-none');
                const source = (data.data && data.data.source) || 'pdf';
                resultTitle.textContent = source === 'photo'
                    ? txt('js.upload_photo_ok', 'Photo saved as a PDF bill.')
                    : txt('js.upload_ok', 'Bill uploaded successfully.');
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
                // The next form defaults to the opposite of the type that was
                // just uploaded: a cash upload is followed by a card-default
                // form and vice versa, instead of a stale re-selection. The
                // server re-renders the markup with that default.
                const nextDefault = data.data.payment === 'cash' ? 'card' : 'cash';
                againBtn.addEventListener('click', function () {
                    const url = new URL(window.location.href);
                    url.searchParams.set('payment_default', nextDefault);
                    window.location.href = url.toString();
                });

                resultActions.appendChild(viewLink);
                resultActions.appendChild(againBtn);

                // The upload changed the "bills not uploaded" alert for this
                // branch. Saying so here stops the branch admin assuming the
                // owner is still looking at a red badge, and confirms the loop
                // from their side actually did something.
                //
                // A day can be only partly fixed, so the server sends the
                // finished message rather than a key: it knows what is still
                // outstanding and has already translated it. The client only
                // needs a fallback for the no-placeholder case.
                const compliance = data.data.compliance;
                if (compliance && compliance.closed > 0) {
                    const message = compliance.message || (compliance.compliant
                        ? txt('js.compliance_restored', 'The owner has been notified that today\'s bills are now complete.')
                        : '');
                    if (message) {
                        toast(message, compliance.compliant ? 'success' : 'warning');
                    }
                }
            } else if (xhr.status === 413 && selectedPhoto && autoRetryCount < 2) {
                // Some Apache/LiteSpeed hosts return 413 before PHP runs even
                // though PHP's own limits are generous. Retry with a smaller
                // normalised copy instead of making the user guess a size.
                autoRetryCount++;
                busy = true;
                uploadBtn.disabled = true;
                const caps = [900 * 1024, 400 * 1024];
                const edges = [1600, 1200];
                progressLabel.textContent = txt('js.retrying_smaller', 'Retrying with a smaller copy…');
                try {
                    const smaller = await normalizePhoto(selectedPhoto, {
                        maxBytes: caps[autoRetryCount - 1],
                        maxEdge: edges[autoRetryCount - 1],
                        quality: 0.82,
                    });
                    showPhoto(smaller);
                    busy = false;
                    uploadBtn.disabled = false;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                } catch (err) {
                    busy = false;
                    uploadBtn.disabled = false;
                    showError(txt('js.server_too_large', 'The file is too large for the server. Please use a smaller photo.'));
                }
            } else if (xhr.status === 413) {
                showError(txt('js.server_too_large', 'The file is too large for the server. Please use a smaller photo.'));
            } else if (data && data.message) {
                showError(data.message);
            } else {
                // A non-JSON response (proxy error page, PHP fatal outside the
                // JSON handler, etc.). Log the body for support and show the
                // status code instead of an unhelpful generic message.
                console.error('[bill-upload] non-JSON response', xhr.status, xhr.responseText);
                showError(txt('js.failed_retry', 'Upload failed — please retry.')
                    + (xhr.status ? ' (HTTP ' + xhr.status + ')' : ''));
            }
        });

        xhr.addEventListener('error', function () {
            busy = false;
            uploadBtn.disabled = false;
            showError(txt('js.network_error', 'Network error — upload failed. Please retry.'));
        });

        xhr.addEventListener('timeout', function () {
            busy = false;
            uploadBtn.disabled = false;
            showError(txt('js.timeout_error', 'The upload timed out. Please check your connection and retry.'));
        });

        xhr.addEventListener('abort', function () {
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
            clearPhotoSelection();
            clearFieldErrors();
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

    function toast(message, type) {
        if (typeof window.RRToast === 'function') {
            window.RRToast(message, type || 'info');
        }
    }
})();
