<?php

declare(strict_types=1);

/**
 * UX "Thêm khu vực" - tự gợi ý Mã khu vực = <MÃ TÒA NHÀ>-<TÊN KHU CHUẨN HOÁ> (JS thuần) + backend vẫn là nguồn kiểm tra.
 *   php tests/zone_suggest_ui_test.php
 * 1) Markup thật của /zones/create (Apache, BQL) + script mô phỏng thao tác, chạy trong Chrome headless (--dump-dom).
 *    Tòa "RIVERSIDE-A1" / "SKY-B" chỉ thêm vào <select> trong trang test (không ghi DB). Không có Chrome -> phần JS SKIPPED.
 * 2) Backend qua Apache: mã trùng trong tòa / rỗng bị từ chối; tạo hợp lệ rồi xoá (khu E2E chưa có căn hộ).
 */

require dirname(__DIR__) . '/bootstrap.php';
restore_error_handler();
restore_exception_handler();

use App\Core\Database;

$base = rtrim((string) config('app.url'), '/');
$pdo = Database::connection();
$runId = date('His') . random_int(10, 99);
$jar = sys_get_temp_dir() . "/zsug_{$runId}.txt";

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

function q2(PDO $pdo, string $sql, array $p = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($p);

    return $st->fetchColumn();
}

