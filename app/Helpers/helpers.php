<?php

declare(strict_types=1);

/**
 * Hàm dùng chung toàn hệ thống. File này được require trực tiếp trong
 * bootstrap.php (không qua autoload class vì đây là hàm, không phải class).
 */

use App\Core\Config;
use App\Core\Flash;
use App\Core\Session;

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        // ?v=<thời điểm sửa file>: đổi CSS / JS là đổi URL -> trình duyệt không dùng bản cũ trong cache cho HTML mới
        // (Apache không gửi Cache-Control nên trình duyệt có thể tự giữ file tĩnh một thời gian).
        $path = ltrim($path, '/');
        $mtime = @filemtime(BASE_PATH . '/public/assets/' . $path);

        return base_url('/assets/' . $path) . ($mtime ? '?v=' . $mtime : '');
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        return e(Flash::old($key, $default));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return (string) Session::get('csrf_token', '');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('auth_user')) {
    /** @return array{id:int,username:string,full_name:string,role:string}|null */
    function auth_user(): ?array
    {
        if (!Session::has('user_id')) {
            return null;
        }

        return [
            'id' => (int) Session::get('user_id'),
            'username' => (string) Session::get('username'),
            'full_name' => (string) Session::get('full_name'),
            'role' => (string) Session::get('user_role'),
        ];
    }
}

if (!function_exists('can')) {
    /** RBAC phía view: ẩn/hiện menu, nút bấm theo quyền (FN-01.04). */
    function can(string $permissionKey): bool
    {
        $user = auth_user();
        if ($user === null) {
            return false;
        }

        $allowedRoles = config('permissions.' . $permissionKey, []);

        return in_array($user['role'], $allowedRoles, true);
    }
}

