<?php
/**
 * Hướng dẫn theo vai trò: mỗi bước có tiêu đề ngắn, mô tả 1-2 câu và nút đi thẳng tới đúng màn hình.
 * Nút chỉ hiện khi vai trò có quyền vào màn hình đó.
 */
$role = (string) (auth_user()['role'] ?? '');
$steps = [
    'bql' => [
        ['s1', '/dashboard', 'dashboard.view'],
        ['s2', '/periods', 'meter_readings.approve'],
        ['s3', '/periods', 'billing.approve'],
        ['s4', '/payments', 'payments.view'],
        ['s5', '/alerts', 'alerts.view'],
    ],
    'ky_thuat' => [
        ['s1', '/periods', 'billing_periods.view'],
        ['s2', '/periods', 'meter_readings.create'],
        ['s3', '/periods', 'meter_readings.import'],
        ['s4', '/alerts', 'alerts.view'],
        ['s5', '/readings', 'meter_readings.view'],
    ],
    'ke_toan' => [
        ['s1', '/periods', 'billing.view'],
        ['s2', '/periods', 'billing.confirm'],
        ['s3', '/email-notifications', 'email_notifications.view'],
        ['s4', '/payments', 'payments.view'],
        ['s5', '/reports/utility', 'reports.view'],
    ],
];
$list = $steps[$role] ?? [];
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e(t('help.title')) ?></h1>
        <p class="page-subtitle"><?= e(t('help.subtitle.' . ($role !== '' ? $role : 'bql'))) ?></p>
    </div>
</div>

<ol class="help-steps">
    <?php foreach ($list as $i => [$key, $path, $perm]): ?>
        <li class="help-step">
            <span class="help-step__no" aria-hidden="true"><?= $i + 1 ?></span>
            <h2 class="help-step__title"><?= e(t('help.' . $role . '.' . $key . '_title')) ?></h2>
            <p class="help-step__desc"><?= e(t('help.' . $role . '.' . $key . '_desc')) ?></p>
            <?php if (can($perm)): ?>
                <a class="btn btn--primary" href="<?= e(base_url($path)) ?>"><?= e(t('help.' . $role . '.' . $key . '_btn')) ?></a>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

<p class="help-note"><?= e(t('help.tip')) ?></p>
