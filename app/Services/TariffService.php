<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\TariffProposalRepository;
use App\Repositories\TariffRepository;

/**
 * FN-03.01 Quản lý biểu giá điện/nước | [TL] 2.1, 2.3, NFR-04, BR-13, BR-14, BR-20, BR-21; [ĐX] BR-42.
 * - Mỗi dòng tariffs = 1 phiên bản; tạo mới luôn là phiên bản mới, lịch sử giữ nguyên (P1).
 * - Chỉ sửa phiên bản Sắp áp dụng (P3). Bậc nửa khoảng [from, to) - Schema Review A1, KHÔNG +1 (P7).
 * - Không có đơn giá nào trong code: mọi giá đến từ input và lưu DB.
 * validatePayload()/assertEffectiveDateAllowed()/insertVersion() dùng chung với FN-03.03/03.04.
 */
class TariffService extends BaseService
{
    private TariffRepository $tariffs;
    private BillingPeriodRepository $periods;

    public function __construct()
    {
        $this->tariffs = new TariffRepository();
        $this->periods = new BillingPeriodRepository();
    }

    public function list(array $filters, int $page): array
    {
        $filters['utility_type'] = array_key_exists($filters['utility_type'], config('master_data.utility_type')) ? $filters['utility_type'] : '';
        $filters['usage_type'] = array_key_exists($filters['usage_type'], config('master_data.usage_type')) ? $filters['usage_type'] : '';
        $filters['lifecycle'] = array_key_exists($filters['lifecycle'], config('pricing.tariff_lifecycle')) ? $filters['lifecycle'] : '';
        foreach (['effective_from', 'effective_to'] as $key) {
            $filters[$key] = $this->isValidDate($filters[$key]) ? $filters[$key] : '';
        }

        return $this->tariffs->search($filters, $this->today(), $page, (int) config('master_data.per_page', 20));
    }

    public function find(int $id): ?array
    {
        $tariff = $this->tariffs->findDetailed($id, $this->today());
        if ($tariff !== null) {
            $tariff['tiers'] = $this->tariffs->tiersOf($id);
            $tariff['version_token'] = $this->versionToken($tariff, $tariff['tiers']);
        }

        return $tariff;
    }

    /** Chi tiết + lịch sử phiên bản của chuỗi + đề xuất liên quan + quyền sửa. */
    public function detail(int $id): ?array
    {
        $tariff = $this->find($id);
        if ($tariff === null) {
            return null;
        }

        $tariff['versions'] = $this->tariffs->seriesVersions($tariff['utility_type'], $tariff['usage_type'], $this->today());
        $tariff['proposals'] = (new TariffProposalRepository())->forTariff($id);
        $tariff['edit_block_reason'] = $this->editBlockReason($tariff);

        return $tariff;
    }

    public function latestPerSeries(): array
    {
        return $this->tariffs->latestPerSeries($this->today());
    }

    /** null = được sửa; ngược lại là lý do không được sửa (P3). */
    public function editBlockReason(array $tariff): ?string
    {
        if ($tariff['status'] !== 'hieu_luc') {
            return 'Phiên bản đã hết hiệu lực.';
        }
        if ($tariff['effective_date'] <= $this->today()) {
            return 'Phiên bản đã/đang được áp dụng — không sửa đè lịch sử. Hãy tạo phiên bản mới.';
        }
        $lastLocked = $this->periods->lastLockedEndDate();
        if ($lastLocked !== null && $tariff['effective_date'] <= $lastLocked) {
            return 'Ngày hiệu lực thuộc kỳ đã khóa.';
        }

        return null;
    }

    public function create(array $input): int
    {
        $data = $this->validateSeries($input);
        $payload = $this->validatePayload($input, $data['utility_type'], $data['usage_type']);

        return $this->transaction($this->tariffs, function () use ($data, $payload): int {
            $this->tariffs->lockSeries($data['utility_type'], $data['usage_type']);
            $this->assertEffectiveDateAllowed($data['utility_type'], $data['usage_type'], $payload['effective_date'], null);

            $id = $this->insertVersion($data['utility_type'], $data['usage_type'], $payload, $this->actorId(), $this->actorId());
            $this->audit('CREATE_TARIFF', 'tariff', $id, null, $this->snapshot($id));

            return $id;
        });
    }

