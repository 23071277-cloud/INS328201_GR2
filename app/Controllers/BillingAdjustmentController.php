<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\BaseController;
use App\Core\Flash;
use App\Core\ValidationException;
use App\Core\Request;
use App\Services\BillingAdjustmentService;

final class BillingAdjustmentController extends BaseController
{
    public function index(): void
    {
        $s = new BillingAdjustmentService();
        $this->view('billing_adjustments.index',['title'=>'Đối soát sau phát hành','ready'=>$s->available(),'rows'=>$s->queue()]);
    }
    public function charge(string $id): void
    {
        $id = $this->routeId($id); $s = new BillingAdjustmentService(); $snapshot = null; $error = null;
        try { if ($s->available()) { $snapshot = $s->capture($id); } }
        catch (ValidationException $e) { $error = $e->getMessage(); }
        $this->view('billing_adjustments.charge',['title'=>'Đối soát bản tính #'.$id,'id'=>$id,'ready'=>$s->available(),
            'snapshot'=>$snapshot,'error'=>$error,'history'=>$s->history($id),'balances'=>$s->balances($id),'form'=>Flash::pullFormState()]);
    }
    public function submit(string $id): void
    {
        $id = $this->routeId($id);
        $input = [];
        foreach (['explanation','evidence','idempotency_key','input_hash','effective_date','full_period'] as $key) { $input[$key] = Request::postString($key); }
        try { $request = (new BillingAdjustmentService())->submit($id,$input); redirect('/billing-adjustments/'.$request); }
        catch (ValidationException $e) { $this->backWithErrors('/charges/'.$id.'/adjustments',$e,$input); }
    }
    public function show(string $id): void
    {
        $s = new BillingAdjustmentService();
        try { $q = $s->request($this->routeId($id)); }
        catch (ValidationException $e) { Flash::error($e->getMessage()); redirect('/billing-adjustments'); }
        $this->view('billing_adjustments.show',['title'=>'Đề nghị đối soát #'.$q['id'],'q'=>$q,
            'reconciliationCurrent'=>$q['status'] === 'approved' && $s->reconciled((int)$q['charge_id']),
            'balances'=>$s->balances((int)$q['charge_id']),'history'=>$s->history((int)$q['charge_id'])]);
    }
    public function debit(string $id): void
    {
        $s = new BillingAdjustmentService();
        try { $d = $s->debit($this->routeId($id)); }
        catch (ValidationException $e) { Flash::error($e->getMessage()); redirect('/billing-adjustments'); }
        $this->view('billing_adjustments.debit',['title'=>'Phiếu ghi nợ bổ sung #'.$d['id'],'d'=>$d,
            'balances'=>$s->balances((int)$d['charge_id']),'history'=>$s->history((int)$d['charge_id'])]);
    }
    private function decision(string $id,string $action): void
    {
        $id = $this->routeId($id);
        try {
            $state = (new BillingAdjustmentService())->decide($id,$action,Request::postString('reason'));
            if ($state === 'stale_input') { Flash::error('Dữ liệu đã đổi: đề nghị không được duyệt. Lập đề nghị mới.'); }
            else { Flash::success('Đã ghi nhận quyết định. Không gửi email và không khóa kỳ.'); }
        } catch (ValidationException $e) { Flash::error($e->getMessage()); }
        redirect('/billing-adjustments/'.$id);
    }
    public function approve(string $id): void { $this->decision($id,'approve'); }
    public function reject(string $id): void { $this->decision($id,'reject'); }
    public function withdraw(string $id): void { $this->decision($id,'withdraw'); }
}
