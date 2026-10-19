<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\TariffProposalRepository;
use App\Repositories\TariffRepository;

/**
 * FN-03.03 Đề xuất cập nhật biểu giá (Kế toán) + FN-03.04 Duyệt/từ chối (BQL).
 * [TL] 2.3 "Propose update (chờ BQL Approve)", 4.4 (FR-05 dùng biểu giá đã duyệt); BR-21, BR-42.
 * Trạng thái theo G7: cho_duyet -> da_duyet | tu_choi. KHÔNG có trạng thái "Lỗi thời":
 * đề xuất dựa trên dữ liệu đã cũ bị chặn DUYỆT bằng technical safeguard (P11), vẫn Chờ duyệt.
 * Chỉ áp dụng cho biểu giá điện/nước đã có (P9) - không có đề xuất phí cố định (B9/J-06).
 */
class TariffProposalService extends BaseService
{
    private TariffProposalRepository $proposals;
    private TariffRepository $tariffs;
    private TariffService $tariffService;

    public function __construct()
    {
        $this->proposals = new TariffProposalRepository();
        $this->tariffs = new TariffRepository();
        $this->tariffService = new TariffService();
    }

    public function list(array $filters, int $page): array
    {
        $filters['status'] = array_key_exists($filters['status'], config('pricing.proposal_status')) ? $filters['status'] : '';
        $filters['utility_type'] = array_key_exists($filters['utility_type'], config('master_data.utility_type')) ? $filters['utility_type'] : '';

        return $this->proposals->search($filters, $page, (int) config('master_data.per_page', 20));
    }

    /** Chi tiết: dữ liệu đề xuất + phiên bản gốc + lý do không duyệt được (nếu có). */
    public function detail(int $id): ?array
    {
        $proposal = $this->proposals->findDetailed($id);
        if ($proposal === null) {
            return null;
        }

        $proposal['data'] = $this->decode($proposal['proposed_data']);
        $proposal['base'] = $proposal['tariff_id'] !== null ? $this->tariffService->find((int) $proposal['tariff_id']) : null;
        $proposal['approve_block_reason'] = $proposal['status'] === 'cho_duyet' ? $this->approveBlockReason($proposal) : null;

        return $proposal;
    }

    /** Phiên bản gốc để đề xuất: phải là phiên bản mới nhất của chuỗi (P9). */
    public function baseForProposal(int $tariffId): array
    {
        $base = $this->tariffService->find($tariffId);
        if ($base === null) {
            throw ValidationException::withMessage('Biểu giá cần đề xuất không tồn tại.');
        }

        $latest = $this->tariffs->lockSeries($base['utility_type'], $base['usage_type'])[0] ?? null;
        if ($latest === null || (int) $latest['id'] !== (int) $base['id']) {
            throw ValidationException::withMessage('Chỉ đề xuất dựa trên phiên bản mới nhất của biểu giá. Vui lòng mở phiên bản mới nhất.');
        }

        return $base;
    }

    public function create(int $tariffId, array $input): int
    {
        return $this->transaction($this->proposals, function () use ($tariffId, $input): int {
            $base = $this->baseForProposal($tariffId); // khoá chuỗi -> tuần tự hoá việc gửi đề xuất

            if ($this->proposals->pendingExists($base['utility_type'], $base['usage_type'])) {
                // VAL-03.05 [ĐX]
                throw ValidationException::withMessage('Biểu giá này đang có đề xuất chờ duyệt.');
            }

            $payload = $this->tariffService->validatePayload($input, $base['utility_type'], $base['usage_type']);
            $reason = (string) ($input['reason'] ?? '');
            $v = new Validator(['reason' => $reason]);
            $v->maxLength('reason', 500, 'Lý do/căn cứ tối đa 500 ký tự.')->throwIfFails();

            $this->tariffService->assertEffectiveDateAllowed($base['utility_type'], $base['usage_type'], $payload['effective_date'], null);

            $id = $this->proposals->insertRow([
                'tariff_id' => (int) $base['id'],
                'utility_type' => $base['utility_type'],
                'usage_type' => $base['usage_type'],
                'proposed_data' => json_encode([
                    'vat_percent' => $payload['vat_percent'],
                    'business_unit_price' => $payload['business_unit_price'],
                    'tiers' => $payload['tiers'],
                ], JSON_UNESCAPED_UNICODE),
                'effective_date' => $payload['effective_date'],
                'reason' => $this->nullIfEmpty($reason),
                'status' => 'cho_duyet',
                'proposed_by' => $this->actorId(),
            ]);
            // Biểu giá hiện hành KHÔNG đổi khi mới gửi đề xuất (BR-21).
            $this->audit('CREATE_TARIFF_PROPOSAL', 'tariff_proposal', $id, null, $this->proposals->find($id));

            return $id;
        });
    }

