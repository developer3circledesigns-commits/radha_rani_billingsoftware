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
    const cameraCaptureBtn  = document.getElementById('cameraCaptureBtn');
    const cameraSwitchBtn   = document.getElementById('cameraSwitchBtn');
    const cameraFallbackBtn = document.getElementById('cameraFallbackBtn');

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

    // Robust MAX_FILE_SIZE from server is already enforced; mirror the client rule here.
    const MAX_BYTES = (parseInt((window.APP || {}).MAX_UPLOAD_MB || '25', 10) || 25) * 1024 * 1024;
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
    let cameraModal = null;
    let cameraStream = null;
    let cameraFacing = 'environment';
    // Bumped whenever the stream is stopped, so a getUserMedia() promise that
    // resolves after the modal was closed cannot switch the camera back on.
    let cameraGeneration = 0;

    if (takePhotoBtn) {
        takePhotoBtn.addEventListener('click', openCamera);
    }
    if (galleryBtn) {
        galleryBtn.addEventListener('click', function () { photoGalleryInput.click(); });
    }
    if (photoRetakeBtn) {
        photoRetakeBtn.addEventListener('click', openCamera);
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

    if (cameraModalEl && window.bootstrap && window.bootstrap.Modal) {
        cameraModal = new window.bootstrap.Modal(cameraModalEl);
        cameraModalEl.addEventListener('hide.bs.modal', function () {
            cameraGeneration++;
        });
        cameraModalEl.addEventListener('hidden.bs.modal', stopCamera);
    }

    function openCamera() {
        if (!window.isSecureContext ||
            !navigator.mediaDevices ||
            typeof navigator.mediaDevices.getUserMedia !== 'function') {
            toast(
                txt(
                    window.isSecureContext ? 'js.camera_unavailable' : 'js.camera_insecure',
                    'Camera is not available in this browser.'
                ),
                'warning'
            );
            if (photoCaptureInput) photoCaptureInput.click();
            return;
        }

        if (!cameraModal) {
            if (photoCaptureInput) photoCaptureInput.click();
            return;
        }

        cameraModal.show();
        startCamera();
    }

    async function startCamera() {
        if (!cameraVideo) return;

        stopCamera();
        const generation = cameraGeneration;

        cameraStatus.classList.remove('d-none');
        cameraStatus.textContent = txt('js.camera_starting', 'Starting camera…');
        cameraCaptureBtn.disabled = true;
        cameraFallbackBtn.classList.add('d-none');

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: cameraFacing },
                    width: { ideal: 1920 },
                    height: { ideal: 1080 }
                }
            });

            // The modal was closed or another camera switch happened while the
            // permission prompt was open: release the stream immediately.
            if (generation !== cameraGeneration) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                return;
            }

            cameraStream = stream;
            cameraVideo.srcObject = stream;
            await cameraVideo.play().catch(function () {});
            cameraStatus.classList.add('d-none');
            cameraCaptureBtn.disabled = false;
        } catch (err) {
            if (generation !== cameraGeneration) return;
            cameraFail(cameraErrorKey(err));
        }
    }

    function cameraErrorKey(err) {
        const name = (err && err.name) || '';
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
            return 'js.camera_denied';
        }
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
            return 'js.camera_not_found';
        }
        if (name === 'NotReadableError' || name === 'TrackStartError') {
            return 'js.camera_busy';
        }
        return 'js.camera_unavailable';
    }

    function cameraFail(key) {
        cameraStatus.classList.remove('d-none');
        cameraStatus.textContent = txt(key, 'Camera is not available.');
        cameraCaptureBtn.disabled = true;
        cameraFallbackBtn.classList.remove('d-none');
        stopCamera();
    }

    function stopCamera() {
        cameraGeneration++;
        if (cameraVideo) cameraVideo.srcObject = null;
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) { track.stop(); });
            cameraStream = null;
        }
        if (cameraCaptureBtn) cameraCaptureBtn.disabled = true;
    }

    if (cameraCaptureBtn) {
        cameraCaptureBtn.addEventListener('click', function () {
            if (!cameraVideo || cameraVideo.readyState < 2) {
                toast(txt('js.camera_capture_failed', 'Could not capture the photo. Please try again.'), 'danger');
                return;
            }

            const w = cameraVideo.videoWidth;
            const h = cameraVideo.videoHeight;
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(cameraVideo, 0, 0, w, h);

            canvas.toBlob(function (blob) {
                if (!blob) {
                    toast(txt('js.camera_capture_failed', 'Could not capture the photo. Please try again.'), 'danger');
                    return;
                }
                const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
                const file = new File([blob], 'camera_' + stamp + '.jpg', { type: 'image/jpeg' });
                cameraModal.hide();
                acceptPhotoFile(file);
            }, 'image/jpeg', 0.92);
        });
    }

    if (cameraSwitchBtn) {
        cameraSwitchBtn.addEventListener('click', function () {
            cameraFacing = cameraFacing === 'environment' ? 'user' : 'environment';
            startCamera();
        });
    }

    if (cameraFallbackBtn) {
        cameraFallbackBtn.addEventListener('click', function () {
            cameraModal.hide();
            window.setTimeout(function () {
                if (photoCaptureInput) photoCaptureInput.click();
            }, 300);
        });
    }

    window.addEventListener('pagehide', stopCamera);

    // ---------- photo normalisation ----------
    async function acceptPhotoFile(file) {
        clearFileError();

        if (!file || (file.type && file.type.indexOf('image/') !== 0)) {
            setFileError(txt('js.only_photo', 'Only camera photos (JPEG) are allowed.'));
            return;
        }
        if (file.size === 0) {
            setFileError(txt('js.empty_file', 'Empty files are not allowed.'));
            return;
        }
        if (file.size > MAX_BYTES) {
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

    async function normalizePhoto(file) {
        const image = await decodeImage(file);
        try {
            let w = image.width || image.naturalWidth;
            let h = image.height || image.naturalHeight;
            if (!w || !h) throw new Error('decode');

            const scale = Math.min(1, MAX_PHOTO_EDGE / Math.max(w, h));
            let tw = Math.max(1, Math.round(w * scale));
            let th = Math.max(1, Math.round(h * scale));
            let quality = 0.9;
            let blob = null;

            for (let attempt = 0; attempt < 4; attempt++) {
                const canvas = document.createElement('canvas');
                canvas.width = tw;
                canvas.height = th;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, tw, th);
                ctx.drawImage(image, 0, 0, tw, th);

                blob = await canvasToBlob(canvas, quality);
                if (blob && blob.size <= MAX_BYTES - 65536) break;
                if (attempt === 3) throw new Error('too-large');

                tw = Math.max(1, Math.round(tw * 0.75));
                th = Math.max(1, Math.round(th * 0.75));
                if (Math.max(tw, th) < 640) throw new Error('too-large');
                quality = Math.max(0.6, quality - 0.1);
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