if (!function_exists('format_money')) {
    function format_money($amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        $url = base_url($path);
        if (\App\Core\Request::isLive()) {
            // PRG cho thao tác live: trình duyệt tự tải trang đích (flash vẫn nằm trong session) và chỉ thay vùng nội dung
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => true, 'redirect' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        header('Location: ' . $url);
        exit;
    }
}

// ---------------------------------------------------------------------------
// STEP 6 (FN-02): helper hiển thị dùng chung cho các màn hình danh mục.
// Chỉ định dạng/escape dữ liệu - KHÔNG chứa business rule.
// ---------------------------------------------------------------------------

if (!function_exists('field_error')) {
    /** HTML lỗi của một trường (đã escape) từ trạng thái form. */
    function field_error(array $form, string $field): string
    {
        $message = $form['errors'][$field][0] ?? null;

        return $message === null ? '' : '<div class="form-error">' . e(message_t((string) $message)) . '</div>';
    }
}

if (!function_exists('has_error')) {
    function has_error(array $form, string $field): bool
    {
        return !empty($form['errors'][$field]);
    }
}

if (!function_exists('form_value')) {
    /** Giá trị hiển thị trong form: input cũ (khi lỗi) > bản ghi đang sửa > mặc định. Chưa escape. */
    function form_value(array $form, ?array $record, string $key, string $default = ''): string
    {
        if (array_key_exists($key, $form['old'] ?? [])) {
            return (string) $form['old'][$key];
        }
        if ($record !== null && array_key_exists($key, $record) && $record[$key] !== null) {
            return (string) $record[$key];
        }

        return $default;
    }
}

if (!function_exists('t')) {
    /** Nhãn giao diện theo ngôn ngữ hiện tại (vi / en), vd t('menu.dashboard'), t('common.pagination.showing', ['from' => 1]). */
    function t(string $key, array $replace = []): string
    {
        return \App\Core\Lang::get($key, $replace);
    }
}

if (!function_exists('locale')) {
    /** Ngôn ngữ giao diện hiện tại: 'vi' (mặc định) hoặc 'en' - lưu trong session. */
    function locale(): string
    {
        return \App\Core\Lang::locale();
    }
}

if (!function_exists('master_labels')) {
    /** Cả map nhãn ENUM của config/master_data.php (cho dropdown lọc / form) theo ngôn ngữ hiện tại; khoá = mã lưu DB giữ nguyên. */
    function master_labels(string $group): array
    {
        $out = [];
        foreach (array_keys((array) config('master_data.' . $group, [])) as $value) {
            $out[$value] = label_of($group, (string) $value);
        }

        return $out;
    }
}

if (!function_exists('label_t')) {
    /**
     * Nhãn HIỂN THỊ song ngữ cho một giá trị: tiếng Việt = $vi (nguồn gốc, không đổi); tiếng Anh = resources/lang/en.php "labels.<$key>" nếu có.
     * CHỈ dùng ở view / helper hiển thị - không dùng khi dựng chuỗi lưu DB (email, audit, lý do cảnh báo...).
     */
    function label_t(string $key, string $vi): string
    {
        return locale() !== 'vi' && \App\Core\Lang::has('labels.' . $key) ? t('labels.' . $key) : $vi;
    }
}

if (!function_exists('config_label')) {
    /** Nhãn hiển thị của mã trạng thái trong một map config, vd config_label('readings.period_status', 'mo'). Mã lưu DB không đổi. */
    function config_label(string $path, $value, ?string $fallback = null): string
    {
        $value = (string) $value;
        $vi = config($path, [])[$value] ?? null;

        return label_t($path . '.' . $value, is_string($vi) ? $vi : ($fallback ?? $value));
    }
}

if (!function_exists('config_labels')) {
    /** Cả map nhãn (cho dropdown / chú giải), đã theo ngôn ngữ hiện tại; khoá = mã lưu DB giữ nguyên. */
    function config_labels(string $path): array
    {
        $out = [];
        foreach ((array) config($path, []) as $value => $vi) {
            if (is_array($vi) && is_string($vi['label'] ?? null)) {
                $vi['label'] = label_t($path . '.' . $value, $vi['label']); // vd pricing.fee_type: ['label' => ..., 'unit' => ...]
            }
            $out[$value] = is_string($vi) ? label_t($path . '.' . $value, $vi) : $vi;
        }

        return $out;
    }
}

if (!function_exists('message_t')) {
    /**
     * Bản dịch HIỂN THỊ của thông điệp hệ thống tiếng Việt (flash, tiêu đề, lỗi form, danh sách lý do) - tầng C (dual-use).
     * Nguồn tiếng Việt trong service/controller giữ nguyên; tiếng Việt => trả lại nguyên văn. Tiếng Anh => tra resources/lang/messages_en.php
     * (khớp nguyên câu, rồi khớp mẫu {x}, rồi tách theo "; " / ", "); không khớp => giữ nguyên văn. KHÔNG dùng khi dựng chuỗi lưu DB / email / export.
     */
    function message_t(?string $vi): string
    {
        $vi = (string) $vi;
        if ($vi === '' || locale() === 'vi') {
            return $vi;
        }

        static $exact = null, $fragments = [], $patterns = [];
        if ($exact === null) {
            $exact = [];
            $file = dirname(__DIR__, 2) . '/resources/lang/messages_' . locale() . '.php';
            $catalog = is_file($file) ? (array) require $file : [];
            foreach ($catalog as $key => $en) {
                $fragment = str_starts_with($key, '~');
                $key = $fragment ? substr($key, 1) : $key;
                if (!str_contains($key, '{x}')) {
                    if ($fragment) {
                        $fragments[$key] = $en;
                    } else {
                        $exact[$key] = $en;
                    }
                    continue;
                }
                $parts = array_map(static fn ($p) => preg_quote($p, '/'), explode('{x}', $key));
                $regex = implode('(.+?)', $parts);
                if (str_ends_with($key, '{x}')) {
                    $regex = substr($regex, 0, -5) . '(.*?)'; // phần đuôi tùy chọn (vd " N dòng lỗi...") có thể rỗng
                }
                $patterns[] = ['/^' . $regex . '$/su', $en, strlen(str_replace('{x}', '', $key)), $fragment];
            }
            usort($patterns, static fn ($a, $b) => $b[2] <=> $a[2]); // mẫu cụ thể (nhiều chữ cố định) trước
        }

        $translate = static function (string $s, int $depth) use (&$translate, $exact, $fragments, $patterns): ?string {
            if (isset($exact[$s])) {
                return $exact[$s];
            }
            if ($depth > 0 && isset($fragments[$s])) {
                return $fragments[$s];
            }
            if ($depth > 3) {
                return null;
            }
            foreach ($patterns as [$regex, $en, , $fragment]) {
                if (($fragment && $depth === 0) || !preg_match($regex, $s, $m)) {
                    continue;
                }
                $caps = array_map(static fn ($c) => $translate($c, $depth + 1) ?? $c, array_slice($m, 1));
                $i = 0;
                $out = preg_replace_callback('/\{x\}|\{(\d+)\}/', static function ($p) use ($caps, &$i) {
                    return isset($p[1]) ? ($caps[(int) $p[1] - 1] ?? '') : ($caps[$i++] ?? '');
                }, $en);

                return $out;
            }
            foreach (['; ', ', '] as $sep) {
                if (str_contains($s, $sep)) {
                    $hit = false;
                    $parts = array_map(static function ($p) use (&$translate, &$hit, $depth) {
                        $tr = $translate($p, $depth + 1);
                        $hit = $hit || $tr !== null;

                        return $tr ?? $p;
                    }, explode($sep, $s));
                    if ($hit) {
                        return implode($sep, $parts);
                    }
                }
            }

            return null;
        };

        return $translate($vi, 0) ?? $vi;
    }
}

if (!function_exists('rule_condition_t')) {
    /**
     * Câu mô tả điều kiện quy tắc cho GIAO DIỆN. Tiếng Việt = đúng chuỗi của AlertRuleService / RecommendationRuleService::describe() (nguồn không đổi);
     * tiếng Anh dựng lại từ nhãn en của config. Mô tả nằm trong nội dung cảnh báo lưu DB vẫn do service dựng bằng tiếng Việt.
     */
    function rule_condition_t(string $kind, array $rule): string
    {
        if ($kind === 'alert') {
            if (locale() === 'vi') {
                return \App\Services\AlertRuleService::describe($rule);
            }
            $measure = config_label('alerts.measure_type', $rule['measure_type']);
            $utility = label_t('alerts.utility_word.' . $rule['utility_type'], (string) (config('alerts.utility_word')[$rule['utility_type']] ?? $rule['utility_type']));
            $op = config('alerts.operator')[$rule['operator']] ?? $rule['operator'];
            $threshold = \App\Services\RuleConditionEvaluator::plain((string) $rule['threshold_value']);
            if ($rule['compare_base'] === 'gia_tri_tuyet_doi') {
                $unit = $rule['measure_type'] === 'chi_phi' ? 'đ' : (config('pricing.utility_unit')[$rule['utility_type']] ?? '');

                return t('lit.rule_condition_absolute', ['utility' => ucfirst($utility), 'measure' => mb_strtolower($measure), 'op' => $op, 'threshold' => $threshold, 'unit' => $unit]);
            }

            return t('lit.rule_condition_change', ['utility' => ucfirst($utility), 'measure' => mb_strtolower($measure), 'op' => $op, 'threshold' => $threshold,
                'base' => $rule['compare_base'] === 'ky_truoc' ? t('lit.previous_period') : 'baseline']);
        }
        if (locale() === 'vi') {
            return \App\Services\RecommendationRuleService::describe($rule);
        }
        $params = \App\Services\RecommendationRuleService::params($rule);
        $text = config_label('recommendations.condition_type', $rule['condition_type']);
        if (isset($params['n_periods'])) {
            $text = str_replace('N', (string) $params['n_periods'], $text);
        }
        if (isset($params['utility_type'])) {
            $text .= t('lit.only_utility', ['utility' => label_t('alerts.utility_word.' . $params['utility_type'], (string) (config('alerts.utility_word')[$params['utility_type']] ?? $params['utility_type']))]);
        }

        return $text;
    }
}

if (!function_exists('label_source')) {
    /**
     * Nhãn tiếng Việt GỐC của giá trị ENUM (config/master_data.php), KHÔNG theo ngôn ngữ giao diện.
     * Dùng trong service khi dựng chuỗi lưu DB / thông báo nghiệp vụ (lịch sử cảnh báo, lỗi tính phí...) - đổi ngôn ngữ không được làm đổi dữ liệu.
     */
    function label_source(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return (string) (config('master_data.' . $group, [])[$value] ?? $value);
    }
}

if (!function_exists('label_of')) {
    /** Nhãn hiển thị của giá trị ENUM theo config/master_data.php. */
    function label_of(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        // Song ngữ: tiếng Anh lấy từ resources/lang/en.php (labels.<nhóm>.<giá trị>); tiếng Việt vẫn là config (nguồn gốc)
        if (locale() !== 'vi' && \App\Core\Lang::has('labels.' . $group . '.' . $value)) {
            return t('labels.' . $group . '.' . $value);
        }

        return (string) (config('master_data.' . $group, [])[$value] ?? $value);
    }
}

if (!function_exists('status_badge')) {
    /** Badge trạng thái bản ghi (hoat_dong/ngung) - HTML đã escape. */
    function status_badge(?string $status): string
    {
        $class = $status === 'hoat_dong' ? 'badge--active' : 'badge--inactive';

        return '<span class="badge ' . $class . '">' . e(label_of('record_status', $status)) . '</span>';
    }
}

if (!function_exists('mask_phone')) {
    /** [TECHNICAL ASSUMPTION D10] che SĐT cho role không có quyền residents.view_phone. */
    function mask_phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }
}

