<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\BaseService;
use App\Core\Decimal;
use App\Core\ValidationException;
use App\Repositories\ApartmentChargeRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\BillingAdjustmentRepository;
use App\Repositories\BillingPeriodRepository;
use App\Repositories\ApartmentRepository;

final class BillingAdjustmentService extends BaseService
{
    private BillingAdjustmentRepository $repo;
    public function __construct() { $this->repo = new BillingAdjustmentRepository(); }
    public function available(): bool { return $this->repo->available(); }
    public static function statusLabel(string $status): string
    {
        return ['submitted'=>'Chờ duyệt','approved'=>'Đã duyệt','rejected'=>'Từ chối','withdrawn'=>'Đã rút','stale_input'=>'Dữ liệu đã đổi'][$status] ?? $status;
    }
    private function ready(): void
    {
        if (!$this->available()) { throw ValidationException::withMessage('Chưa triển khai migration đối soát. Không thể lập đề nghị.'); }
    }
    private function actor(string $role): int
    {
        $user = $this->repo->rows("SELECT id,role,status FROM users WHERE id=?", [(int)$this->actorId()])[0] ?? null;
        if (!$user || $user['role'] !== $role || $user['status'] !== 'hoat_dong') {
            throw ValidationException::withMessage('Không có quyền thực hiện thao tác này.');
        }
        return (int)$user['id'];
    }
    private function context(int $id, bool $lock = false): array
    {
        $charges = new ApartmentChargeRepository();
        $plain = $charges->find($id);
        if (!$plain) { throw ValidationException::withMessage('Bản tính không tồn tại.'); }
        $periods = new BillingPeriodRepository();
        $period = $lock ? $periods->findForUpdate((int)$plain['billing_period_id']) : $periods->find((int)$plain['billing_period_id']);
        $charge = $lock ? $charges->findForUpdate($id) : $plain;
        if ($lock) {
            // Parking edits lock the apartment; serialize capture with those edits too.
            $this->repo->rows('SELECT id FROM apartments WHERE id=? FOR UPDATE',[$charge['apartment_id']]);
        }
        return [$charge,$period];
    }
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    /** Read-only capture. Only full-period parking differences can be reconciled here. */
    public function capture(int $id): array
    {
        [$charge,$period] = $this->context($id);
        if (!in_array($charge['status'],config('billing.lockable_statuses'),true)) {
            throw ValidationException::withMessage('Bản gốc chưa được xác nhận.');
        }
        $notices = $this->repo->rows("SELECT id,status,content_hash,subject,body_html,body_text,sent_at FROM email_notifications
            WHERE apartment_charge_id=? AND notification_type='bill_notice' AND status='sent' ORDER BY id",[$id]);
        if (!$notices) { throw ValidationException::withMessage('Chỉ đối soát bản tính có phiếu đã gửi thành công.'); }
        if ($this->repo->rows("SELECT id FROM email_notifications WHERE apartment_charge_id=? AND notification_type='bill_notice' AND status='pending'",[$id])) {
            throw ValidationException::withMessage('Còn phiếu đang gửi; chưa thể đối soát.');
        }
        $log = (new AuditLogRepository())->latestForEntity('apartment_charge',(string)$id,BillingCalculationService::SNAPSHOT_ACTIONS);
        $old = $log ? (json_decode($log['new_value'],true)['snapshot'] ?? null) : null;
        if (!$old) { throw ValidationException::withMessage('Thiếu snapshot bản gốc; cần kiểm tra thủ công.'); }
        $apartment = (new ApartmentRepository())->find((int)$charge['apartment_id']);
        $current = (new BillingCalculationService())->compute($period,$apartment,MoneyCalculator::fromDb($charge['old_debt_amount']));
        if (!$current['ok']) { throw ValidationException::withMessage(implode('; ',$current['errors'])); }
        $delta = $current['columns']['parking_fee_amount'] - MoneyCalculator::fromDb($charge['parking_fee_amount']);
        if ($delta < 0) { throw ValidationException::withMessage('Quy trình tối thiểu chỉ hỗ trợ ghi nợ bổ sung, không hỗ trợ giảm phí.'); }
        foreach (ApartmentChargeRepository::AMOUNT_COLUMNS as $column) {
            $expected = MoneyCalculator::fromDb($charge[$column]) + (in_array($column,['parking_fee_amount','total_arising','total_due'],true) ? $delta : 0);
            if ($current['columns'][$column] !== $expected) {
                throw ValidationException::withMessage('Có chênh lệch ngoài phí gửi xe: '.$column.'. Không thể dùng đối soát này.');
            }
        }
        $tiers = $this->repo->rows('SELECT meter_reading_id,utility_type,tier_order,tier_from,tier_to,quantity,unit_price,amount FROM charge_tier_details WHERE apartment_charge_id=? ORDER BY meter_reading_id,tier_order',[$id]);
        $normalize = static fn ($r) => implode('|',[(int)$r['meter_reading_id'],$r['utility_type'],(int)$r['tier_order'],
            MoneyCalculator::fromDb($r['tier_from']),$r['tier_to'] === null ? '' : MoneyCalculator::fromDb($r['tier_to']),
            MoneyCalculator::fromDb($r['quantity']),MoneyCalculator::fromDb($r['unit_price']),MoneyCalculator::fromDb($r['amount'])]);
        $before = array_map($normalize,$tiers); $after = [];
        foreach ($current['meters'] as $m) { foreach ($m['lines'] as $l) {
            $after[] = implode('|',[$m['reading_id'],$m['utility_type'],$l['tier_order'],$l['tier_from'],$l['tier_to'] ?? '',$l['quantity'],$l['unit_price'],$l['amount']]);
        }}
        sort($before); sort($after);
        if ($before !== $after) { throw ValidationException::withMessage('Chi tiết điện/nước đã đổi; đối soát gửi xe không giải quyết được.'); }
        $oldVersions = $old['versions'] ?? []; $newVersions = $current['snapshot']['versions'];
        foreach (array_values(config('billing.parking')) as $type) { unset($oldVersions['fee:'.$type],$newVersions['fee:'.$type]); }
        if ($oldVersions != $newVersions || ($old['apartment'] ?? []) != $current['snapshot']['apartment']) {
            throw ValidationException::withMessage('Phiên bản giá điện/nước, phí khác hoặc thông tin căn đã đổi.');
        }
        foreach ($notices as &$notice) {
            $notice['body_hash'] = hash('sha256',$notice['subject']."\n".$notice['body_html']."\n".$notice['body_text']);
            unset($notice['subject'],$notice['body_html'],$notice['body_text']);
        } unset($notice);
        $readings = $this->repo->rows('SELECT r.*,m.utility_type,m.usage_type,m.status meter_status FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.billing_period_id=? AND m.apartment_id=? ORDER BY r.id',[$period['id'],$apartment['id']]);
        return ['charge'=>$charge,'period'=>['id'=>$period['id'],'period_code'=>$period['period_code'],'start_date'=>$period['start_date'],'end_date'=>$period['end_date']],
            'original_audit_id'=>$log['id'],'original_audit_hash'=>hash('sha256',$log['new_value']),
            'notices'=>$notices,'tiers'=>$tiers,'readings'=>$readings,
            'vehicles'=>$this->repo->rows('SELECT vehicle_type,quantity FROM parking_vehicles WHERE apartment_id=? ORDER BY vehicle_type',[$apartment['id']]),
            'current'=>$current['snapshot'],'amount'=>Decimal::fromCents($delta)];
    }
    public function submit(int $chargeId, array $input): int
    {
        $this->ready(); $actor = $this->actor('ke_toan');
        foreach (['explanation'=>1000,'evidence'=>2000] as $field=>$max) {
            $input[$field] = trim((string)($input[$field] ?? ''));
            if ($input[$field] === '' || mb_strlen($input[$field]) > $max) { throw ValidationException::withMessage('Cần lý do và bằng chứng hợp lệ.'); }
        }
        if (!preg_match('/^[a-f0-9]{64}$/',(string)($input['idempotency_key'] ?? ''))) { throw ValidationException::withMessage('Mã đề nghị không hợp lệ.'); }
        return $this->transaction($this->repo,function () use ($chargeId,$input,$actor): int {
            [$charge,$period] = $this->context($chargeId,true);
            if ($period['status'] === 'da_khoa') { throw ValidationException::withMessage('Kỳ đã khóa; không lập đối soát.'); }
            $existing = $this->repo->rows('SELECT * FROM billing_adjustment_requests WHERE idempotency_key=?',[$input['idempotency_key']])[0] ?? null;
            if ($existing) {
                if ((int)$existing['charge_id'] !== $chargeId || (int)$existing['initiated_by'] !== $actor) { throw ValidationException::withMessage('Mã đề nghị đã dùng.'); }
                return (int)$existing['id'];
            }
            if ($this->repo->rows("SELECT id FROM billing_adjustment_requests WHERE charge_id=? AND status IN ('submitted','approved')",[$chargeId])) {
                throw ValidationException::withMessage('Đã có đề nghị đang chờ hoặc đã duyệt.');
            }
            $snapshot = $this->capture($chargeId); $hash = self::fingerprint($snapshot);
            if (!hash_equals($hash,(string)($input['input_hash'] ?? ''))) { throw ValidationException::withMessage('Dữ liệu đã đổi; tải lại trước khi gửi.'); }
            if (($input['effective_date'] ?? '') !== $period['start_date'] || ($input['full_period'] ?? '') !== '1') {
                throw ValidationException::withMessage('Phải xác nhận áp dụng toàn kỳ và ngày hiệu lực đúng ngày đầu kỳ. Không tự suy ra từ ngày nhập xe.');
            }
            $id = $this->repo->insert('billing_adjustment_requests',['charge_id'=>$chargeId,'initiated_by'=>$actor,
                'effective_date'=>$period['start_date'],'explanation'=>$input['explanation'],'evidence'=>$input['evidence'],
                'amount'=>$snapshot['amount'],'input_hash'=>$hash,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'idempotency_key'=>$input['idempotency_key']]);
            $this->audit('SUBMIT_BILLING_ADJUSTMENT','billing_adjustment',$id,null,['charge_id'=>$chargeId,'input_hash'=>$hash,'amount'=>$snapshot['amount'],'effective_date'=>$period['start_date'],'explanation'=>$input['explanation'],'evidence'=>$input['evidence']]);
            return $id;
        });
    }
    public function decide(int $id, string $action, string $reason): string
    {
        $this->ready();
        if (!in_array($action,['approve','reject','withdraw'],true)) { throw ValidationException::withMessage('Thao tác không hợp lệ.'); }
        $actor = $this->actor($action === 'withdraw' ? 'ke_toan' : 'bql');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason)>1000) { throw ValidationException::withMessage('Cần lý do quyết định, tối đa 1000 ký tự.'); }
        return $this->transaction($this->repo,function () use ($id,$action,$reason,$actor): string {
            $plain = $this->repo->find($id);
            if (!$plain) { throw ValidationException::withMessage('Đề nghị không tồn tại.'); }
            [$charge,$period] = $this->context((int)$plain['charge_id'],true);
            $q = $this->repo->findForUpdate($id);
            if ($action === 'withdraw' && (int)$q['initiated_by'] !== $actor) { throw ValidationException::withMessage('Chỉ người lập được rút đề nghị.'); }
            if ($action !== 'withdraw' && (int)$q['initiated_by'] === $actor) { throw ValidationException::withMessage('Người lập không được tự duyệt / từ chối.'); }
            if ($q['status'] === 'approved' && $action === 'approve') { return 'approved'; }
            if ($q['status'] !== 'submitted') { throw ValidationException::withMessage('Đề nghị đã kết thúc; lập đề nghị mới.'); }
            $status = $action === 'withdraw' ? 'withdrawn' : 'rejected';
            $documents = [];
            if ($action === 'approve') {
                if ($period['status'] === 'da_khoa') { throw ValidationException::withMessage('Kỳ đã khóa.'); }
                try { $snapshot = $this->capture((int)$q['charge_id']); }
                catch (ValidationException $e) { $snapshot = null; }
                if (!$snapshot || !hash_equals($q['input_hash'],self::fingerprint($snapshot))) { $status = 'stale_input'; }
                else {
                    $status = 'approved';
                    $rid = $this->repo->insert('billing_reconciliations',['request_id'=>$id,'charge_id'=>$q['charge_id'],
                        'approved_by'=>$actor,'input_hash'=>$q['input_hash'],'snapshot'=>$q['snapshot']]);
                    $documents = ['reconciliation_id'=>$rid,'amount'=>$q['amount'],'input_hash'=>$q['input_hash']];
                    if (MoneyCalculator::fromDb($q['amount'])>0) {
                        $documents['debit_id'] = $this->repo->insert('supplementary_debits',['reconciliation_id'=>$rid,'charge_id'=>$q['charge_id'],'amount'=>$q['amount']]);
                    }
                }
            }
            $this->repo->execute('UPDATE billing_adjustment_requests SET status=?,decided_by=?,decision_reason=?,decided_at=NOW() WHERE id=?',[$status,$actor,$reason,$id]);
            $this->audit('DECIDE_BILLING_ADJUSTMENT','billing_adjustment',$id,['status'=>'submitted'],['status'=>$status,'reason'=>$reason,'charge_id'=>$q['charge_id']]+$documents);
            return $status;
        });
    }
    /** Fail closed: only exact, approved coverage can satisfy the stale check. */
    public function reconciled(int $chargeId): bool
    {
        if (!$this->available()) { return false; }
        if (\App\Core\Database::connection()->inTransaction()) { $this->context($chargeId,true); }
        $r = $this->repo->rows("SELECT r.*,q.status,q.amount,q.initiated_by,q.decided_by,q.effective_date,q.input_hash request_hash,q.charge_id request_charge,q.snapshot request_snapshot,
            d.amount debit_amount,d.charge_id debit_charge FROM billing_reconciliations r
            JOIN billing_adjustment_requests q ON q.id=r.request_id LEFT JOIN supplementary_debits d ON d.reconciliation_id=r.id WHERE r.charge_id=?",[$chargeId])[0] ?? null;
        if (!$r || $r['status'] !== 'approved' || $r['initiated_by'] == $r['decided_by'] || $r['approved_by'] != $r['decided_by'] || $r['input_hash'] !== $r['request_hash']
            || $r['request_charge'] != $chargeId || $r['snapshot'] !== $r['request_snapshot']) { return false; }
        if ($this->repo->rows("SELECT id FROM billing_adjustment_requests WHERE charge_id=? AND status='submitted'",[$chargeId])) { return false; }
        try { $snapshot = $this->capture($chargeId); } catch (ValidationException $e) { return false; }
        $amount = MoneyCalculator::fromDb($snapshot['amount']);
        return $r['effective_date'] === $snapshot['period']['start_date'] && $amount === MoneyCalculator::fromDb($r['amount'])
            && hash_equals($r['input_hash'],self::fingerprint($snapshot))
            && ($amount === 0 ? $r['debit_amount'] === null : ($r['debit_charge'] == $chargeId && $amount === MoneyCalculator::fromDb($r['debit_amount'])));
    }
    public function history(int $charge): array { return $this->available() ? $this->repo->history($charge) : []; }
    public function pendingForPeriod(int $period): int
    {
        return $this->available() ? (int)($this->repo->rows("SELECT COUNT(*) n FROM billing_adjustment_requests q JOIN apartment_charges c ON c.id=q.charge_id WHERE c.billing_period_id=? AND q.status='submitted'",[$period])[0]['n']) : 0;
    }
    public function queue(): array
    {
        return $this->available() ? $this->repo->rows("SELECT q.*,a.code,p.period_code FROM billing_adjustment_requests q
            JOIN apartment_charges c ON c.id=q.charge_id JOIN apartments a ON a.id=c.apartment_id
            JOIN billing_periods p ON p.id=c.billing_period_id ORDER BY q.id DESC LIMIT 200") : [];
    }
    public function request(int $id): array
    {
        $this->ready(); $r = $this->repo->find($id);
        if (!$r) { throw ValidationException::withMessage('Đề nghị không tồn tại.'); }
        return $r;
    }
    public function debit(int $id): array
    {
        $this->ready();
        $d = $this->repo->rows('SELECT d.*,r.request_id,r.approved_by,r.snapshot FROM supplementary_debits d JOIN billing_reconciliations r ON r.id=d.reconciliation_id WHERE d.id=?',[$id])[0] ?? null;
        if (!$d) { throw ValidationException::withMessage('Phiếu ghi nợ không tồn tại.'); }
        return $d;
    }
    public function balances(int $chargeId): array
    {
        $charge = (new ApartmentChargeRepository())->find($chargeId);
        if (!$charge) { throw ValidationException::withMessage('Bản tính không tồn tại.'); }
        $original = (new PaymentStatusService())->forCharge($charge);
        $d = $this->available() ? ($this->repo->rows('SELECT * FROM supplementary_debits WHERE charge_id=?',[$chargeId])[0] ?? null) : null;
        $extra = $d ? MoneyCalculator::fromDb($d['amount']) : 0;
        return ['original'=>$charge['total_due'],'original_paid'=>Decimal::fromCents($original['recorded'] ?? 0),
            'original_remaining'=>Decimal::fromCents($original['remaining'] ?? MoneyCalculator::fromDb($charge['total_due'])),
            'supplementary'=>Decimal::fromCents($extra),'supplementary_paid'=>'0.00','supplementary_remaining'=>Decimal::fromCents($extra),
            'combined'=>Decimal::fromCents(MoneyCalculator::fromDb($charge['total_due'])+$extra),'debit'=>$d];
    }
}
