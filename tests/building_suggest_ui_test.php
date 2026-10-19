<?php

declare(strict_types=1);

/**
 * UX "Thêm tòa nhà" - tự gợi ý Mã tòa nhà từ Tên (JS thuần) + backend vẫn là nguồn kiểm tra.
 *   php tests/building_suggest_ui_test.php
 * 1) Markup thật của /buildings/create (Apache, BQL) + script mô phỏng thao tác, chạy trong Chrome headless (--dump-dom).
 *    Không có Chrome -> phần JS SKIPPED (không tính lỗi).
 * 2) Backend qua Apache: mã trùng / rỗng bị từ chối; tạo hợp lệ rồi xoá (tòa nhà E2E chưa có khu).
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = sys_get_temp_dir() . "/bsug_{$runId}.txt";
$maxBuilding = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM buildings')->fetchColumn();

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

function findChrome(): ?string
{
    foreach ([(string) getenv('CHROME_BIN'), '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
        if ($c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return null;
}

try {
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $login = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => 'bql01', 'password' => 'Password@123']);
    check('SETUP', 'Đăng nhập bql01', str_ends_with($login['location'], '/dashboard'));

    // ================================================================ Markup
    $G = 'MARKUP';
    $create = http($jar, 'GET', $base . '/buildings/create');
    $html = $create['body'];
    check($G, 'màn Thêm: form có data-building-suggest', $create['code'] === 200 && str_contains($html, 'data-building-suggest'));
    check($G, 'helper text mới + aria-describedby', str_contains($html, 'Mã tòa nhà được hệ thống tự gợi ý từ tên. Bạn có thể chỉnh sửa nếu cần.') && str_contains($html, 'aria-describedby="building_code_hint"'));
    check($G, 'ô mã vẫn required + maxlength 30, có <label>', (bool) preg_match('/id="code" name="code" maxlength="30" required/', $html) && str_contains($html, '<label for="code">Mã tòa nhà'));
    $edit = http($jar, 'GET', $base . '/buildings/1/edit');
    check($G, 'màn Sửa: KHÔNG bật auto-suggest (không đổi mã đã lưu)', $edit['code'] === 200 && !str_contains($edit['body'], 'data-building-suggest') && !str_contains($edit['body'], 'tự gợi ý từ tên'));

    // ================================================================ Hành vi JS (Chrome headless)
    $G = 'JS';
    $chrome = findChrome();
    if ($chrome === null) {
        check($G, 'SKIPPED: không tìm thấy Chrome/Chromium (đặt CHROME_BIN) - không kiểm được hành vi JS', true);
    } else {
        $harness = <<<'JS'
<script>
(function () {
  var R = {};
  var name = document.getElementById('name'), code = document.getElementById('code');
  function type(f, v) { f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); }
  type(name, 'Riverside A1'); R.case1 = code.value;
  type(name, 'Chung cư A'); R.case2 = code.value;
  type(name, 'Tòa Đông Nam Phường Bến Thành'); R.case3 = code.value;
  type(name, '   Sunrise    City   B2  '); R.case4 = code.value;
  type(code, 'MY-CODE'); type(name, 'Riverside A2'); R.case5 = code.value;
  type(code, ''); type(name, 'Riverside A3'); R.cleared = code.value;
  R.pure = [BuildingSuggest.code('Tòa nhà @#$ (Khu 1) & 2!!'), BuildingSuggest.code('--A -- B--'), BuildingSuggest.code(''), BuildingSuggest.code('Ánh Dương'),
    BuildingSuggest.code('Khu đô thị mới Thủ Thiêm phía Nam sông Sài Gòn'), BuildingSuggest.code('Tòa nhà Ủy ban Ưu tiên'), BuildingSuggest.code('A 1234567890123456789012345678 B')];
  R.editable = [code.readOnly, code.disabled];
  var pre = document.createElement('pre'); pre.id = '__result'; pre.textContent = JSON.stringify(R); document.body.appendChild(pre);
})();
</script>
JS;
        $file = sys_get_temp_dir() . "/bsug_{$runId}.html";
        file_put_contents($file, str_replace('</body>', $harness . '</body>', $html));
        $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --virtual-time-budget=5000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        @unlink($file);
        preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
        $r = json_decode(html_entity_decode($m[1] ?? ''), true);
        if (!is_array($r)) {
            check($G, 'chạy được script kiểm tra trong Chrome headless', false, substr($out, 0, 300));
        } else {
            check($G, 'Case 1: "Riverside A1" → RIVERSIDE-A1', $r['case1'] === 'RIVERSIDE-A1', (string) $r['case1']);
            check($G, 'Case 2: "Chung cư A" → CHUNG-CU-A (khoảng trắng → "-")', $r['case2'] === 'CHUNG-CU-A', (string) $r['case2']);
            check($G, 'Case 3: có dấu tiếng Việt (đ, ư, ơ...) → TOA-DONG-NAM-PHUONG-BEN-THANH', $r['case3'] === 'TOA-DONG-NAM-PHUONG-BEN-THANH', (string) $r['case3']);
            check($G, 'Case 4: nhiều khoảng trắng / đầu cuối → SUNRISE-CITY-B2', $r['case4'] === 'SUNRISE-CITY-B2', (string) $r['case4']);
            check($G, 'Case 5: sửa mã tay "MY-CODE" → đổi tên: không ghi đè', $r['case5'] === 'MY-CODE', (string) $r['case5']);
            check($G, 'xoá trống ô mã → quay lại tự gợi ý theo tên', $r['cleared'] === 'RIVERSIDE-A3', (string) $r['cleared']);
            check($G, 'chuẩn hoá: bỏ ký tự đặc biệt, gộp/cắt "-", rỗng, Á/Ủ/Ư, tối đa 30 ký tự và không kết thúc bằng "-"',
                $r['pure'] === ['TOA-NHA-KHU-1-2', 'A-B', '', 'ANH-DUONG', 'KHU-DO-THI-MOI-THU-THIEM-PHIA', 'TOA-NHA-UY-BAN-UU-TIEN', 'A-1234567890123456789012345678'],
                json_encode($r['pure'], JSON_UNESCAPED_UNICODE));
            check($G, 'ô mã không readonly / disabled (người dùng sửa được)', $r['editable'] === [false, false]);
        }
    }

    // ================================================================ Backend vẫn là nguồn kiểm tra
    $G = 'BACKEND';
    $post = static fn (array $over) => $over + ['csrf_token' => csrfFrom($create['body']), 'code' => "UXB{$runId}-A1", 'name' => "UX Building {$runId}"];
    $count = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM buildings WHERE id > {$maxBuilding} AND name = 'UX Building {$runId}'")->fetchColumn();
    http($jar, 'POST', $base . '/buildings', $post(['code' => 'CHUNGCU-A']));
    $form = http($jar, 'GET', $base . '/buildings/create')['body'];
    check($G, 'Case 6: mã trùng (CHUNGCU-A) → backend từ chối "Mã tòa nhà đã tồn tại."', $count() === 0 && str_contains($form, 'Mã tòa nhà đã tồn tại.'));
    http($jar, 'POST', $base . '/buildings', $post(['code' => 'chungcu-a']));
    check($G, 'mã trùng khác hoa thường → backend từ chối', $count() === 0);
    http($jar, 'POST', $base . '/buildings', $post(['code' => '']));
    $form = http($jar, 'GET', $base . '/buildings/create')['body'];
    check($G, 'mã rỗng (bỏ qua gợi ý) → backend từ chối', $count() === 0 && str_contains($form, 'Vui lòng nhập mã tòa nhà.'));
    http($jar, 'POST', $base . '/buildings', $post(['code' => str_repeat('X', 31)]));
    check($G, 'mã > 30 ký tự → backend từ chối', $count() === 0);
    $r = http($jar, 'POST', $base . '/buildings', $post([]));
    $saved = $pdo->query("SELECT code, name FROM buildings WHERE id > {$maxBuilding} AND name = 'UX Building {$runId}'")->fetch();
    check($G, 'giá trị hợp lệ → tạo được, lưu đúng mã', $r['code'] === 302 && $saved !== false && $saved['code'] === "UXB{$runId}-A1", json_encode($saved, JSON_UNESCAPED_UNICODE));
} finally {
    // chỉ tòa do lượt chạy tạo (tên mang mã lượt chạy) - không đụng tòa của người dùng
    $pdo->exec("DELETE FROM buildings WHERE id > {$maxBuilding} AND name = 'UX Building {$runId}' AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.building_id = buildings.id)");
    @unlink($jar);
    check('CLEANUP', 'Đã xoá tòa nhà E2E của lượt chạy (audit giữ lại)', (int) $pdo->query("SELECT COUNT(*) FROM buildings WHERE name = 'UX Building {$runId}'")->fetchColumn() === 0);
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
echo "\n{$pass}/{$total} PASS (UI + HTTP qua {$base}, run {$runId})\n";
exit($pass === $total ? 0 : 1);
