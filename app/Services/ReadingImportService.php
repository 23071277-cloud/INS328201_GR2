<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\ApartmentRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\MeterReadingRepository;
use App\Repositories\MeterRepository;
use App\Support\XlsxException;
use App\Support\XlsxReader;
use App\Support\XlsxWriter;

/**
 * FN-04.03 Import chỉ số từ file Excel mẫu + FN-04.05 Đối chiếu control total.
 * [TL] FR-04, 2.4 (cột chuẩn, dòng "Tổng", lệch thì cảnh báo trước khi lưu), BR-08, BR-09.
 *
 * Luồng: Upload -> kiểm file (đuôi, MIME, dung lượng, cấu trúc OOXML) -> đọc -> kiểm cấu trúc cột
 * -> ánh xạ căn hộ/công tơ -> validate từng dòng (MeterReadingValidator) -> control total
 * -> PREVIEW (lô 'da_kiem_tra', chưa ghi meter_readings) -> người dùng XÁC NHẬN -> lưu.
 * Khi xác nhận, file được đọc và kiểm tra LẠI theo dữ liệu mới nhất (vd trùng với nhập tay vừa lưu).
 *
 * mẫu cột (config readings.import.headers); CH = "<mã căn> [nhãn công tơ]"
 * (ưu tiên cột "Mã công tơ điện/nước" = meter_code; không có -> nhãn = meters.display_label hoặc meter_code, [SL] 2.2); CSĐ trong file phải khớp CSĐ hệ thống; cột tiền
 * chỉ để đối chiếu (K-13). Lưu từng phần (dòng hợp lệ) theo system_configs.import_strict_mode = 0
 * [ASSUMPTION A-06 / J-12]; = 1 thì có dòng lỗi là không lưu gì.
 */
class ReadingImportService extends BaseService
{
    private ImportBatchRepository $batches;
    private BillingPeriodRepository $periods;
    private MeterReadingRepository $readings;
    private ApartmentRepository $apartments;
    private MeterRepository $meters;
    private MeterReadingService $readingService;
    private MeterReadingValidator $validator;

    public function __construct()
    {
        $this->batches = new ImportBatchRepository();
        $this->periods = new BillingPeriodRepository();
        $this->readings = new MeterReadingRepository();
        $this->apartments = new ApartmentRepository();
        $this->meters = new MeterRepository();
        $this->readingService = new MeterReadingService();
        $this->validator = new MeterReadingValidator();
    }

    public static function storageDir(): string
    {
        return BASE_PATH . '/storage/imports';
    }

    /** Đường dẫn file lưu tạm - chỉ từ id lô (không dùng tên file người dùng -> không path traversal). */
    public static function storedPath(int $batchId): string
    {
        return self::storageDir() . '/batch_' . $batchId . '.xlsx';
    }

    public function isStrictMode(): bool
    {
        return SystemParameterService::get('import_strict_mode') === '1'; // FN-01.07
    }

    /**
     * Bước 1–6: kiểm file + phân tích + lưu lô 'da_kiem_tra' (chưa ghi chỉ số).
     * $file: ['name','tmp_name','error','size'] đã qua is_uploaded_file() ở Controller.
     */
    public function upload(int $periodId, ?array $file): int
    {
        $period = $this->periods->find($periodId);
        if ($period === null) {
            throw ValidationException::withMessage('Kỳ ghi chỉ số không tồn tại.');
        }
        if ($period['status'] === 'da_khoa') {
            throw ValidationException::withMessage('Kỳ đã khóa, không thể thay đổi chỉ số.'); // VAL-04.04
        }

        $originalName = $this->checkFile($file);
        try {
            $sheet = XlsxReader::firstSheetRows($file['tmp_name'], (int) config('readings.import.max_rows'));
        } catch (XlsxException $e) {
            throw new ValidationException(['file' => [$e->getMessage()]]);
        }
        $analysis = $this->analyze($period, $sheet); // ném lỗi VAL-04.11 nếu sai cấu trúc cột

        $id = $this->transaction($this->batches, function () use ($periodId, $originalName, $analysis): int {
            $id = $this->batches->insertRow([
                'billing_period_id' => $periodId,
                'file_name' => $originalName,
                'total_rows' => $analysis['total_rows'],
                'valid_rows' => $analysis['valid_rows'],
                'error_rows' => $analysis['error_rows'],
                'control_total_diff' => $analysis['control']['diff'],
                'control_total_details' => $analysis['control']['details'] === null ? null : json_encode($analysis['control']['details'], JSON_UNESCAPED_UNICODE),
                'status' => 'da_kiem_tra',
                'uploaded_by' => $this->actorId(),
            ]);
            $this->batches->replaceErrors($id, $this->errorRecords($analysis['rows']));

            return $id;
        });

        if (!is_dir(self::storageDir()) || !copy($file['tmp_name'], self::storedPath($id))) {
            $this->batches->updateById($id, ['status' => 'da_huy']);
            throw ValidationException::withMessage('Không lưu được file tạm để xem trước (kiểm tra quyền ghi storage/imports — README mục 11).');
        }
        @chmod(self::storedPath($id), 0640);

        return $id;
    }

