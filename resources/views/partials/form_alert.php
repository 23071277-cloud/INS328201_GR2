<?php
/** @var array $form trạng thái form (errors/old) - lỗi chung khoá '_' đã hiện qua flash. */
?>
<?php if (!empty($form['errors']) && empty($form['errors']['_'])): ?>
    <div class="form-summary"><?= t('ui.there_are') ?> <?= count($form['errors']) ?> <?= t('ui.invalid_fields_see_the_details_below') ?></div>
<?php endif; ?>
