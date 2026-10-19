<?php

declare(strict_types=1);

/**
 * UX - trang chi tiết công tơ: nút "← Về căn hộ <MÃ CĂN>" + breadcrumb theo ngữ cảnh căn hộ (link trực tiếp /apartments/{apartment_id}).
 *   php tests/meter_apartment_nav_test.php
 * Chỉ đọc (GET) qua Apache với dữ liệu seed: BT1-DIEN / BT1-NUOC → căn BT1, S01-NUOC → S01, CC2305-DIEN → CC-2305. Không ghi DB.
 */
require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);

$results = [];
function check(string $g, string $n, bool $ok, string $d = ''): void
{
    global $results;
    $results[] = [$g, $n, $ok, $d];
}

function http(string $jar, string $method, string $url, $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

    return ['code' => $code, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function csrfFrom(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
}

function login(string $base, string $user): string
{
    $jar = sys_get_temp_dir() . '/mnav_' . $user . '_' . getmypid() . '.txt';
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => $user, 'password' => 'Password@123']);

    return $jar;
}

/** @return array{0:string,1:string} [breadcrumb text (gộp khoảng trắng), html breadcrumb] */
function crumbs(string $html): array
{
    preg_match('#<nav class="breadcrumb".*?</nav>#s', $html, $m);
    $nav = $m[0] ?? '';

    return [trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($nav)))), $nav];
}

$jars = [];
$seed = $pdo->query("SELECT m.id, m.meter_code, m.apartment_id, a.code FROM meters m JOIN apartments a ON a.id = m.apartment_id
    WHERE m.meter_code IN ('BT1-DIEN', 'BT1-NUOC', 'S01-NUOC', 'CC2305-DIEN') ORDER BY m.id")->fetchAll();
try {
    foreach (['bql01', 'kythuat01', 'ketoan01'] as $u) {
        $jars[$u] = login($base, $u);
    }
    check('SETUP', 'Có 4 công tơ seed để kiểm tra', count($seed) === 4);

    $G = 'NAV';
    foreach ($seed as $mt) {
        $page = http($jars['bql01'], 'GET', $base . '/meters/' . $mt['id']);
        $html = $page['body'];
        $href = base_url('/apartments/' . $mt['apartment_id']);
        $btn = '<a class="btn btn--ghost" href="' . $href . '">← Về căn hộ ' . htmlspecialchars($mt['code']) . '</a>';
        check($G, "{$mt['meter_code']}: trang 200, nút \"← Về căn hộ {$mt['code']}\" trỏ /apartments/{$mt['apartment_id']}", $page['code'] === 200 && str_contains($html, $btn));
        preg_match('#<div class="page-head__actions">(.*?)</div>\s*</div>#s', $html, $act);
        $actions = $act[1] ?? '';
        check($G, "{$mt['meter_code']}: nút nằm trong khu action phía trên, trước \"Sửa\" / \"Ngừng công tơ\"",
            str_contains($actions, '← Về căn hộ') && strpos($actions, '← Về căn hộ') < (int) strpos($actions, '>Sửa<') && str_contains($actions, 'Ngừng công tơ'));
        [$text, $nav] = crumbs($html);
        $expected = "Dashboard / Danh mục / Căn hộ / {$mt['code']} / Công tơ {$mt['meter_code']}";
        check($G, "{$mt['meter_code']}: breadcrumb \"{$expected}\" (căn hộ là link, công tơ là trang hiện tại)",
            $text === $expected && str_contains($nav, 'href="' . base_url('/apartments') . '">Căn hộ</a>') && str_contains($nav, 'href="' . $href . '">' . htmlspecialchars($mt['code']) . '</a>')
            && str_contains($nav, '<span class="breadcrumb__current">Công tơ ' . htmlspecialchars($mt['meter_code']) . '</span>') && !str_contains($nav, '>Công tơ</a>'), $text);
        $target = http($jars['bql01'], 'GET', $base . '/apartments/' . $mt['apartment_id']);
        check($G, "{$mt['meter_code']}: link đích không 404, đúng căn {$mt['code']}", $target['code'] === 200 && str_contains($target['body'], '<h1 class="page-title">Căn hộ ' . htmlspecialchars($mt['code'])), 'HTTP ' . $target['code']);
        check($G, "{$mt['meter_code']}: không dùng history.back / javascript: làm điều hướng", !str_contains($html, 'history.back') && !str_contains($html, 'href="javascript:'));
    }

    $G = 'RBAC';
    $bt1 = $seed[0];
    foreach (['kythuat01', 'ketoan01'] as $u) {
        $page = http($jars[$u], 'GET', $base . '/meters/' . $bt1['id']);
        $target = http($jars[$u], 'GET', $base . '/apartments/' . $bt1['apartment_id']);
        check($G, "{$u}: có meters.view + apartments.view → thấy nút + breadcrumb căn, mở được căn (200)", $page['code'] === 200 && str_contains($page['body'], '← Về căn hộ BT1') && $target['code'] === 200);
        check($G, "{$u}: không có meters.update / meters.deactivate → không thấy Sửa / Ngừng (giữ RBAC cũ)", !str_contains($page['body'], '/edit">Sửa</a>') && !str_contains($page['body'], 'Ngừng công tơ'));
    }
    $guest = sys_get_temp_dir() . '/mnav_guest_' . getmypid() . '.txt';
    @unlink($guest);
    $g1 = http($guest, 'GET', $base . '/meters/' . $bt1['id']);
    $g2 = http($guest, 'GET', $base . '/apartments/' . $bt1['apartment_id']);
    @unlink($guest);
    check($G, 'chưa đăng nhập → trang công tơ và trang căn hộ đều chuyển về /login', $g1['code'] === 302 && str_ends_with($g1['location'], '/login') && $g2['code'] === 302 && str_ends_with($g2['location'], '/login'));
    $missing = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM meters')->fetchColumn();
    check($G, 'công tơ không tồn tại → 404 (không lộ link căn)', http($jars['bql01'], 'GET', $base . '/meters/' . $missing)['code'] === 404);
} finally {
    foreach ($jars as $j) {
        @unlink($j);
    }
}

$pass = 0;
$current = '';
foreach ($results as [$g, $n, $ok, $d]) {
    if ($g !== $current) {
        echo "\n[{$g}]\n";
        $current = $g;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || $d === '' ? '' : "  -> {$d}") . "\n";
    $pass += $ok ? 1 : 0;
}
$total = count($results);
echo "\n{$pass}/{$total} PASS (HTTP qua {$base}, chỉ đọc)\n";
exit($pass === $total ? 0 : 1);