    /** Xem trước / kết quả. Lô đang chờ xác nhận được phân tích LẠI theo dữ liệu hiện tại. */
    public function preview(int $batchId): ?array
    {
        $batch = $this->batches->findDetailed($batchId);
        if ($batch === null) {
            return null;
        }
        $batch['control'] = json_decode((string) ($batch['control_total_details'] ?? 'null'), true);
        $batch['errors'] = $this->batches->errorsOf($batchId);
        $batch['analysis'] = null;
        $batch['saved_ids'] = $this->readings->idsOfBatch($batchId);

        if ($batch['status'] === 'da_kiem_tra' && is_file(self::storedPath($batchId))) {
            $period = $this->periods->find((int) $batch['billing_period_id']);
            try {
                $batch['analysis'] = $this->analyze($period, XlsxReader::firstSheetRows(self::storedPath($batchId), (int) config('readings.import.max_rows')));
            } catch (ValidationException | XlsxException $e) {
                $batch['analysis_error'] = $e->getMessage();
            }
        }

        return $batch;
    }

    /**
     * Bước 7–8: người dùng xác nhận -> lưu dòng hợp lệ (Nháp, source=import, import_batch_id).
     * @return array{saved:int,skipped:int}
     */
    public function confirm(int $batchId, bool $acknowledgeMismatch): array
    {
        $result = $this->transaction($this->batches, function () use ($batchId, $acknowledgeMismatch): array {
            $batch = $this->batches->findForUpdate($batchId); // xác nhận 2 lần đồng thời -> lần sau thấy đã lưu
            if ($batch === null) {
                throw ValidationException::withMessage('Lô import không tồn tại.');
            }
            if ($batch['status'] !== 'da_kiem_tra') {
                throw ValidationException::withMessage('Lô import đã được xác nhận hoặc đã hủy.');
            }
            $period = $this->periods->findForUpdate((int) $batch['billing_period_id']);
            if ($period['status'] === 'da_khoa') {
                throw ValidationException::withMessage('Kỳ đã khóa, không thể thay đổi chỉ số.');
            }
            if (!is_file(self::storedPath($batchId))) {
                throw ValidationException::withMessage('Không tìm thấy file tạm của lô — vui lòng tải file lên lại.');
            }

            try {
                $sheet = XlsxReader::firstSheetRows(self::storedPath($batchId), (int) config('readings.import.max_rows'));
            } catch (XlsxException $e) {
                throw ValidationException::withMessage($e->getMessage());
            }
            $analysis = $this->analyze($period, $sheet);
            if ($this->isStrictMode() && $analysis['error_rows'] > 0) {
                throw ValidationException::withMessage("Chế độ all-or-nothing (import_strict_mode = 1): file còn {$analysis['error_rows']} dòng lỗi nên không lưu dòng nào.");
            }
            if ($analysis['control']['status'] === 'mismatch' && !$acknowledgeMismatch) {
                throw ValidationException::withMessage('Tổng chi tiết lệch so với dòng Tổng. Vui lòng xem chênh lệch và tick xác nhận trước khi lưu.');
            }

            $savedIds = [];
            foreach ($analysis['rows'] as $i => $row) {
                if ($row['errors'] !== []) {
                    continue;
                }
                $rowIds = [];
                foreach ($row['readings'] as $reading) {
                    $id = $this->readings->insertUnlessDuplicate([
                        'meter_id' => $reading['meter_id'],
                        'billing_period_id' => (int) $period['id'],
                        'previous_reading' => $reading['previous'],
                        'current_reading' => $reading['current'],
                        'consumption' => $reading['consumption'],
                        'status' => 'nhap',
                        'source' => 'import',
                        'import_batch_id' => $batchId,
                        'note' => null,
                        'entered_by' => $this->actorId(),
                    ]);
                    if ($id === null) {
                        $analysis['rows'][$i]['errors'][] = "Công tơ {$reading['meter_code']}: vừa có chỉ số trong kỳ (nhập tay/import khác) — bỏ qua.";
                        continue;
                    }
                    $rowIds[] = $id;
                }
                $savedIds = array_merge($savedIds, $rowIds);
            }

            $errorRows = count(array_filter($analysis['rows'], static fn (array $r): bool => $r['errors'] !== []));
            $this->batches->updateById($batchId, [
                'total_rows' => $analysis['total_rows'],
                'valid_rows' => $analysis['total_rows'] - $errorRows,
                'error_rows' => $errorRows,
                'control_total_diff' => $analysis['control']['diff'],
                'control_total_details' => $analysis['control']['details'] === null ? null : json_encode($analysis['control']['details'], JSON_UNESCAPED_UNICODE),
                'status' => 'da_luu',
            ]);
            $this->batches->replaceErrors($batchId, $this->errorRecords($analysis['rows']));
            $this->audit('IMPORT_METER_READINGS', 'import_batch', $batchId, ['status' => 'da_kiem_tra'], [
                'status' => 'da_luu',
                'file_name' => $batch['file_name'],
                'billing_period_id' => (int) $period['id'],
                'saved_readings' => count($savedIds),
                'reading_ids' => $savedIds,
                'error_rows' => $errorRows,
                'control_total' => $analysis['control']['status'],
                'mode' => $this->isStrictMode() ? 'all_or_nothing' : 'partial',
            ]);

            return ['saved' => count($savedIds), 'skipped' => $errorRows];
        });

        @unlink(self::storedPath($batchId)); // không giữ file chứa dữ liệu cá nhân sau khi xử lý xong

        return $result;
    }

