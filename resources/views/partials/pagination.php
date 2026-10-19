<?php
/**
 * @var array{total:int,page:int,per_page:int,last_page:int} $pager
 * @var string $basePath   đường dẫn danh sách, vd "/buildings"
 * @var array  $filters    bộ lọc hiện tại (giữ nguyên khi chuyển trang)
 */
$from = $pager['total'] === 0 ? 0 : ($pager['page'] - 1) * $pager['per_page'] + 1;
$to = min($pager['total'], $pager['page'] * $pager['per_page']);
?>
<div class="pagination">
    <div class="pagination__info"><?= e(t('common.pagination.showing', ['from' => $from, 'to' => $to, 'total' => $pager['total']])) ?></div>
    <?php if ($pager['last_page'] > 1): ?>
        <div class="pagination__links">
            <?php if ($pager['page'] > 1): ?>
                <a class="pagination__link" href="<?= e(url_with($basePath, $filters, ['page' => $pager['page'] - 1])) ?>"><?= e(t('common.pagination.previous')) ?></a>
            <?php endif; ?>
            <?php for ($p = max(1, $pager['page'] - 2); $p <= min($pager['last_page'], $pager['page'] + 2); $p++): ?>
                <?php if ($p === $pager['page']): ?>
                    <span class="pagination__link is-current"><?= $p ?></span>
                <?php else: ?>
                    <a class="pagination__link" href="<?= e(url_with($basePath, $filters, ['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($pager['page'] < $pager['last_page']): ?>
                <a class="pagination__link" href="<?= e(url_with($basePath, $filters, ['page' => $pager['page'] + 1])) ?>"><?= e(t('common.pagination.next')) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