    /**
     * FN-03.04 Duyệt (P12): transaction + khoá đề xuất (SELECT ... FOR UPDATE).
     * Hai BQL bấm Duyệt cùng lúc: request sau chờ khoá, thấy trạng thái đã đổi -> bị từ chối.
     * @return int id phiên bản biểu giá mới
     */
    public function approve(int $id): int
    {
        return $this->transaction($this->proposals, function () use ($id): int {
            $proposal = $this->lockPending($id);
            $this->tariffs->lockSeries($proposal['utility_type'], $proposal['usage_type']);

            $blocked = $this->approveBlockReason($proposal);
            if ($blocked !== null) {
                throw ValidationException::withMessage('Không thể duyệt: ' . $blocked);
            }

            $payload = $this->tariffService->validatePayload(
                $this->decode($proposal['proposed_data']) + ['effective_date' => $proposal['effective_date']],
                $proposal['utility_type'],
                $proposal['usage_type']
            );
            $now = date('Y-m-d H:i:s');
            if ($this->proposals->approvesInPlace()) {
                // [STEP 4C] schema v2: chính dòng đề xuất thành phiên bản hiệu lực (người đề xuất = created_by, BQL duyệt = reviewed_by)
                $this->proposals->approveInPlace($id, $payload, (int) $this->actorId(), $now);
                $tariffId = $id;
            } else {
                $tariffId = $this->tariffService->insertVersion(
                    $proposal['utility_type'],
                    $proposal['usage_type'],
                    $payload,
                    (int) $proposal['proposed_by'], // người đề xuất
                    $this->actorId()                // BQL duyệt
                );
                $this->proposals->updateById($id, ['status' => 'da_duyet', 'reviewed_by' => $this->actorId(), 'reviewed_at' => $now]);
            }

            $this->audit('CREATE_TARIFF', 'tariff', $tariffId, null, $this->tariffService->snapshot($tariffId) + ['source_proposal_id' => $id]);
            $this->audit('APPROVE_TARIFF_PROPOSAL', 'tariff_proposal', $id,
                ['status' => 'cho_duyet'],
                ['status' => 'da_duyet', 'reviewed_at' => $now, 'created_tariff_id' => $tariffId]
            );

            return $tariffId;
        });
    }

    /** FN-03.04 Từ chối: lý do bắt buộc (VAL-03.06), biểu giá hiện hành giữ nguyên. */
    public function reject(int $id, string $reason): void
    {
        $v = new Validator(['rejection_reason' => $reason]);
        $v->required('rejection_reason', 'Vui lòng nhập lý do từ chối.')
            ->maxLength('rejection_reason', 500, 'Lý do từ chối tối đa 500 ký tự.')
            ->throwIfFails();

        $this->transaction($this->proposals, function () use ($id, $reason): void {
            $this->lockPending($id);

            $now = date('Y-m-d H:i:s');
            $changes = ['status' => 'tu_choi', 'reviewed_by' => $this->actorId(), 'reviewed_at' => $now, 'rejection_reason' => $reason];
            $this->proposals->updateById($id, $changes);
            $this->audit('REJECT_TARIFF_PROPOSAL', 'tariff_proposal', $id, ['status' => 'cho_duyet'], $changes);
        });
    }

    /**
     * P11 - technical safeguard (KHÔNG phải trạng thái nghiệp vụ): lý do không được DUYỆT, null nếu được.
     * Chuỗi đã có phiên bản tạo/sửa sau khi gửi đề xuất, hoặc ngày hiệu lực nay không còn hợp lệ.
     */
    public function approveBlockReason(array $proposal): ?string
    {
        if ($this->tariffs->countModifiedAfter($proposal['utility_type'], $proposal['usage_type'], (string) $proposal['created_at']) > 0) {
            return 'biểu giá đã có phiên bản được tạo/sửa sau khi đề xuất được gửi, đề xuất có thể dựa trên dữ liệu cũ. '
                . 'Hãy từ chối và để Kế toán đề xuất lại (an toàn kỹ thuật, không phải trạng thái nghiệp vụ).';
        }

        try {
            $this->tariffService->assertEffectiveDateAllowed($proposal['utility_type'], $proposal['usage_type'], (string) $proposal['effective_date'], null);
        } catch (ValidationException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function lockPending(int $id): array
    {
        $proposal = $this->proposals->findForUpdate($id);
        if ($proposal === null) {
            throw ValidationException::withMessage('Đề xuất không tồn tại.');
        }
        if ($proposal['status'] !== 'cho_duyet') {
            throw ValidationException::withMessage('Đề xuất đã được xử lý, vui lòng tải lại.'); // [ĐX] FN-03.04 mục 9
        }

        return $proposal;
    }

    private function decode(?string $json): array
    {
        $data = json_decode((string) $json, true);

        return is_array($data) ? $data + ['vat_percent' => null, 'business_unit_price' => null, 'tiers' => []] : ['vat_percent' => null, 'business_unit_price' => null, 'tiers' => []];
    }
}
