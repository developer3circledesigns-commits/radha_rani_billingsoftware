<?php
/**
 * Sample layout switcher — floating bar to compare the 5 login landing concepts.
 * Expects: $currentLayout (int 1..5)
 */
$currentLayout = $currentLayout ?? 1;
$layouts = [
    1 => ['name' => 'Executive Split', 'slug' => 'login-layout-1.php'],
    2 => ['name' => 'Luxury Hotel',    'slug' => 'login-layout-2.php'],
    3 => ['name' => 'Marketing Site',  'slug' => 'login-layout-3.php'],
    4 => ['name' => 'Portal Hub',      'slug' => 'login-layout-4.php'],
    5 => ['name' => 'Dark Elegant',    'slug' => 'login-layout-5.php'],
];
$total = count($layouts);
?>
<nav class="sample-bar" aria-label="Sample layout switcher">
    <div class="sample-bar-inner">
        <span class="sample-badge">Concept <strong><?= $currentLayout ?>/<?= $total ?></strong> · <?= e($layouts[$currentLayout]['name']) ?></span>

        <span class="sample-nav">
            <?php foreach ($layouts as $n => $l) : ?>
            <a class="sample-dot <?= $n === $currentLayout ? 'is-active' : '' ?>"
               href="<?= url($l['slug']) ?>"
               title="Layout <?= $n ?> — <?= e($l['name']) ?>"
               aria-label="Go to layout <?= $n ?> — <?= e($l['name']) ?>"></a>
            <?php endforeach; ?>
        </span>

        <span class="sample-links">
            <?php if ($currentLayout > 1) : ?>
            <a class="sample-link" href="<?= url($layouts[$currentLayout - 1]['slug']) ?>">
                <i class="bi bi-chevron-left"></i> Prev
            </a>
            <?php endif; ?>
            <?php if ($currentLayout < $total) : ?>
            <a class="sample-link" href="<?= url($layouts[$currentLayout + 1]['slug']) ?>">
                Next <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </span>

        <a class="sample-link sample-url" href="<?= url('login.php') ?>">
            <span>Current login.php</span> <i class="bi bi-box-arrow-up-right"></i>
        </a>
    </div>
</nav>