    /** P3: chỉ phiên bản Sắp áp dụng; loại tiện ích/loại sử dụng không đổi được. */
    public function update(int $id, array $input): bool
    {
        return $this->transaction($this->tariffs, function () use ($id, $input): bool {
            // STEP 15: khoá CHUỖI trước rồi mới khoá dòng - cùng thứ tự với create / duyệt đề xuất.
            // Thứ tự cũ (dòng -> chuỗi) deadlock khi 2 người sửa 2 phiên bản cùng chuỗi (tests/fn03_lock_order_demo.sh).
            // Chuỗi (utility_type, usage_type) không đổi khi sửa nên đọc thường để biết chuỗi là đủ.
            $series = $this->tariffs->find($id);
            if ($series === null) {
                throw ValidationException::withMessage('Biểu giá không tồn tại.');
            }
            $this->tariffs->lockSeries($series['utility_type'], $series['usage_type']);
            $current = $this->tariffs->findForUpdate($id);
            if ($current === null) {
                throw ValidationException::withMessage('Biểu giá không tồn tại.');
            }

            $reason = $this->editBlockReason($current);
            if ($reason !== null) {
                throw ValidationException::withMessage('Không thể sửa phiên bản này: ' . $reason);
            }

            $before = $this->snapshot($id);
            if (($input['version_token'] ?? '') !== $this->versionToken($current, $before['tiers'])) {
                throw ValidationException::withMessage('Dữ liệu đã bị người khác thay đổi sau khi bạn mở form. Vui lòng tải lại trang và nhập lại.');
            }

            $payload = $this->validatePayload($input, $current['utility_type'], $current['usage_type']);
            $this->assertEffectiveDateAllowed($current['utility_type'], $current['usage_type'], $payload['effective_date'], $id);

            if ($this->pricePart($before) === $this->pricePart($payload)) {
                return false;
            }

            $this->tariffs->updateById($id, [
                'vat_percent' => $payload['vat_percent'],
                'business_unit_price' => $payload['business_unit_price'],
                'effective_date' => $payload['effective_date'],
                'updated_at' => date('Y-m-d H:i:s'), // đổi riêng bậc cũng phải đổi updated_at của phiên bản
            ]);
            $this->tariffs->replaceTiers($id, $payload['tiers']);

            $this->audit('UPDATE_TARIFF', 'tariff', $id, $this->comparable($before), $this->comparable($this->snapshot($id)));

            return true;
        });
    }

    /**
     * Kiểm tra dữ liệu giá của một phiên bản (dùng cho cả tạo/sửa biểu giá và đề xuất).
     * @return array{vat_percent:?string,business_unit_price:?string,effective_date:string,tiers:array}
     */
    public function validatePayload(array $input, string $utilityType, string $usageType): array
    {
        $data = [
            'vat_percent' => $this->normalizeDecimal($input['vat_percent'] ?? ''),
            'business_unit_price' => $this->normalizeDecimal($input['business_unit_price'] ?? ''),
            'effective_date' => (string) ($input['effective_date'] ?? ''),
        ];

        $v = new Validator($data);
        $v->decimal('vat_percent', 2, 0, 100, false, 'Thuế VAT phải là số từ 0 đến 100 (tối đa 2 chữ số thập phân).')
            ->required('effective_date', 'Vui lòng chọn ngày hiệu lực.')
            ->date('effective_date', 'Ngày hiệu lực không hợp lệ.');

        $tiers = [];
        if ($usageType === 'kinh_doanh') {
            // P6: kinh doanh = đồng giá
            $v->required('business_unit_price', 'Vui lòng nhập đơn giá kinh doanh.')
                ->decimal('business_unit_price', 2, 0, 9999999999.99, true, 'Đơn giá phải lớn hơn 0.'); // VAL-03.01
        } else {
            // P6/P7: sinh hoạt = bậc thang
            $tiers = $this->validateTiers((array) ($input['tiers'] ?? []), $v);
        }

        $v->throwIfFails();

        return [
            'vat_percent' => $data['vat_percent'] === '' ? null : number_format((float) $data['vat_percent'], 2, '.', ''),
            'business_unit_price' => $usageType === 'kinh_doanh' ? number_format((float) $data['business_unit_price'], 2, '.', '') : null,
            'effective_date' => $data['effective_date'],
            'tiers' => $tiers,
        ];
    }