    public function cancel(int $batchId): void
    {
        $this->transaction($this->batches, function () use ($batchId): void {
            $batch = $this->batches->findForUpdate($batchId);
            if ($batch === null) {
                throw ValidationException::withMessage('Lô import không tồn tại.');
            }
            if ($batch['status'] !== 'da_kiem_tra') {
                throw ValidationException::withMessage('Lô import đã được xác nhận hoặc đã hủy.');
            }
            $this->batches->updateById($batchId, ['status' => 'da_huy']);
            $this->audit('CANCEL_IMPORT_BATCH', 'import_batch', $batchId, ['status' => 'da_kiem_tra'], ['status' => 'da_huy']);
        });
        @unlink(self::storedPath($batchId));
    }

    /** File mẫu của kỳ: CH (+ nhãn khi căn có nhiều công tơ cùng loại), CSĐ hệ thống điền sẵn, công thức CL/Tổng. */
    public function buildTemplate(int $periodId, string $path): void
    {
        // Mẫu: 9 cột chuẩn + 2 cột tuỳ chọn "Mã công tơ điện/nước" (ở CUỐI - không xê dịch cột chuẩn / công thức).
        // Gom 1 dòng / căn theo mã căn (không theo nhãn hiển thị); căn có nhiều công tơ cùng loại -> thêm dòng, mỗi dòng
        // ghi rõ mã công tơ để import nhận diện bằng meter_code.
        $period = $this->periods->find($periodId);
        $codeHeaders = config('readings.import.meter_code_headers');
        $rows = [array_merge(config('readings.import.headers'), [$codeHeaders['dien'], $codeHeaders['nuoc']])];
        $byApartment = [];
        foreach ($this->periods->pendingMeters($periodId) as $meter) {
            $byApartment[$meter['apartment_code']][$meter['utility_type']][] = $meter;
        }
        $n = 0;
        foreach ($byApartment as $code => $meters) {
            $lines = max(count($meters['dien'] ?? []), count($meters['nuoc'] ?? []));
            for ($i = 0; $i < $lines; $i++) {
                $n++;
                $r = count($rows) + 1;
                $pair = ['dien' => $meters['dien'][$i] ?? null, 'nuoc' => $meters['nuoc'][$i] ?? null];
                $prev = [];
                foreach ($pair as $u => $m) {
                    $prev[$u] = $m !== null && $period !== null ? $this->readingService->previousReading($this->meters->findDetailed((int) $m['id']), $period)['value'] : null;
                }
                $rows[] = [$n, (string) $code, '',
                    $prev['dien'] !== null ? (float) $prev['dien'] : null, null, $pair['dien'] !== null ? ['f' => "IF(E{$r}=\"\",\"\",E{$r}-D{$r})"] : null,
                    $prev['nuoc'] !== null ? (float) $prev['nuoc'] : null, null, $pair['nuoc'] !== null ? ['f' => "IF(H{$r}=\"\",\"\",H{$r}-G{$r})"] : null,
                    $pair['dien']['meter_code'] ?? null, $pair['nuoc']['meter_code'] ?? null];
            }
        }
        $last = count($rows);
        $rows[] = ['Tổng', '', '', null, null, ['f' => "SUM(F2:F{$last})"], null, null, ['f' => "SUM(I2:I{$last})"]];
        XlsxWriter::write($path, $rows);
    }

