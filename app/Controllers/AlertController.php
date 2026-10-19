<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AlertEvaluationService;
use App\Services\AlertService;
use App\Services\AlertVerificationService;
use App\Services\AlertWorkflowService;
use App\Services\EmailNotificationService;
use App\Services\NoUsageAlertWorkflowService;
use App\Services\RecommendationService;

/**
 * FN-07.02 - 07.10: đánh giá ngưỡng, cảnh báo thủ công, danh sách, chi tiết, review, ưu tiên, kiểm tra lại,
 * kết quả, xác nhận/điều chỉnh, đóng/mở lại. Controller chỉ đọc request + gọi Service; mọi kiểm tra trạng thái ở Service.
 */
class AlertController extends BaseController
{
    private AlertService $alerts;

    public function __construct()
    {
        $this->alerts = new AlertService();
    }

    public function index(): void
    {
        $filters = $this->filters(['period_id', 'utility_type', 'zone_id', 'q', 'source', 'priority', 'status', 'rule_id', 'alert_type']);
        $filters['alert_type'] = array_key_exists($filters['alert_type'], config('alerts.alert_type')) ? $filters['alert_type'] : '';
        $options = $this->alerts->formOptions();

        $this->view('alerts.index', [
            'title' => t('page.alerts'),
            'filters' => $filters,
            'rows' => $this->alerts->list($filters, auth_user()),
            'periods' => $options['periods'],
            'zones' => $options['zones'],
            'rules' => $options['rules'],
        ]);
    }

