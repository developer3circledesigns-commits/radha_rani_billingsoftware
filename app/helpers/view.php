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

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
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
        return '<span class="badge badge-cash"><i class="bi bi-cash-coin me-1"></i>Cash</span>';
    }
    return '<span class="badge badge-card"><i class="bi bi-credit-card me-1"></i>Card</span>';
}

function bill_icon(): string
{
    return '<i class="bi bi-file-earmark-pdf-fill text-danger fs-3"></i>';
}