    /** VAL-03.04 / BR-42 (P4) + P5. Gọi TRONG transaction sau khi đã lockSeries. */
    public function assertEffectiveDateAllowed(string $utilityType, string $usageType, string $date, ?int $exceptId): void
    {
        $lastLocked = $this->periods->lastLockedEndDate();
        if ($lastLocked !== null && $date <= $lastLocked) {
            throw new ValidationException(['effective_date' => [sprintf(
                'Không thể áp dụng biểu giá cho kỳ đã khóa. Kỳ khóa gần nhất kết thúc %s — ngày hiệu lực phải sau ngày này.',
                date('d/m/Y', (int) strtotime($lastLocked))
            )]]);
        }
        if ($this->tariffs->effectiveDateExists($utilityType, $usageType, $date, $exceptId)) {
            throw new ValidationException(['effective_date' => [
                'Biểu giá này đã có phiên bản hiệu lực từ ' . date('d/m/Y', (int) strtotime($date)) . '. Chọn ngày khác hoặc sửa phiên bản đó (nếu chưa áp dụng).',
            ]]);
        }
    }

    /** Ghi một phiên bản mới (gọi trong transaction của caller). */
    public function insertVersion(string $utilityType, string $usageType, array $payload, ?int $createdBy, ?int $approvedBy): int
    {
        $id = $this->tariffs->insertRow([
            'utility_type' => $utilityType,
            'usage_type' => $usageType,
            'vat_percent' => $payload['vat_percent'],
            'business_unit_price' => $payload['business_unit_price'],
            'effective_date' => $payload['effective_date'],
            'status' => 'hieu_luc', // BQL là người duyệt (FN-03.01 bước 4 [SL]) hoặc đến từ đề xuất đã duyệt
            'created_by' => $createdBy,
            'approved_by' => $approvedBy,
        ]);
        $this->tariffs->replaceTiers($id, $payload['tiers']);

        return $id;
    }

    /** Bản ghi + bậc (cho audit và so sánh). */
    public function snapshot(int $id): array
    {
        $row = $this->tariffs->find($id) ?? [];
        $row['tiers'] = $this->tariffs->tiersOf($id);

        return $row;
    }

    public function lockSeries(string $utilityType, string $usageType): array
    {
        return $this->tariffs->lockSeries($utilityType, $usageType);
    }

    public function versionToken(array $tariff, array $tiers): string
    {
        return sha1(json_encode([
            $tariff['id'], $tariff['vat_percent'], $tariff['business_unit_price'], $tariff['effective_date'], $tariff['status'],
            array_map(static fn (array $t): array => [(string) $t['tier_from'], $t['tier_to'] === null ? null : (string) $t['tier_to'], (string) $t['unit_price']], $tiers),
        ]));
    }

    /** @return array{utility_type:string,usage_type:string} */
    private function validateSeries(array $input): array
    {
        $data = ['utility_type' => (string) ($input['utility_type'] ?? ''), 'usage_type' => (string) ($input['usage_type'] ?? '')];

        $v = new Validator($data);
        $v->required('utility_type', 'Vui lòng chọn loại tiện ích.')
            ->in('utility_type', array_keys(config('master_data.utility_type')), 'Loại tiện ích không hợp lệ (chỉ Điện hoặc Nước).')
            ->required('usage_type', 'Vui lòng chọn loại sử dụng.')
            ->in('usage_type', array_keys(config('master_data.usage_type')), 'Loại sử dụng không hợp lệ (chỉ Sinh hoạt hoặc Kinh doanh).');
        $v->throwIfFails();

        return $data;
    }

    /**
     * VAL-03.01/02/03 theo quy ước Schema Review A1 (P7).
     * $rows: [['tier_from'=>..,'tier_to'=>..,'unit_price'=>..], ...]; dòng trống hoàn toàn bị bỏ qua.
     * @return array<int, array{tier_from:string,tier_to:?string,unit_price:string}>
     */
    private function validateTiers(array $rows, Validator $v): array
    {
        $tiers = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $tier = [
                'tier_from' => $this->normalizeDecimal(is_scalar($row['tier_from'] ?? null) ? (string) $row['tier_from'] : ''),
                'tier_to' => $this->normalizeDecimal(is_scalar($row['tier_to'] ?? null) ? (string) $row['tier_to'] : ''),
                'unit_price' => $this->normalizeDecimal(is_scalar($row['unit_price'] ?? null) ? (string) $row['unit_price'] : ''),
            ];
            if ($tier['tier_from'] === '' && $tier['tier_to'] === '' && $tier['unit_price'] === '') {
                continue;
            }
            $tiers[] = $tier;
        }

        if ($tiers === []) {
            $v->addError('tiers', 'Biểu giá bậc thang phải có ít nhất 1 bậc.');

            return [];
        }
        if (count($tiers) > (int) config('pricing.max_tiers', 20)) {
            $v->addError('tiers', 'Tối đa ' . (int) config('pricing.max_tiers', 20) . ' bậc.');
        }

