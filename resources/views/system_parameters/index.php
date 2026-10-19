<?php
/** @var array $rows */
$breadcrumbs = [[t('crumbs.system'), null], [t('page.system_parameters'), null]];
require BASE_PATH . '/resources/views/partials/breadcrumb.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('page.system_parameters')) ?></h1>
        <p class="page-subtitle"><?= e(t('ui.system_params_subtitle')) ?></p>
    </div>
</div>

<div class="panel">
    <div class="table-wrap sp-table-wrap">
        <table class="data-table sp-table">
            <thead>
                <tr>
                    <th class="sp-table__th-key"><?= e(t('ui.key')) ?></th>
                    <th><?= e(t('ui.parameters')) ?></th>
                    <th class="sp-table__th-num"><?= e(t('ui.stored_value')) ?></th>
                    <th class="sp-table__th-num"><?= e(t('ui.value_in_effect')) ?></th>
                    <th class="sp-table__th-num"><?= e(t('ui.limits')) ?></th>
                    <th class="sp-table__th-src">
                        <?= e(t('common.fields.source')) ?>
                        <span class="sp-tooltip" tabindex="0" aria-label="<?= e(t('ui.source_tooltip_hint')) ?>">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        </span>
                    </th>
                    <th><?= e(t('ui.last_modified')) ?></th>
                    <th style="width:72px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $p): $d = $p['def']; ?>
                <tr class="sp-table__row">
                    <!-- Khóa kỹ thuật -->
                    <td><code class="sp-key"><?= e($p['key']) ?></code></td>

                    <!-- Tên tham số + mô tả áp dụng -->
                    <td class="sp-table__label">
                        <span class="sp-table__param-name"><?= e(message_t($d['label'])) ?></span>
                        <span class="sp-table__param-desc"><?= e(message_t($d['applies'])) ?></span>
                    </td>

                    <!-- Giá trị lưu -->
                    <td class="sp-table__num">
                        <?php if ($p['row'] !== null): ?>
                            <?= e($p['row']['config_value']) ?>
                            <?php if (!$p['valid']): ?>
                                <span class="badge badge--warning"><?= e(t('ui.value_invalid')) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sp-table__no-row"><?= e(t('lit.em_no_row_in_the_db')) ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- Giá trị đang áp dụng -->
                    <td class="sp-table__num">
                        <strong class="sp-table__effective"><?= e($p['effective']) ?></strong>
                    </td>

                    <!-- Giới hạn -->
                    <td class="sp-table__num sp-table__limits">
                        <?= e(match ($d['type']) {
                            'integer' => t('lit.integer_range', ['min' => $d['min'], 'max' => $d['max']]),
                            'string'  => t('lit.string_range',  ['min' => $d['min'], 'max' => $d['max']]),
                            'enum'    => implode(' / ', array_keys($d['values'])),
                            default   => '',
                        }) ?>
                    </td>

                    <!-- Nguồn: ẩn vào tooltip icon (i) -->
                    <td class="sp-table__src">
                        <?php $src = message_t($d['source']); ?>
                        <?php if ($src !== ''): ?>
                            <span class="sp-src-badge" tabindex="0">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <span class="sp-src-tip" role="tooltip"><?= e($src) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="sp-table__dash">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Lần sửa cuối -->
                    <td class="sp-table__ts">
                        <?php if ($p['row'] !== null): ?>
                            <span class="sp-table__by"><?= e($p['row']['updated_by_name'] ?? '—') ?></span>
                            <span class="sp-table__at"><?= e($p['row']['updated_at']) ?></span>
                        <?php else: ?>
                            <span class="sp-table__dash">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Nút Sửa -->
                    <td class="sp-table__action">
                        <?php if ($p['row'] !== null && can('system_parameters.manage')): ?>
                            <a class="action-edit sp-edit-btn" href="<?= e(base_url('/system-parameters/' . $p['row']['id'] . '/edit')) ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <?= e(t('common.actions.edit')) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
