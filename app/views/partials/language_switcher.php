<?php
/**
 * Language switcher.
 *
 * Renders one small POST form per available locale, so switching needs no JS
 * and works on a page that failed to load its scripts. public/set-language.php
 * validates the CSRF token and the Referer, sets the cookie, and redirects
 * back to the page the visitor was on.
 *
 * The "return" is the current path, carried in a hidden field. It is validated
 * server-side as a relative path before the redirect, so it can only ever send
 * the visitor back inside this site.
 */
$langCode  = Lang::code();
$langNames = Lang::available();
$returnTo  = e($_SERVER['REQUEST_URI'] ?? '/');
?>
<div class="lang-switch">
    <span class="lang-switch-label">
        <i class="bi bi-translate"></i> <?= e(t('nav.choose_language')) ?>
    </span>
    <?php foreach ($langNames as $code => $name) : ?>
    <form method="post" action="<?= url('set-language.php') ?>" class="lang-switch-form">
        <?= csrf_field() ?>
        <input type="hidden" name="locale" value="<?= e($code) ?>">
        <input type="hidden" name="return_to" value="<?= $returnTo ?>">
        <button type="submit"
                class="lang-switch-btn <?= $code === $langCode ? 'active' : '' ?>"
                lang="<?= e($code) ?>"
                <?= $code === $langCode ? 'aria-current="true"' : '' ?>>
            <?= e($name) ?>
        </button>
    </form>
    <?php endforeach; ?>
</div>