        $numberRegex = '/^\d+(\.\d{1,2})?$/';
        $lastIndex = count($tiers) - 1;
        $prevTo = null;
        $result = [];

        foreach ($tiers as $i => $tier) {
            $n = $i + 1;
            $field = 'tiers.' . $i;
            $fromOk = (bool) preg_match($numberRegex, $tier['tier_from']);
            $toOk = $tier['tier_to'] === '' || (bool) preg_match($numberRegex, $tier['tier_to']);

            if (!$fromOk) {
                $v->addError($field, "Bậc {$n}: \"Từ\" phải là số ≥ 0 (tối đa 2 chữ số thập phân).");
            }
            if (!$toOk) {
                $v->addError($field, "Bậc {$n}: \"Đến\" phải là số (tối đa 2 chữ số thập phân) hoặc để trống ở bậc cuối.");
            }
            if (!preg_match($numberRegex, $tier['unit_price']) || (float) $tier['unit_price'] <= 0 || (float) $tier['unit_price'] > 9999999999.99) {
                $v->addError($field, "Bậc {$n}: đơn giá phải lớn hơn 0."); // VAL-03.01
            }

            if ($fromOk && $toOk) {
                $from = (float) $tier['tier_from'];
                if ($i === 0 && $from !== 0.0) {
                    $v->addError($field, 'Bậc 1 phải bắt đầu từ 0.');
                }
                if ($prevTo !== null && abs($from - $prevTo) > 0.0001) {
                    // VAL-03.02: bậc k phải bắt đầu đúng tại tier_to của bậc k-1 (nửa khoảng, không +1)
                    $v->addError($field, sprintf(
                        'Bậc %d: %s với bậc %d — bậc trước kết thúc tại %s, bậc này phải bắt đầu đúng tại %s (quy ước [từ, đến), không +1).',
                        $n,
                        $from > $prevTo ? 'bị hở' : 'chồng lấn',
                        $n - 1,
                        rtrim(rtrim(number_format($prevTo, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($prevTo, 2, '.', ''), '0'), '.')
                    ));
                }
                if ($tier['tier_to'] === '' && $i !== $lastIndex) {
                    $v->addError($field, "Bậc {$n}: chỉ bậc cuối được để trống \"Đến\".");
                }
                if ($tier['tier_to'] !== '' && $i === $lastIndex) {
                    $v->addError($field, 'Bậc cuối phải không giới hạn trên (để trống "Đến").'); // VAL-03.03
                }
                if ($tier['tier_to'] !== '' && (float) $tier['tier_to'] <= $from) {
                    $v->addError($field, "Bậc {$n}: \"Đến\" phải lớn hơn \"Từ\".");
                }
                $prevTo = $tier['tier_to'] === '' ? null : (float) $tier['tier_to'];
            }

            $result[] = [
                'tier_from' => $fromOk ? number_format((float) $tier['tier_from'], 2, '.', '') : $tier['tier_from'],
                'tier_to' => $tier['tier_to'] === '' ? null : ($toOk ? number_format((float) $tier['tier_to'], 2, '.', '') : $tier['tier_to']),
                'unit_price' => $tier['unit_price'],
            ];
        }

        foreach ($result as $i => $tier) {
            if (preg_match($numberRegex, $tier['unit_price'])) {
                $result[$i]['unit_price'] = number_format((float) $tier['unit_price'], 2, '.', '');
            }
        }
        if ($result !== [] && !$v->fails() && $result[$lastIndex]['tier_to'] !== null) {
            $v->addError('tiers', 'Bậc cuối phải không giới hạn trên (để trống "Đến").');
        }

        return $result;
    }

    /** Phần giá của một phiên bản (snapshot DB hoặc payload đã validate) ở dạng so sánh được. */
    private function pricePart(array $data): array
    {
        return [
            $data['vat_percent'] === null ? null : (string) $data['vat_percent'],
            $data['business_unit_price'] === null ? null : (string) $data['business_unit_price'],
            (string) $data['effective_date'],
            array_map(static fn (array $t): array => [
                (string) $t['tier_from'],
                $t['tier_to'] === null ? null : (string) $t['tier_to'],
                (string) $t['unit_price'],
            ], array_values($data['tiers'])),
        ];
    }

    /** Phần so sánh/audit: bỏ timestamp. */
    private function comparable(array $snapshot): array
    {
        unset($snapshot['created_at'], $snapshot['updated_at']);

        return $snapshot;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
