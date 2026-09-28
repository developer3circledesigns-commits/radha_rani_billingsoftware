<?php

declare(strict_types=1);

/**
 * Language switch endpoint.
 *
 * POST only, CSRF protected, same-origin enforced. On success it sets the
 * rr_lang cookie and redirects back to the page the visitor came from; the
 * language is applied to the redirect target on the next request, so no part
 * of this endpoint renders UI.
 *
 * Why GET is refused: a GET that mutates state can be triggered by a link,
 * an <img> or a prefetch, which would let a third-party site flip a visitor's
 * language. The switcher posts a form instead.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!isPost()) {
    header('Allow: POST');
    http_response_code(405);
    api_json(['success' => false, 'message' => t('api.method_not_allowed')], 405);
}

if (!csrf_verify()) {
    csrf_fail();
}

/**
 * Same-origin check.
 *
 * The Referer is not authentication, only a cheap guard against a cross-site
 * auto-submitting form. Browsers always send it on a same-origin POST; when
 * it is missing entirely (some privacy settings strip it) the request is
 * allowed through and CSRF remains the real check.
 *
 * Both sides are reduced to a full origin before comparing, because the port
 * is part of an origin. Comparing a bare host against HTTP_HOST would reject
 * every legitimate request on a non-default port, which is most local setups:
 * HTTP_HOST arrives as "hotel.local:8080" while parse_url() hands back
 * "hotel.local" and the two never match.
 */
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    $refOrigin  = request_origin($referer, true);
    $ourOrigin  = request_origin((string) ($_SERVER['HTTP_HOST'] ?? ''), false);
    if ($refOrigin !== null && $ourOrigin !== null && $refOrigin !== $ourOrigin) {
        header('Allow: POST');
        http_response_code(405);
        api_json(['success' => false, 'message' => t('api.method_not_allowed')], 405);
    }
}

$locale = (string) post('locale', '');
if (!Lang::persist($locale)) {
    // Unknown code: leave the stored language untouched and go back where the
    // visitor was. Nothing is written, so a tampered form cannot break the site.
    http_response_code(400);
    api_json(['success' => false, 'message' => t('api.invalid_response')], 400);
}

/**
 * Where to send the visitor back.
 *
 * Only a path on this host is accepted. A value starting with //, or with a
 * scheme, would be an open redirect.
 */
$returnTo = (string) post('return_to', '');
if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
    $returnTo = '/';
} elseif (preg_match('#[\r\n]#', $returnTo) === 1) {
    $returnTo = '/';
}

header('Location: ' . $returnTo, true, 303);
exit;