    public function evaluate(): void
    {
        $periodId = Request::postString('period_id');
        try {
            if (!ctype_digit($periodId)) {
                throw ValidationException::withMessage('Vui lòng chọn kỳ để đánh giá.');
            }
            $r = (new AlertEvaluationService())->evaluatePeriod((int) $periodId);
            Flash::success("Đánh giá {$r['rules']} quy tắc trên {$r['evaluated']} đối tượng: " . count($r['created']) . ' cảnh báo mới, ' . $r['duplicates'] . ' đã có (không sinh trùng).');
            if (isset($r['j16'])) {
                Flash::info('Căn khai báo không sử dụng nhưng có tiêu thụ: ' . count($r['j16']['created']) . ' cảnh báo mới, ' . count($r['j16']['updated']) . ' cập nhật số liệu, '
                    . $r['j16']['unchanged'] . ' không đổi' . ($r['j16']['noted'] !== [] ? ', ' . count($r['j16']['noted']) . ' không còn thỏa điều kiện (chờ BQL đóng)' : '') . '.');
            }
            foreach ($r['skipped'] as $reason => $n) {
                if ($reason !== 'Trong ngưỡng') {
                    Flash::info("Bỏ qua {$n}: {$reason}");
                }
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/alerts' . (ctype_digit($periodId) ? '?period_id=' . (int) $periodId : ''));
    }

    public function create(): void
    {
        $this->view('alerts.create', ['title' => 'Tạo cảnh báo thủ công', 'form' => Flash::pullFormState(), 'options' => $this->alerts->formOptions()]);
    }

    public function store(): void
    {
        $input = [];
        foreach (['apartment_id', 'meter_id', 'billing_period_id', 'utility_type', 'description', 'priority'] as $k) {
            $input[$k] = Request::postString($k);
        }
        try {
            $r = $this->alerts->createManual($input);
            Flash::success('Đã tạo cảnh báo thủ công (Mới). BQL sẽ thấy trong danh sách cảnh báo.');
            if ($r['open_existing'] > 0) {
                Flash::info("Căn/công tơ này đã có {$r['open_existing']} cảnh báo đang mở cùng kỳ — có thể bổ sung thông tin vào cảnh báo cũ.");
            }
            $this->redirect('/alerts/' . $r['id']);
        } catch (ValidationException $e) {
            $this->backWithErrors('/alerts/create', $e, $input);
        }
    }

    public function show(string $id): void
    {
        $alertId = $this->routeId($id);
        $this->guardVisible($alertId);
        $detail = $this->alerts->detail($alertId) ?? $this->notFound();

        $this->view('alerts.show', [
            'title' => 'Cảnh báo #' . $alertId,
            'd' => $detail,
            'form' => Flash::pullFormState(),
            // FN-08.02: chỉ role có quyền xem khuyến nghị (BQL; KTo/KT: NEED BA)
            'recommendations' => can('recommendations.view') ? (new RecommendationService())->forAlert($alertId) : null,
            'recommendationPreview' => can('recommendations.view') ? (new RecommendationService())->previewForAlert($alertId) : null,
        ]);
    }

    /** FN-08.02: BQL bấm "Sinh khuyến nghị" [ASSUMPTION T3]. Không đổi trạng thái cảnh báo. */
    public function generateRecommendations(string $id): void
    {
        $alertId = $this->routeId($id);
        try {
            $r = (new RecommendationService())->generateForAlert($alertId);
            $created = count($r['created']);
            if ($created === 0 && $r['duplicates'] === 0) {
                Flash::info('Không có quy tắc khuyến nghị nào thỏa điều kiện — không tạo khuyến nghị.');
            } else {
                Flash::success("Sinh khuyến nghị: {$created} mới, {$r['duplicates']} đã có (không tạo trùng).");
            }
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect('/alerts/' . $alertId);
    }

    public function review(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertWorkflowService())->review($a, Request::postString('priority'), Request::postString('note'), Request::postString('version_token')), 'Đã review cảnh báo.');
    }

    public function priority(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertWorkflowService())->changePriority($a, Request::postString('priority'), Request::postString('note'), Request::postString('version_token')), 'Đã đổi mức ưu tiên.');
    }

    public function requestVerification(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertVerificationService())->request($a, Request::postString('content'), Request::postString('assigned_to'), Request::postString('version_token')), 'Đã tạo yêu cầu kiểm tra lại cho Kỹ thuật.');
    }

    public function verificationResult(string $id): void
    {
        $alertId = $this->routeId($id);
        $this->guardVisible($alertId);
        $this->act($id, fn (int $a) => (new AlertVerificationService())->submitResult($a, Request::postString('result_conclusion'), Request::postString('rechecked_value'), Request::postString('note')), 'Đã cập nhật kết quả kiểm tra. BQL sẽ kết luận.');
    }

    public function confirm(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertVerificationService())->confirm($a, Request::postString('decision'), Request::postString('note'), Request::postString('version_token'),
            ['current_reading' => Request::postString('current_reading'), 'reading_token' => Request::postString('reading_token')]),
            'Đã xác nhận dữ liệu sau kiểm tra (Đã xác nhận).');
    }

    public function close(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertWorkflowService())->close($a, Request::postString('close_reason'), Request::postString('version_token')), 'Đã đóng cảnh báo.');
    }

    public function reopen(string $id): void
    {
        $this->act($id, fn (int $a) => (new AlertWorkflowService())->reopen($a, Request::postString('reopen_reason'), Request::postString('version_token')), 'Đã mở lại cảnh báo (Đã review).');
    }

    // ------------------------------------------------------------------ [BA Proposal J-16]

    /** BQL ghi nhận "Đã gọi chủ hộ" (contact_type=phone) hoặc phản hồi cư dân (resident_response). Không gọi điện tự động. */
    public function j16Contact(string $id): void
    {
        $type = Request::postString('contact_type');
        $this->act($id, fn (int $a) => (new NoUsageAlertWorkflowService())->recordContact($a, $type, Request::postString('contact_time'), Request::postString('result'), Request::postString('contact_note')),
            $type === 'phone' ? 'Đã ghi nhận cuộc gọi chủ hộ.' : 'Đã ghi nhận phản hồi của cư dân.');
    }

    /** BQL gửi email xác minh (FN-10.03 transport; lỗi gửi không làm hỏng luồng xử lý cảnh báo). */
    public function j16Email(string $id): void
    {
        $alertId = $this->routeId($id);
        try {
            $r = (new EmailNotificationService())->sendConsumptionVerification($alertId, Request::postString('resident_id'));
            $r['status'] === 'sent' ? Flash::success('Đã gửi email xác minh cho cư dân.')
                : Flash::error('Gửi email thất bại: ' . $r['error'] . ' — có thể "Gửi lại" trong hộp thư đi; luồng xác minh vẫn tiếp tục.');
        } catch (ValidationException $e) {
            $this->backWithErrors('/alerts/' . $alertId, $e, ['resident_id' => Request::postString('resident_id')]);
        }
        $this->redirect('/alerts/' . $alertId);
    }

    public function j16Conclude(string $id): void
    {
        $this->act($id, fn (int $a) => (new NoUsageAlertWorkflowService())->conclude($a, Request::postString('conclusion'), Request::postString('conclusion_note'),
            Request::postString('follow_up_date'), Request::postString('version_token')), 'Đã lưu kết luận của BQL.');
    }

    public function j16ApartmentStatus(string $id): void
    {
        $this->act($id, fn (int $a) => (new NoUsageAlertWorkflowService())->updateApartmentStatus($a, Request::postString('confirm_apartment'), Request::postString('version_token')),
            'Đã cập nhật trạng thái căn hộ. Bản tính phí của căn (nếu có) cần Kế toán tính lại.');
    }

    public function j16AdjustReading(string $id): void
    {
        $this->act($id, fn (int $a) => (new NoUsageAlertWorkflowService())->adjustReading($a, Request::postString('reading_id'), Request::postString('current_reading'),
            Request::postString('adjust_note'), Request::postString('reading_token'), Request::postString('version_token')),
            'Đã điều chỉnh chỉ số (về Nháp, chờ BQL duyệt lại; hệ thống tự đánh giá lại khi duyệt).');
    }

    // ------------------------------------------------------------------

    /** Chạy thao tác Service, PRG về trang chi tiết; lỗi field -> form state cho modal. */
    private function act(string $id, callable $action, string $success): void
    {
        $alertId = $this->routeId($id);
        try {
            $action($alertId);
            Flash::success($success);
        } catch (ValidationException $e) {
            $old = [];
            foreach (['priority', 'note', 'content', 'assigned_to', 'result_conclusion', 'rechecked_value', 'decision', 'current_reading', 'close_reason', 'reopen_reason',
                'contact_type', 'contact_time', 'result', 'contact_note', 'conclusion', 'conclusion_note', 'follow_up_date', 'reading_id', 'adjust_note'] as $k) {
                $old[$k] = Request::postString($k);
            }
            $this->backWithErrors('/alerts/' . $alertId, $e, $old);
        }
        $this->redirect('/alerts/' . $alertId);
    }

    /** Kỹ thuật chỉ xem cảnh báo mình tạo / case giao cho mình (S12) - URL trực tiếp -> 403. */
    private function guardVisible(int $alertId): void
    {
        if (!$this->alerts->canView($alertId, auth_user())) {
            http_response_code(403);
            require BASE_PATH . '/resources/views/errors/403.php';
            exit;
        }
    }
}
