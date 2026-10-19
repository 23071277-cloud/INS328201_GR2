<?php
/**
 * Nút ngừng/kết thúc hiệu lực + modal xác nhận (public/assets/js/app.js).
 * Không có JS thì form vẫn gửi bình thường; Service luôn kiểm tra lại điều kiện.
 * @var string $deactivateAction  đường dẫn POST, vd "/buildings/3/deactivate"
 * @var string $deactivateMessage nội dung xác nhận
 * @var string $deactivateLabel   chữ trên nút
 */
?>
<form method="post" action="<?= e(base_url($deactivateAction)) ?>" class="inline-form js-confirm"
      data-confirm-title="<?= e(t('common.confirm')) ?>" data-confirm-message="<?= e($deactivateMessage) ?>" data-confirm-button="<?= e($deactivateLabel) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--danger btn--sm"><?= e($deactivateLabel) ?></button>
</form>
