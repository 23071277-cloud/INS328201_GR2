<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Decimal;
use App\Repositories\InspectionCaseRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\MeterReadingRepository;

final class RecommendationWorkflowContext
{
    public function forAlert(array $alert): array
    {
        $cases=(new InspectionCaseRepository())->casesOf((int)$alert['id']);
        $events=(new AuditLogRepository())->forEntity('alert',(string)$alert['id']);
        $reading=$alert['meter_id']!==null ? (new MeterReadingRepository())->findByMeterPeriod((int)$alert['meter_id'],(int)$alert['billing_period_id']) : null;
        return self::resolve($alert,$cases,$events,$reading);
    }
    /** Pure state resolver: latest case and its audited Management decision, never inferred findings. */
    public static function resolve(array $alert,array $cases,array $events,?array $reading): array
    {
        usort($cases,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        usort($events,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $case=$cases===[]?null:$cases[count($cases)-1];
        $decision=null; $decisionId=null; $lastReopen=0; $threshold=null;
        foreach ($events as $event) {
            if ($event['action']==='CREATE_ALERT_AUTO') { $threshold=json_decode((string)$event['new_value'],true)['rule_snapshot']??null; }
            if ($event['action']==='REOPEN_ALERT') $lastReopen=(int)$event['id'];
            if ($event['action']==='CONFIRM_ALERT_DATA' && $case!==null) {
                $value=json_decode((string)$event['new_value'],true);
                if (($value['case_id']??null)==$case['id'] && ($value['status']??null)==='da_xac_nhan') {
                    $decision=$value; $decisionId=(int)$event['id'];
                }
            }
        }
        if ($decisionId!==null && $decisionId<$lastReopen) { $decision=null; $decisionId=null; }
        $stage='unknown'; $reason='Thiếu hoặc mâu thuẫn bằng chứng xác minh; không suy ra kết luận.';
        $status=$alert['status'];
        $openCases=array_filter($cases,fn($row)=>$row['status']!=='da_dong');
        if ($status==='da_dong') { $stage='closed'; $reason='Cảnh báo đã đóng; chỉ lưu lịch sử, không đề xuất hành động mới.'; }
        elseif (count($openCases)>1 || ($case!==null && $case['status']==='da_dong' && $openCases!==[])) {
            $reason='Các case kiểm tra có trạng thái mâu thuẫn; cần Ban quản lý rà soát lịch sử.';
        }
        elseif (!in_array($alert['priority'],['cao','trung_binh','thap'],true) || !in_array($alert['utility_type'],['dien','nuoc'],true)) {
            $reason='Loại tiện ích hoặc mức ưu tiên không thuộc phạm vi chính sách xác minh này.';
        }
        elseif ($case!==null && $case['status']==='da_giao' && $status==='can_kiem_tra_lai') {
            $stage='pending_recheck'; $reason='Yêu cầu đã giao; chưa có kết quả kỹ thuật.';
        }
        elseif ($case!==null && $case['status']==='da_co_ket_qua' && $status==='can_kiem_tra_lai' && !empty($case['result_conclusion'])) {
            $stage='management_pending'; $reason='Kỹ thuật đã nộp kết quả; chưa có quyết định Ban quản lý.';
        }
        elseif ($case!==null && $case['status']==='da_dong' && $status==='da_xac_nhan' && $decision!==null && !empty($case['result_conclusion'])) {
            if (($decision['decision']??'')==='adjust' && !empty($decision['adjusted']['reading_id'])
                && $reading!==null && (int)$reading['id']===(int)$decision['adjusted']['reading_id']
                && Decimal::toCents((string)$reading['current_reading'])===Decimal::toCents((string)($decision['adjusted']['new_current']??''))) {
                $stage='reading_adjusted'; $reason='Quyết định điều chỉnh đã được ghi audit cho đúng case và chỉ số hiện tại.';
            } elseif (($decision['decision']??'')==='confirm' && ($decision['adjusted']??null)===null
                && $reading!==null && $reading['status']==='da_xac_nhan' && (int)($reading['is_meter_changed']??0)===0
                && $alert['actual_value']!==null && preg_match('/^\d+(?:\.\d{1,4})?$/',(string)$alert['actual_value'])
                && bccomp((string)$reading['consumption'],(string)$alert['actual_value'],4)===0) {
                $thresholdMeasure=$threshold===null?null:(($threshold['compare_base']??'')==='gia_tri_tuyet_doi'?$alert['actual_value']:$alert['percent_change']);
                $thresholdMatches=$threshold!==null && $thresholdMeasure!==null && isset($threshold['operator'],$threshold['threshold_value'])
                    && RuleConditionEvaluator::matches($threshold['operator'],(string)$thresholdMeasure,(string)$threshold['threshold_value']);
                $genuine=$thresholdMatches && $case['result_conclusion']==='canh_bao_dung' && ($alert['alert_type']??'threshold')==='threshold'
                    && $alert['percent_change']!==null && $alert['compare_value']!==null
                    && preg_match('/^\d+(?:\.\d{1,4})?$/',(string)$alert['compare_value'])
                    && bccomp((string)$alert['actual_value'],(string)$alert['compare_value'],4)>0;
                $stage=$genuine?'verified_confirm':'verified_other';
                $reason=$genuine?'Case đã đóng, Kỹ thuật xác nhận cảnh báo đúng và Ban quản lý giữ nguyên dữ liệu.':'Ban quản lý giữ nguyên dữ liệu; chưa đủ bằng chứng kết luận bất thường thực tế.';
            }
        }
        elseif (in_array($status,['moi','da_review'],true) && ($case===null || ($case['status']==='da_dong' && $lastReopen>0))) {
            $stage='initial'; $reason=$lastReopen>0?'Cảnh báo được mở lại; cần bằng chứng mới, không tái dùng kết luận cũ.':'Cảnh báo chưa được xác minh; cần đối chiếu dữ liệu trước khi kết luận.';
        }
        $policy=config('recommendation_workflow.'.$stage,[]);
        $evidence=['alert'=>array_intersect_key($alert,array_flip(['id','alert_type','status','priority','utility_type','meter_id','apartment_id','billing_period_id','alert_rule_id','actual_value','compare_value','percent_change','conclusion'])),
            'case'=>$case,'decision'=>$decision,'decision_audit_id'=>$decisionId,'last_reopen_audit_id'=>$lastReopen,'threshold_snapshot'=>$threshold,
            'reading'=>$reading===null?null:array_intersect_key($reading,array_flip(['id','status','previous_reading','current_reading','consumption','is_meter_changed','updated_at']))];
        return ['stage'=>$stage,'actionable'=>$policy!==[],'category'=>$policy['category']??'Chưa đủ ngữ cảnh',
            'text'=>isset($policy['text'])?RecommendationService::render($policy['text'],['condition_params'=>null],$alert):'',
            'reason'=>$reason,'evidence'=>$evidence,'policy_version'=>config('recommendation_workflow.version')];
    }
    public static function hash(array $context,array $rule): string
    {
        return hash('sha256',json_encode(['context'=>$context,'rule'=>array_intersect_key($rule,array_flip(['id','condition_type','condition_params','recommendation_text','status']))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}