try {
    @unlink($jar);
    $page = http($jar, 'GET', $base . '/login');
    $login = http($jar, 'POST', $base . '/login', ['csrf_token' => csrfFrom($page['body']), 'username' => 'bql01', 'password' => 'Password@123']);
    check('SETUP', 'Đăng nhập bql01', str_ends_with($login['location'], '/dashboard'));

    // ================================================================ Markup
    $G = 'MARKUP';
    $create = http($jar, 'GET', $base . '/zones/create');
    $html = $create['body'];
    check($G, 'màn Thêm: form có data-zone-suggest, option tòa nhà mang data-code', $create['code'] === 200 && str_contains($html, 'data-zone-suggest') && (bool) preg_match('/<option value="1" data-code="CHUNGCU-A"/', $html));
    check($G, 'helper text Mã + Tên khu vực, có aria-describedby', str_contains($html, 'Mã khu vực được hệ thống tự gợi ý từ Tòa nhà và Tên khu vực. Bạn có thể chỉnh sửa nếu cần.')
        && str_contains($html, 'Tên hiển thị dễ hiểu, ví dụ: Khu biệt thự, Khu thương mại, Tầng 1.') && str_contains($html, 'aria-describedby="zone_code_hint"') && str_contains($html, 'aria-describedby="zone_name_hint"'));
    check($G, 'ô mã vẫn required + maxlength 30, có <label>', (bool) preg_match('/id="code" name="code" maxlength="30" required/', $html) && str_contains($html, '<label for="code">Mã khu vực'));
    $edit = http($jar, 'GET', $base . '/zones/1/edit');
    check($G, 'màn Sửa: KHÔNG bật auto-suggest (không đổi mã đã lưu)', $edit['code'] === 200 && !str_contains($edit['body'], 'data-zone-suggest') && !str_contains($edit['body'], 'tự gợi ý từ Tòa nhà'));

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
  function el(id) { return document.getElementById(id); }
  var b = el('building_id'), type = el('zone_type'), name = el('name'), code = el('code');
  function addOpt(c) { var o = document.createElement('option'); o.value = 'test-' + c; o.setAttribute('data-code', c); o.textContent = c; b.appendChild(o); return o; }
  function choose(s, v) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
  function typeIn(f, v) { f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); }
  var river = addOpt('RIVERSIDE-A1'), sky = addOpt('SKY-B');
  choose(b, ''); typeIn(name, 'Khu biệt thự'); R.noBuilding = code.value;
  choose(b, river.value); R.case1 = code.value;
  typeIn(name, 'Khu thương mại'); R.case2 = code.value;
  typeIn(name, 'Tầng 1 – Đường Nguyễn Huệ'); R.case3 = code.value;
  choose(b, sky.value); R.changeBuilding = code.value;
  choose(type, type.options[1] ? type.options[1].value : ''); R.typeNoEffect = code.value;
  typeIn(code, 'KHU-RIENG'); typeIn(name, 'Khu thương mại'); choose(b, river.value); choose(type, ''); R.case4 = code.value;
  typeIn(code, ''); typeIn(name, 'Khu biệt thự'); R.case5 = code.value;
  R.pure = [ZoneSuggest.code('RIVERSIDE-A1', 'Khu biệt thự'), ZoneSuggest.code('', 'Khu A'), ZoneSuggest.code('RIVERSIDE-A1', '  @@ '),
    ZoneSuggest.code('RIVERSIDE-A1', '  Khu   Ánh   Dương  '), ZoneSuggest.code('RIVERSIDE-A1', 'Khu đô thị mới phía Nam'), ZoneSuggest.code('CHUNGCU-A', 'Khu Shophouse')];
  R.editable = [code.readOnly, code.disabled];
  var pre = document.createElement('pre'); pre.id = '__result'; pre.textContent = JSON.stringify(R); document.body.appendChild(pre);
})();
</script>
JS;
        $file = sys_get_temp_dir() . "/zsug_{$runId}.html";
        file_put_contents($file, str_replace('</body>', $harness . '</body>', $html));
        $out = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run --virtual-time-budget=5000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        @unlink($file);
        preg_match('#<pre id="__result">(.*?)</pre>#s', $out, $m);
        $r = json_decode(html_entity_decode($m[1] ?? ''), true);
        if (!is_array($r)) {
            check($G, 'chạy được script kiểm tra trong Chrome headless', false, substr($out, 0, 300));
        } else {
            check($G, 'chưa chọn tòa nhà → chưa gợi ý (ô mã trống)', $r['noBuilding'] === '', (string) $r['noBuilding']);
            check($G, 'Case 1: RIVERSIDE-A1 + "Khu biệt thự" → RIVERSIDE-A1-KHU-BIET-THU', $r['case1'] === 'RIVERSIDE-A1-KHU-BIET-THU', (string) $r['case1']);
            check($G, 'Case 2: "Khu thương mại" → RIVERSIDE-A1-KHU-THUONG-MAI', $r['case2'] === 'RIVERSIDE-A1-KHU-THUONG-MAI', (string) $r['case2']);
            check($G, 'Case 3: có dấu tiếng Việt + ký tự đặc biệt → RIVERSIDE-A1-TANG-1-DUONG-NGUY (cắt ≤ 30, không "-" cuối)', $r['case3'] === 'RIVERSIDE-A1-TANG-1-DUONG-NGUY', (string) $r['case3']);
            check($G, 'đổi tòa nhà (chưa sửa tay) → mã cập nhật theo tòa mới', $r['changeBuilding'] === 'SKY-B-TANG-1-DUONG-NGUYEN-HUE', (string) $r['changeBuilding']);
            check($G, 'đổi Loại khu → không ảnh hưởng mã', $r['typeNoEffect'] === 'SKY-B-TANG-1-DUONG-NGUYEN-HUE', (string) $r['typeNoEffect']);
            check($G, 'Case 4: sửa mã tay "KHU-RIENG" → đổi tên / tòa / loại: không ghi đè', $r['case4'] === 'KHU-RIENG', (string) $r['case4']);
            check($G, 'Case 5: xoá trống mã → tự gợi ý lại', $r['case5'] === 'RIVERSIDE-A1-KHU-BIET-THU', (string) $r['case5']);
            check($G, 'hàm gợi ý: thiếu tòa / tên rỗng sau chuẩn hoá → rỗng; gộp khoảng trắng; bỏ dấu; ≤ 30 ký tự; không tự thêm -2 khi trùng (KHU-SHOPHOUSE)',
                $r['pure'] === ['RIVERSIDE-A1-KHU-BIET-THU', '', '', 'RIVERSIDE-A1-KHU-ANH-DUONG', 'RIVERSIDE-A1-KHU-DO-THI-MOI-PH', 'CHUNGCU-A-KHU-SHOPHOUSE'], json_encode($r['pure'], JSON_UNESCAPED_UNICODE));
            check($G, 'ô mã không readonly / disabled (người dùng sửa được)', $r['editable'] === [false, false]);
        }
    }

    // ================================================================ Backend vẫn là nguồn kiểm tra
    $G = 'BACKEND';
    // tòa test riêng (có sẵn khu KHU-BT để thử trùng mã) - không tạo khu trong tòa của người dùng
    $pdo->prepare("INSERT INTO buildings (code, name, status, created_by) VALUES (?, ?, 'hoat_dong', 1)")->execute(["UXZB{$runId}", "UX Zone building {$runId}"]);
    $testBuilding = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO zones (building_id, code, name, zone_type, status) VALUES (?, 'KHU-BT', ?, NULL, 'hoat_dong')")->execute([$testBuilding, "UX Khu BT {$runId}"]);
    $post = static fn (array $over) => $over + ['csrf_token' => csrfFrom($create['body']), 'building_id' => (string) $testBuilding, 'zone_type' => '', 'code' => "UXZ{$runId}-KHU", 'name' => "UX Zone {$runId}"];
    $count = static fn () => (int) $pdo->query("SELECT COUNT(*) FROM zones WHERE building_id = {$testBuilding} AND name = 'UX Zone {$runId}'")->fetchColumn();
    http($jar, 'POST', $base . '/zones', $post(['code' => 'KHU-BT']));
    $form = http($jar, 'GET', $base . '/zones/create')['body'];
    check($G, 'Case 6: mã trùng trong tòa (KHU-BT) → backend từ chối "Mã khu vực đã tồn tại trong tòa nhà này."', $count() === 0 && str_contains($form, 'Mã khu vực đã tồn tại trong tòa nhà này.'));
    http($jar, 'POST', $base . '/zones', $post(['code' => '']));
    $form = http($jar, 'GET', $base . '/zones/create')['body'];
    check($G, 'mã rỗng (bỏ qua gợi ý) → backend từ chối', $count() === 0 && str_contains($form, 'Vui lòng nhập mã khu vực.'));
    http($jar, 'POST', $base . '/zones', $post(['code' => str_repeat('X', 31)]));
    check($G, 'mã > 30 ký tự → backend từ chối', $count() === 0);
    $r = http($jar, 'POST', $base . '/zones', $post([]));
    $saved = $pdo->query("SELECT code, building_id FROM zones WHERE building_id = {$testBuilding} AND name = 'UX Zone {$runId}'")->fetch();
    check($G, 'giá trị hợp lệ → tạo được, lưu đúng mã', $r['code'] === 302 && $saved !== false && $saved['code'] === "UXZ{$runId}-KHU", json_encode($saved, JSON_UNESCAPED_UNICODE));
} finally {
    // chỉ xoá tòa / khu do lượt chạy tạo (tòa test + khu trong tòa đó; khu mang mã lượt chạy)
    $testBuilding = (int) ($testBuilding ?? 0);
    $pdo->prepare('DELETE FROM zones WHERE building_id = ? OR code LIKE ?')->execute([$testBuilding, "UXZ{$runId}%"]);
    $pdo->prepare('DELETE FROM buildings WHERE id = ?')->execute([$testBuilding]);
    @unlink($jar);
    $left = (int) q2($pdo, 'SELECT (SELECT COUNT(*) FROM zones WHERE building_id = ? OR code LIKE ?) + (SELECT COUNT(*) FROM buildings WHERE id = ?)', [$testBuilding, "UXZ{$runId}%", $testBuilding]);
    check('CLEANUP', 'Đã xoá tòa / khu vực E2E của lượt chạy (audit giữ lại)', $left === 0, "còn {$left}");
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
