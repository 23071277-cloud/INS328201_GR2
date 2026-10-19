<?php
/** @var array $log */
$breadcrumbs = [[t('crumbs.system'), null], [t('menu.audit_logs'), '/audit-logs'], ['#' . $log['id'], null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
$pretty = static fn ($v): string => $v === null ? '—' : (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= t('ui.audit_record') ?><?= (int) $log['id'] ?> — <?= e($log['action']) ?></h1>
        <p class="page-subtitle"><?= t('ui.read_only_br_04_sensitive_values') ?> <code>***</code>.</p>
    </div>
</div>
<div class="panel">
    <dl class="detail-grid">
        <div><dt><?= t('common.fields.timestamp') ?></dt><dd><?= e($log['created_at']) ?></dd></div>
        <div><dt><?= t('common.fields.actor') ?></dt><dd><?= $log['user_id'] !== null ? e(($log['username'] ?? '#' . $log['user_id']) . ' — ' . ($log['user_name'] ?? '')) : t('crumbs.system') ?></dd></div>
        <div><dt><?= t('common.fields.action') ?></dt><dd><?= e($log['action']) ?></dd></div>
        <div><dt><?= t('ui.entity') ?></dt><dd><?= e($log['entity_type'] . ($log['entity_id'] !== null ? ' #' . $log['entity_id'] : '')) ?></dd></div>
        <div><dt>IP</dt><dd><?= e($log['ip_address'] ?? '—') ?></dd></div>
    </dl>
    <div class="form-grid">
        <div><h2 class="panel__title"><?= t('common.fields.old_value') ?></h2><pre class="code-block"><?= e($pretty($log['old_value'])) ?></pre></div>
        <div><h2 class="panel__title"><?= t('common.fields.new_value') ?></h2><pre class="code-block"><?= e($pretty($log['new_value'])) ?></pre></div>
    </div>
    <p class="form-hint"><a href="<?= e(url_with('/audit-logs', ['entity_type' => $log['entity_type'], 'entity_id' => (string) $log['entity_id']])) ?>"><?= t('ui.other_records_for_the_same_entity') ?></a></p>
</div>