    // ------------------------------------------------------------------ nội bộ

    /** Kiểm tra file upload (VAL-04.10). @return string tên file gốc đã làm sạch (chỉ để hiển thị) */
    private function checkFile(?array $file): string
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['file' => ['Vui lòng chọn file Excel.']]);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'File vượt dung lượng cho phép.' : 'Tải file lên không thành công (mã lỗi ' . $file['error'] . ').';
            throw new ValidationException(['file' => [$message]]);
        }
        $max = (int) config('readings.import.max_bytes');
        if ($file['size'] <= 0 || $file['size'] > $max || filesize($file['tmp_name']) > $max) {
            throw new ValidationException(['file' => ['File rỗng hoặc vượt ' . round($max / 1024 / 1024) . ' MB.']]);
        }

        $name = basename(str_replace('\\', '/', $file['name']));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = mb_substr($name, 0, 200);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($extension, config('readings.import.allowed_extensions'), true)) {
            throw new ValidationException(['file' => ['Chỉ chấp nhận file Excel theo mẫu (.xlsx).']]); // VAL-04.10
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!in_array($mime, config('readings.import.allowed_mimes'), true)) {
            throw new ValidationException(['file' => ["Nội dung file không phải Excel .xlsx (MIME phát hiện: {$mime})."]]);
        }

        return $name;
    }

    /**
     * Phân tích sheet: cấu trúc cột (VAL-04.11) -> từng dòng -> control total.
     * @return array{rows: array, total_rows:int, valid_rows:int, error_rows:int, warnings: string[], control: array{status:string,details:?array,diff:?string}}
     */
    private function analyze(array $period, array $sheet): array
    {
        [$headerRowNo, $map] = $this->headerMap($sheet);
        $totalLabels = config('readings.import.total_row_labels');

        $rows = [];
        $totalRow = null;
        $seenMeters = [];
        foreach ($sheet as $rowNo => $cells) {
            if ($rowNo <= $headerRowNo || $this->isEmptyRow($cells)) {
                continue;
            }
            $stt = $this->norm($cells[$map['STT']] ?? '');
            $ch = trim($cells[$map['CH']] ?? '');
            if (in_array($stt, $totalLabels, true) || in_array($this->norm($ch), $totalLabels, true)) {
                $totalRow = $cells;
                break; // dòng "Tổng" kết thúc dữ liệu
            }
            $rows[] = $this->analyzeRow($period, $rowNo, $cells, $map, $seenMeters);
        }

        if ($rows === []) {
            throw new ValidationException(['file' => ['File không có dòng dữ liệu chỉ số nào.']]);
        }

        $warnings = [];
        $control = $this->controlTotal($rows, $totalRow, $map, $sheet, $headerRowNo);
        if ($control['status'] === 'missing') {
            $warnings[] = 'Không tìm thấy dòng Tổng để đối chiếu.'; // [ĐX] FN-04.05 mục 9
        }

        $errorRows = count(array_filter($rows, static fn (array $r): bool => $r['errors'] !== []));

        return ['rows' => $rows, 'total_rows' => count($rows), 'valid_rows' => count($rows) - $errorRows, 'error_rows' => $errorRows,
            'warnings' => $warnings, 'control' => $control];
    }

    /** @return array{0:int,1:array<string,int>} số dòng tiêu đề, tên cột => chỉ số cột */
    private function headerMap(array $sheet): array
    {
        $expected = config('readings.import.headers');
        foreach ($sheet as $rowNo => $cells) {
            if ($this->isEmptyRow($cells)) {
                continue;
            }
            $found = [];
            foreach ($cells as $col => $value) {
                $found[$this->norm($value)] = $col;
            }
            $missing = array_values(array_filter($expected, fn (string $h): bool => !array_key_exists($this->norm($h), $found)));
            if ($missing !== []) {
                throw new ValidationException(['file' => ['File không đúng cấu trúc mẫu: thiếu cột ' . implode(', ', $missing) . '.']]); // VAL-04.11
            }
            $map = [];
            foreach ($expected as $position => $header) {
                if ($found[$this->norm($header)] !== $position) {
                    throw new ValidationException(['file' => ["File không đúng cấu trúc mẫu: cột \"{$header}\" phải ở vị trí thứ " . ($position + 1) . ' (sai thứ tự cột chuẩn).']]);
                }
                $map[$header] = $position;
            }
            foreach (config('readings.import.optional_headers') as $header) {
                if (array_key_exists($this->norm($header), $found)) {
                    $map[$header] = $found[$this->norm($header)];
                }
            }

            return [(int) $rowNo, $map];
        }

        throw new ValidationException(['file' => ['File rỗng.']]);
    }

    private function analyzeRow(array $period, int $rowNo, array $cells, array $map, array &$seenMeters): array
    {
        $stt = trim($cells[$map['STT']] ?? '');
        $ch = trim($cells[$map['CH']] ?? '');
        $label = $stt !== '' ? $stt : (string) $rowNo;
        $row = ['row_no' => $rowNo, 'stt' => $stt, 'ch' => $ch, 'apartment' => null, 'readings' => [], 'errors' => [], 'warnings' => []];

        if ($ch === '') {
            $row['errors'][] = "Dòng {$label}: thiếu mã căn hộ (cột CH).";

            return $row;
        }
        $parts = preg_split('/\s+/u', $ch, 2);
        $code = $parts[0];
        $meterLabel = trim($parts[1] ?? '');

        $apartments = $this->apartments->findAllByCode($code);
        if ($apartments === []) {
            $row['errors'][] = "Dòng {$label}: không tìm thấy căn hộ {$code}."; // VAL-04.12

            return $row;
        }
        if (count($apartments) > 1) {
            $row['errors'][] = "Dòng {$label}: mã căn hộ {$code} có ở nhiều tòa nhà — không xác định được căn hộ.";

            return $row;
        }
        $apartment = $apartments[0];
        $row['apartment'] = $apartment['code'];
        $meters = array_values(array_filter($this->meters->listByApartment((int) $apartment['id']), static fn (array $m): bool => $m['status'] === 'hoat_dong'));

        $units = ['dien' => ['Điện CSĐ', 'Điện CSC', 'Điện CL', 'Điện'], 'nuoc' => ['Nước CSĐ', 'Nước CSC', 'Nước CL', 'Nước']];
        foreach ($units as $utility => [$csdKey, $cscKey, $clKey, $name]) {
            $csd = trim($cells[$map[$csdKey]] ?? '');
            $csc = trim($cells[$map[$cscKey]] ?? '');
            $cl = trim($cells[$map[$clKey]] ?? '');
            if ($csd === '' && $csc === '') {
                continue; // dòng không có chỉ số tiện ích này (vd dòng chỉ cho công tơ KD điện)
            }

            // Ưu tiên nhận diện bằng mã công tơ (cột tuỳ chọn "Mã công tơ điện/nước"); không có -> cách cũ (tương thích ngược):
            // hậu tố sau mã căn so với nhãn hiển thị HOẶC mã công tơ; không hậu tố -> công tơ duy nhất của loại tiện ích.
            $codeHeader = config('readings.import.meter_code_headers')[$utility];
            $meterCode = isset($map[$codeHeader]) ? trim($cells[$map[$codeHeader]] ?? '') : '';
            if ($meterCode !== '') {
                $candidates = array_values(array_filter($meters, static fn (array $m): bool => mb_strtolower((string) $m['meter_code']) === mb_strtolower($meterCode)));
                if ($candidates !== [] && $candidates[0]['utility_type'] !== $utility) {
                    $row['errors'][] = "Dòng {$label} – {$name}: công tơ {$meterCode} không phải công tơ {$name}.";
                    continue;
                }
                if ($candidates === []) {
                    $row['errors'][] = "Dòng {$label} – {$name}: không tìm thấy công tơ {$meterCode} đang hoạt động thuộc căn hộ {$code}.";
                    continue;
                }
            } else {
                $candidates = array_values(array_filter($meters, static fn (array $m): bool => $m['utility_type'] === $utility
                    && ($meterLabel === '' || mb_strtolower((string) $m['display_label']) === mb_strtolower($meterLabel)
                        || mb_strtolower((string) $m['meter_code']) === mb_strtolower($meterLabel))));
            }
            if ($candidates === []) {
                $row['errors'][] = "Dòng {$label} – {$name}: căn hộ {$code} không có công tơ {$name} đang hoạt động" . ($meterLabel !== '' ? " với nhãn \"{$meterLabel}\"" : '') . '.';
                continue;
            }
            if (count($candidates) > 1) {
                $row['errors'][] = "Dòng {$label} – {$name}: không xác định được công tơ cho căn hộ {$code} (có " . count($candidates) . ' công tơ — ghi cột "Mã công tơ ' . mb_strtolower($name) . '" hoặc nhãn sau mã căn, vd "' . $code . ' KD").'; // VAL-04.13
                continue;
            }
            $meter = $this->meters->findDetailed((int) $candidates[0]['id']);
            if (isset($seenMeters[$meter['id']])) {
                $row['errors'][] = "Dòng {$label} – {$name}: công tơ {$meter['meter_code']} đã xuất hiện ở dòng {$seenMeters[$meter['id']]} (trùng trong file).";
                continue;
            }
            $seenMeters[$meter['id']] = $label;

            $previous = $this->readingService->previousReading($meter, $period);
            if ($csd === '') {
                $row['errors'][] = "Dòng {$label} – {$name}: thiếu CSĐ.";
            } elseif (Decimal::toCents($csd) === null) {
                $row['errors'][] = "Dòng {$label} – {$name}: CSĐ phải là số và không được để trống.";
            } elseif ($previous['cents'] !== null && Decimal::toCents($csd) !== $previous['cents']) {
                $row['errors'][] = "Dòng {$label} – {$name}: CSĐ trong file ({$csd}) khác CSĐ hệ thống (" . format_decimal($previous['value']) . ') — CSĐ lấy từ CSC đã xác nhận kỳ trước.';
            }

            $result = $this->validator->validate([
                'period' => $period, 'meter' => $meter, 'current_raw' => $csc,
                'previous_cents' => $previous['cents'], 'previous_blocked' => $previous['blocked'],
                'duplicate' => $this->readings->existsFor((int) $meter['id'], (int) $period['id']),
            ]);
            foreach ($result['errors'] as $error) {
                $suffix = $error['meter_reset'] ? ' (Thay/reset công tơ: chưa hỗ trợ khi nhập từ file)' : '';
                $row['errors'][] = "Dòng {$label} – {$name}: {$error['message']}{$suffix}";
            }
            if ($result['errors'] === [] && $previous['cents'] !== null) {
                $consumption = Decimal::fromCents($result['consumption_cents']);
                if ($cl !== '' && Decimal::toCents($cl) !== $result['consumption_cents']) {
                    $row['warnings'][] = "{$name}: CL trong file ({$cl}) khác CL hệ thống tính ({$consumption}) — hệ thống dùng CL tự tính.";
                }
                $row['readings'][] = ['meter_id' => (int) $meter['id'], 'meter_code' => $meter['meter_code'], 'utility' => $utility,
                    'previous' => $previous['value'], 'current' => Decimal::fromCents($result['current_cents']), 'consumption' => $consumption];
            }
        }

        if ($row['errors'] === [] && $row['readings'] === []) {
            $row['errors'][] = "Dòng {$label}: không có chỉ số điện/nước.";
        }
        if ($row['errors'] !== []) {
            $row['readings'] = []; // dòng có lỗi -> cả dòng không được lưu
        }

        return $row;
    }

    /**
     * FN-04.05: expected = dòng "Tổng" của file; actual = tổng tự cộng từ các dòng chi tiết ĐÃ ĐỌC;
     * difference = actual − expected. Lệch -> cảnh báo (VAL-04.14), không chặn cứng.
     * @return array{status:string,details:?array,diff:?string}
     */
    private function controlTotal(array $rows, ?array $totalRow, array $map, array $sheet, int $headerRowNo): array
    {
        if ($totalRow === null) {
            return ['status' => 'missing', 'details' => null, 'diff' => null];
        }

        $details = [];
        $sumAbs = 0;
        $detailRows = array_flip(array_column($rows, 'row_no'));
        foreach (config('readings.import.control_metrics') as $key => $metric) {
            if (!isset($map[$metric['column']])) {
                continue; // cột không có trong file -> không đối chiếu chỉ tiêu này
            }
            $expected = Decimal::toCents(trim($totalRow[$map[$metric['column']]] ?? ''));
            if ($expected === null) {
                continue;
            }

            $actual = 0;
            foreach ($sheet as $rowNo => $cells) {
                if ($rowNo <= $headerRowNo || !isset($detailRows[$rowNo])) {
                    continue;
                }
                if (str_starts_with($metric['actual'], 'consumption:')) {
                    $utility = substr($metric['actual'], 12);
                    [$csdKey, $cscKey] = $utility === 'dien' ? ['Điện CSĐ', 'Điện CSC'] : ['Nước CSĐ', 'Nước CSC'];
                    $csd = Decimal::toCents(trim($cells[$map[$csdKey]] ?? ''));
                    $csc = Decimal::toCents(trim($cells[$map[$cscKey]] ?? ''));
                    if ($csd !== null && $csc !== null) {
                        $actual += $csc - $csd;
                    }
                } else {
                    $actual += Decimal::toCents(trim($cells[$map[$metric['column']]] ?? '')) ?? 0;
                }
            }

            $difference = $actual - $expected;
            $sumAbs += abs($difference);
            $details[$key] = ['label' => $metric['label'], 'expected' => $expected / 100, 'actual' => $actual / 100, 'difference' => $difference / 100];
        }

        if ($details === []) {
            return ['status' => 'missing', 'details' => null, 'diff' => null];
        }

        return ['status' => $sumAbs === 0 ? 'match' : 'mismatch', 'details' => $details, 'diff' => Decimal::fromCents($sumAbs)];
    }

    /** @return array<int, array{row_no:int,apartment_code:?string,error_message:string}> */
    private function errorRecords(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            foreach ($row['errors'] as $message) {
                $records[] = ['row_no' => $row['row_no'], 'apartment_code' => $row['ch'] !== '' ? mb_substr($row['ch'], 0, 30) : null, 'error_message' => $message];
            }
        }

        return $records;
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }
}
