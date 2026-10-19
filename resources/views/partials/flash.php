<?php
/** @var array $data không bắt buộc - partial này chỉ đọc Flash trực tiếp từ Session */
?>
<?php foreach (['success', 'error', 'info'] as $flashType): ?>
    <?php foreach (\App\Core\Flash::get($flashType) as $msg): ?>
        <div data-flash="<?= e($flashType) ?>" class="alert alert--<?= e($flashType) ?>"><?= e(message_t($msg)) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>
