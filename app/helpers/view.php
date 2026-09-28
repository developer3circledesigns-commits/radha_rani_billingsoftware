<?php

declare(strict_types=1);

/**
 * View renderer helpers.
 */

function render_layout(array $params): void
{
    // $params: pageTitle, subtitle, bodyClosure, activeMenu, extraScripts, bodyContent
    $pageTitle = $params['pageTitle'] ?? APP_NAME;
    $pageSubtitle = $params['pageSubtitle'] ?? '';
    $activeMenu = $params['activeMenu'] ?? '';
    $extraScripts = $params['extraScripts'] ?? [];
    $bodyContent = $params['bodyContent'] ?? '';

    require APP_PATH . '/views/layouts/header.php';
    echo $bodyContent;
    require APP_PATH . '/views/layouts/footer.php';
}

function render_complete(string $pageTitle, ?string $pageSubtitle, string $activeMenu, Closure $body): void
{
    ob_start();
    $body();
    $content = ob_get_clean();

    render_layout([
        'pageTitle'   => $pageTitle,
        'pageSubtitle'=> $pageSubtitle ?? '',
        'activeMenu'  => $activeMenu,
        'bodyContent' => $content,
    ]);
}

function partial(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_PATH . '/views/partials/' . $name . '.php';
}

function pagination(array $pagination, string $baseUrl): ?string
{
    if (($pagination['pages'] ?? 1) <= 1) {
        return null;
    }
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    $page = (int) $pagination['page'];
    $pages = (int) $pagination['pages'];

    $html =     '<nav aria-label="' . e(t('common.page_of', ['page' => $page, 'pages' => $pages])) . '"><ul class="pagination pagination-sm mb-0">';
    $html .= '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . e($baseUrl) . $sep . 'page=' . ($page - 1) . '">&laquo;</a></li>';

    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . e($baseUrl) . $sep . 'page=' . $i . '">' . $i . '</a></li>';
    }

    $html .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . e($baseUrl) . $sep . 'page=' . ($page + 1) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

function payment_type_badge(string $type): string
{
    if ($type === 'cash') {
        return '<span class="badge badge-cash"><i class="bi bi-cash-coin me-1"></i>' . e(t('common.cash')) . '</span>';
    }
    return '<span class="badge badge-card"><i class="bi bi-credit-card me-1"></i>' . e(t('common.card')) . '</span>';
}

/**
 * The subset of the catalogue the browser needs, for window.APP.i18n.
 *
 * Only the js.* keys plus a few shared ones are sent, so a 490-key catalogue
 * is not serialised into every page. Values are raw; the caller json_encode()s
 * with the HEX flags, and no browser code builds HTML from them.
 *
 * @return array<string,string>
 */
function client_i18n_strings(): array
{
    /** @var array{strings?:array<string,string>} $catalogue */
    $catalogue = require APP_PATH . '/lang/' . Lang::code() . '.php';
    $strings   = is_array($catalogue['strings'] ?? null) ? $catalogue['strings'] : [];

    $out = [];
    foreach ($strings as $key => $value) {
        if (str_starts_with($key, 'js.') || in_array($key, [
            'common.confirm', 'common.cancel', 'common.yes_continue',
            'common.save', 'common.close',
            'common.cash', 'common.card',
        ], true)) {
            $out[$key] = $value;
        }
    }
    return $out;
}

function bill_icon(): string
{
    return '<i class="bi bi-file-earmark-pdf-fill text-danger fs-3"></i>';
}