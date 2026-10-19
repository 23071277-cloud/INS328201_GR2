<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Services\TariffProposalService;
use App\Services\TariffService;

/** FN-03.03 Đề xuất cập nhật biểu giá (Kế toán) + FN-03.04 Duyệt/từ chối (BQL). */
class TariffProposalController extends BaseController
{
    private TariffProposalService $service;
    private TariffService $tariffs;

    public function __construct()
    {
        $this->service = new TariffProposalService();
        $this->tariffs = new TariffService();
    }

    public function index(): void
    {
        $filters = $this->filters(['status', 'utility_type']);

        $this->view('tariff_proposals.index', [
            'title' => 'Đề xuất biểu giá',
            'filters' => $filters,
            'pager' => $this->service->list($filters, $this->currentPage()),
            'latestVersions' => can('tariff_proposals.create') ? $this->tariffs->latestPerSeries() : [],
        ]);
    }

    /** ?tariff_id= phiên bản mới nhất của chuỗi cần đề xuất. */
    public function create(): void
    {
        $tariffId = $this->filters(['tariff_id'])['tariff_id'];

        try {
            $base = $this->service->baseForProposal(ctype_digit($tariffId) ? (int) $tariffId : 0);
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
            $this->redirect('/tariff-proposals');
        }

        $this->view('tariff_proposals.form', [
            'title' => t('page.tariff_proposals'),
            'base' => $base,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function store(): void
    {
        $input = $this->only(['tariff_id', 'vat_percent', 'business_unit_price', 'effective_date', 'reason']) + ['tiers' => $this->tiersInput()];
        $tariffId = ctype_digit((string) $input['tariff_id']) ? (int) $input['tariff_id'] : 0;

        try {
            $id = $this->service->create($tariffId, $input);
        } catch (ValidationException $e) {
            $this->backWithErrors('/tariff-proposals/create?tariff_id=' . $tariffId, $e, $input);
        }

        Flash::success('Đã gửi đề xuất, đang chờ BQL duyệt. Biểu giá hiện hành chưa thay đổi.');
        $this->redirect('/tariff-proposals/' . $id);
    }

    public function show(string $id): void
    {
        $proposal = $this->service->detail($this->routeId($id)) ?? $this->notFound();

        $this->view('tariff_proposals.show', [
            'title' => 'Đề xuất biểu giá #' . $proposal['id'],
            'proposal' => $proposal,
            'form' => Flash::pullFormState(),
        ]);
    }

    public function approve(string $id): void
    {
        $proposalId = $this->routeId($id);

        try {
            $tariffId = $this->service->approve($proposalId);
            Flash::success("Đã duyệt đề xuất. Phiên bản biểu giá mới #{$tariffId} đã được tạo.");
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/tariff-proposals/' . $proposalId);
    }

    public function reject(string $id): void
    {
        $proposalId = $this->routeId($id);
        $reason = $this->only(['rejection_reason'])['rejection_reason'] ?? '';

        try {
            $this->service->reject($proposalId, (string) $reason);
            Flash::success('Đã từ chối đề xuất. Biểu giá hiện hành giữ nguyên.');
        } catch (ValidationException $e) {
            Flash::setFormState($e->errors(), ['rejection_reason' => $reason]);
            Flash::error($e->getMessage());
        }

        $this->redirect('/tariff-proposals/' . $proposalId);
    }

    /** Bậc từ form: tiers[i][tier_from|tier_to|unit_price]. */
    private function tiersInput(): array
    {
        return $this->postRows('tiers', ['tier_from', 'tier_to', 'unit_price']);
    }
}