if (!function_exists('url_with')) {
    /** URL giữ nguyên bộ lọc hiện tại, ghi đè các tham số trong $overrides; bỏ tham số rỗng. */
    function url_with(string $path, array $filters, array $overrides = []): string
    {
        $query = array_filter(
            array_merge($filters, $overrides),
            static fn ($value): bool => $value !== null && $value !== ''
        );

        return base_url($path) . ($query ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('format_decimal')) {
    /** Số thập phân kiểu Việt Nam, bỏ phần ,00 thừa (80,00 -> 80; 80,50 -> 80,5). */
    function format_decimal($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $formatted = number_format((float) $value, 2, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $time = strtotime($date);

        return $time === false ? e($date) : date('d/m/Y', $time);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $time = strtotime($date);

        return $time === false ? e($date) : date('d/m/Y H:i', $time);
    }
}

if (!function_exists('today_vn_text')) {
    function today_vn_text(): string
    {
        $daysVi = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
        $daysEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $w = (int) date('w');
        if (locale() === 'en') {
            return $daysEn[$w] . ', ' . date('d/m/Y');
        }

        return $daysVi[$w] . ', ' . date('d/m/Y');
    }
}

// ---------------------------------------------------------------------------
// STEP 7 (FN-03): helper hiển thị biểu giá/phí - chỉ định dạng, không có đơn giá.
// ---------------------------------------------------------------------------

if (!function_exists('lifecycle_badge')) {
    /** Vòng đời phiên bản tính theo ngày hiệu lực (P2). HTML đã escape. */
    function lifecycle_badge(?string $lifecycle): string
    {
        $classes = ['dang_ap_dung' => 'badge--active', 'sap_ap_dung' => 'badge--info', 'da_thay_the' => 'badge--inactive', 'het_hieu_luc' => 'badge--inactive'];
        $label = config_label('pricing.tariff_lifecycle', $lifecycle);

        return '<span class="badge ' . ($classes[$lifecycle] ?? 'badge--muted') . '">' . e($label) . '</span>';
    }
}

if (!function_exists('proposal_badge')) {
    function proposal_badge(?string $status): string
    {
        $classes = ['cho_duyet' => 'badge--warning', 'da_duyet' => 'badge--active', 'tu_choi' => 'badge--danger'];
        $label = config_label('pricing.proposal_status', $status);

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e($label) . '</span>';
    }
}

if (!function_exists('tier_range')) {
    /** Khoảng bậc nửa khoảng [from, to): "0 – 50 kWh" hoặc "> 400 kWh" (bậc mở). */
    function tier_range($from, $to, string $unit): string
    {
        if ($to === null || $to === '') {
            return t('helpers.tier_open', ['from' => format_decimal($from), 'unit' => $unit]);
        }

        return format_decimal($from) . ' – ' . format_decimal($to) . ' ' . $unit;
    }
}

if (!function_exists('format_percent')) {
    function format_percent($value): string
    {
        return $value === null || $value === '' ? t('helpers.no_vat_split') : format_decimal($value) . '%';
    }
}

if (!function_exists('format_input')) {
    /** Số từ DB cho ô nhập: "50.00" -> "50", "1806.50" -> "1806.5"; chuỗi người dùng gõ giữ nguyên. */
    function format_input($value): string
    {
        $value = $value === null ? '' : (string) $value;
        if (preg_match('/^\d+\.\d+$/', $value)) {
            return rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }
}

// ---------------------------------------------------------------------------
// STEP 8 (FN-04): badge trạng thái kỳ / chỉ số / lô import.
// ---------------------------------------------------------------------------

if (!function_exists('period_badge')) {
    function period_badge(?string $status): string
    {
        $classes = ['mo' => 'badge--active', 'da_khoa' => 'badge--inactive', 'mo_lai' => 'badge--warning'];

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e(config_label('readings.period_status', $status)) . '</span>';
    }
}

if (!function_exists('reading_badge')) {
    function reading_badge(?string $status): string
    {
        $classes = ['nhap' => 'badge--warning', 'da_xac_nhan' => 'badge--active'];

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e(config_label('readings.reading_status', $status)) . '</span>';
    }
}

if (!function_exists('import_badge')) {
    function import_badge(?string $status): string
    {
        $classes = ['da_tai_len' => 'badge--muted', 'da_kiem_tra' => 'badge--warning', 'da_luu' => 'badge--active', 'da_huy' => 'badge--inactive'];

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e(config_label('readings.import_status', $status)) . '</span>';
    }
}

// ---------------------------------------------------------------------------
// STEP 9 (FN-05): tiền chính xác tới xu, badge bản tính / tính nhất quán.
// ---------------------------------------------------------------------------

if (!function_exists('money_vnd')) {
    /**
     * Tiền từ chuỗi DECIMAL hoặc số xu (int) -> "1.950.058 đ" / "400.972,5 đ" (giữ phần lẻ nếu có,
     * không làm tròn khi hiển thị - khác format_money của FN-03 vốn chỉ dùng cho đơn giá nguyên).
     */
    function money_vnd($value, bool $cents = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $c = $cents ? (int) $value : \App\Services\MoneyCalculator::fromDb((string) $value);
        $sign = $c < 0 ? '-' : '';
        $c = abs($c);
        $text = number_format(intdiv($c, 100), 0, ',', '.');
        if ($c % 100 !== 0) {
            $text .= ',' . rtrim(str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT), '0');
        }

        return $sign . $text . ' đ';
    }
}

if (!function_exists('qty_of')) {
    /** Số lượng dạng xu (int) -> "50,5". */
    function qty_of(int $cents): string
    {
        return format_decimal(\App\Core\Decimal::fromCents($cents));
    }
}

if (!function_exists('charge_badge')) {
    function charge_badge(?string $status): string
    {
        $classes = ['da_tinh' => 'badge--info', 'da_xac_nhan' => 'badge--active', 'bi_tra_lai' => 'badge--danger', 'da_duyet' => 'badge--active', 'da_phat_hanh' => 'badge--inactive'];

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e(config_label('billing.charge_status', $status)) . '</span>';
    }
}

if (!function_exists('consistency_badge')) {
    /** Trạng thái tính nhất quán (Q12) - KHÔNG phải trạng thái nghiệp vụ lưu DB. */
    function consistency_badge(string $state): string
    {
        $map = [
            'current' => ['badge--active', 'Hiện hành'],
            'stale' => ['badge--danger', 'CẦN TÍNH LẠI'],
            'stale_error' => ['badge--danger', 'CẦN TÍNH LẠI — lỗi dữ liệu'],
            'locked' => ['badge--inactive', 'Kỳ đã khóa (lịch sử)'],
            'not_calculated' => ['badge--muted', 'Chưa tính'],
            'missing' => ['badge--muted', 'Không có bản tính'],
            'error' => ['badge--danger', 'Lỗi dữ liệu — chưa tính được'],
        ];
        [$class, $label] = $map[$state] ?? ['badge--muted', $state];

        return '<span class="badge ' . $class . '">' . e(label_t('billing.consistency.' . $state, $label)) . '</span>';
    }
}

// ---------------------------------------------------------------------------
// STEP 10 (FN-06): % (null -> "N/A", không bao giờ NaN/Infinity), xu hướng bằng chữ + ký hiệu, kết quả mục tiêu.
// ---------------------------------------------------------------------------

if (!function_exists('pct_text')) {
    function pct_text(?string $percent): string
    {
        if ($percent === null) {
            return 'N/A';
        }

        return (str_starts_with($percent, '-') ? '' : ($percent === '0.00' ? '' : '+')) . format_decimal($percent) . '%';
    }
}

if (!function_exists('trend_text')) {
    /** Xu hướng bằng ký hiệu + chữ (không chỉ dựa vào màu). Không phải kết luận bất thường (BR-29). */
    function trend_text(?string $trend): string
    {
        return match ($trend) {
            'up' => t('helpers.trend_up'),
            'down' => t('helpers.trend_down'),
            'equal' => t('helpers.trend_equal'),
            default => '—',
        };
    }
}

if (!function_exists('target_badge')) {
    function target_badge(string $status): string
    {
        $map = ['dat' => ['badge--active', '✔ '], 'khong_dat' => ['badge--danger', '✘ '], 'chua_du_du_lieu' => ['badge--muted', '… ']];
        [$class, $icon] = $map[$status] ?? ['badge--muted', ''];

        return '<span class="badge ' . $class . '">' . $icon . e(config_label('analytics.target_status', $status)) . '</span>';
    }
}

// ---------------------------------------------------------------------------
// STEP 11 (FN-07): badge cảnh báo (chữ + màu, không chỉ dựa màu).
// ---------------------------------------------------------------------------

if (!function_exists('alert_status_badge')) {
    function alert_status_badge(?string $status): string
    {
        $classes = ['moi' => 'badge--danger', 'da_review' => 'badge--info', 'can_kiem_tra_lai' => 'badge--warning', 'da_xac_nhan' => 'badge--active', 'da_dong' => 'badge--inactive'];

        return '<span class="badge ' . ($classes[$status] ?? 'badge--muted') . '">' . e(config_label('alerts.status', $status)) . '</span>';
    }
}

if (!function_exists('alert_priority_badge')) {
    function alert_priority_badge(?string $priority): string
    {
        $map = ['cao' => ['badge--danger', '▲▲ '], 'trung_binh' => ['badge--warning', '▲ '], 'thap' => ['badge--muted', '▽ ']];
        [$class, $icon] = $map[$priority] ?? ['badge--muted', ''];

        return '<span class="badge ' . $class . '">' . $icon . e(config_label('alerts.priority', $priority)) . '</span>';
    }
}

// ---------------------------------------------------------------------------
// STEP 13 (FN-09): biểu đồ cột SVG vẽ phía server (không JS, không CDN, chạy offline).
// ---------------------------------------------------------------------------

if (!function_exists('svg_bars')) {
    /**
     * @param array<string, ?float> $points nhãn kỳ => giá trị (null = chưa đủ dữ liệu, không vẽ cột)
     */
    function svg_bars(array $points, string $unit, string $title, string $color = '#2b59c3'): string
    {
        // STEP 17 (UI): lưới ngang nhạt, cột bo góc; kỳ cuối (kỳ đang xem) đậm, các kỳ trước nhạt hơn. Dữ liệu / nhãn không đổi.
        $w = 560;
        $h = 230;
        $pad = 36;
        $top = 26;
        $n = max(1, count($points));
        $max = max(array_merge([0.0], array_filter(array_values($points), static fn ($v) => $v !== null)));
        $slot = ($w - 2 * $pad) / $n;
        // Giới hạn max-width cột 32px, tỉ lệ 0.45 slot để tạo khoảng trắng rõ ràng giữa các cột
        $bar = min(32, $slot * 0.45);
        $base = $h - $pad;
        $font = 'font-family="Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif"';
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '" xmlns="http://www.w3.org/2000/svg" ' . $font . '>'
            . '<title>' . e($title) . '</title>';
        foreach ([0.25, 0.5, 0.75, 1.0] as $g) {
            $gy = round($base - ($base - $top) * $g, 1);
            $svg .= '<line x1="' . $pad . '" y1="' . $gy . '" x2="' . ($w - $pad) . '" y2="' . $gy . '" stroke="#eef1f5"/>';
        }
        $svg .= '<line x1="' . $pad . '" y1="' . $base . '" x2="' . ($w - $pad) . '" y2="' . $base . '" stroke="#cfd6e1"/>';
        if ($points === [] || $max <= 0.0 && array_filter($points, static fn ($v) => $v !== null) === []) {
            return $svg . '<text x="' . ($w / 2) . '" y="' . ($h / 2) . '" text-anchor="middle" fill="#667085" font-size="13">' . e(t('helpers.chart_no_data')) . '</text></svg>';
        }
        $i = 0;
        foreach ($points as $label => $value) {
            $x = $pad + $slot * $i + ($slot - $bar) / 2;
            $cx = round($x + $bar / 2, 1);
            $last = $i === $n - 1;
            if ($value === null) {
                $svg .= '<text x="' . $cx . '" y="' . ($base - 6) . '" text-anchor="middle" fill="#98a2b3" font-size="10">' . e(t('helpers.chart_insufficient')) . '</text>';
            } else {
                $bh = $max > 0 ? ($base - $top - 12) * $value / $max : 0;
                $y = $base - $bh;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bar, 1) . '" height="' . round($bh, 1) . '" fill="' . e($color) . '"' . ($last ? '' : ' fill-opacity="0.45"') . ' rx="4"><title>'
                    . e($label . ': ' . format_decimal((string) $value) . ' ' . $unit) . '</title></rect>'
                    . '<text x="' . $cx . '" y="' . round($y - 6, 1) . '" text-anchor="middle" font-size="11" font-weight="' . ($last ? '700' : '500') . '" fill="' . ($last ? '#101828' : '#475467') . '">' . e(format_decimal((string) $value)) . '</text>';
            }
            $svg .= '<text x="' . $cx . '" y="' . ($base + 16) . '" text-anchor="middle" font-size="11" fill="' . ($last ? '#101828' : '#667085') . '"' . ($last ? ' font-weight="600"' : '') . '>' . e((string) $label) . '</text>';
            $i++;
        }

        return $svg . '<text x="' . $pad . '" y="14" font-size="11" font-weight="600" fill="#667085">' . e($unit) . '</text></svg>';
    }
}

if (!function_exists('user_status_badge')) {
    /** STEP 15 FN-01.03: badge trạng thái tài khoản - HTML đã escape. */
    function user_status_badge(?string $status): string
    {
        $class = ['hoat_dong' => 'badge--active', 'khoa_tam' => 'badge--warning'][$status] ?? 'badge--inactive';

        return '<span class="badge ' . $class . '">' . e(label_of('user_status', $status)) . '</span>';
    }
}

if (!function_exists('mask_email')) {
    /** STEP 25 (BR-36): che email cho vai trò không có quyền xem liên hệ - "ng••••@gmail.com". */
    function mask_email(?string $email): string
    {
        if ($email === null || $email === '' || !str_contains($email, '@')) {
            return '—';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2) . str_repeat('•', max(2, mb_strlen($local) - 2)) . '@' . $domain;
    }
}

if (!function_exists('email_status_badge')) {
    /** STEP 25: badge trạng thái gửi email (pending / sent / failed) - HTML đã escape. */
    function email_status_badge(?string $status): string
    {
        [$class, $label] = ['sent' => ['badge--active', 'Đã gửi'], 'failed' => ['badge--danger', 'Lỗi'], 'pending' => ['badge--warning', 'Đang gửi']][$status] ?? ['badge--muted', (string) $status];

        return '<span class="badge ' . $class . '">' . e(label_t('email.status.' . $status, $label)) . '</span>';
    }
}

if (!function_exists('payment_status_badge')) {
    /** Payment module: badge trạng thái thanh toán của bản tính (PAID / UNPAID / PAYMENT_REVIEW) - HTML đã escape. */
    function payment_status_badge(?string $status): string
    {
        $class = ['PAID' => 'badge--active', 'UNPAID' => 'badge--warning', 'PAYMENT_REVIEW' => 'badge--danger'][$status] ?? 'badge--muted';

        return '<span class="badge ' . $class . '">' . e(label_t('payment.status.' . $status, \App\Services\PaymentStatusService::LABELS[$status] ?? (string) $status)) . '</span>';
    }
}

if (!function_exists('payment_match_badge')) {
    /** Payment module: badge kết quả đối soát của giao dịch - HTML đã escape. */
    function payment_match_badge(?string $match): string
    {
        $class = in_array($match, ['matched', 'manual_matched'], true) ? 'badge--active' : ($match === 'ignored' ? 'badge--muted' : 'badge--danger');

        return '<span class="badge ' . $class . '">' . e(label_t('payment.match.' . $match, \App\Services\PaymentStatusService::MATCH_LABELS[$match] ?? (string) $match)) . '</span>';
    }
}

if (!function_exists('alert_utility_key')) {
    /**
     * Khoá tiện ích hiển thị của cảnh báo: 'dien' | 'nuoc' | 'both' (J-16 cả điện + nước: utility_type NULL CHỈ khi alert_type = no_usage_consumption)
     * | 'unknown' (dữ liệu thiếu / lạ - không suy diễn "Điện + Nước" cho cảnh báo FN-07).
     */
    function alert_utility_key(array $alert): string
    {
        $utility = $alert['utility_type'] ?? null;
        if ($utility === 'dien' || $utility === 'nuoc') {
            return $utility;
        }
        if ($utility === null && ($alert['alert_type'] ?? null) === config('j16.type')) {
            return 'both';
        }

        return 'unknown';
    }
}

if (!function_exists('alert_utility_text')) {
    /** Nhãn tiện ích cảnh báo cho dashboard: Điện / Nước / Điện + Nước (J-16) / Không xác định. */
    function alert_utility_text(array $alert): string
    {
        return config_label('alerts.utility_label', alert_utility_key($alert), label_t('alerts.unknown_label', (string) config('alerts.unknown_label', 'Không xác định')));
    }
}
