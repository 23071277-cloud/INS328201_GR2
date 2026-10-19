<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BaseService;
use App\Core\ValidationException;
use App\Repositories\AlertRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\RecommendationRuleRepository;
use App\Repositories\RecommendationRevisionRepository;

/**
 * FN-08.02 Sinh & hiển thị khuyến nghị theo quy tắc - FR-10, BR-33, BR-29.
 * Cảnh báo -> rule khuyến nghị đang Bật -> RecommendationRuleEvaluator -> khớp -> kiểm tra trùng -> ghi recommendations
 * (snapshot nội dung) -> audit GENERATE_RECOMMENDATIONS (bằng chứng). CHỈ đọc dữ liệu nghiệp vụ và ghi khuyến nghị:
 * không đổi trạng thái cảnh báo, không yêu cầu kiểm tra, không sửa chỉ số, không tính phí, không khoá kỳ.
 * Chống trùng (cảnh báo, rule): khoá dòng cảnh báo FOR UPDATE + exists(); DB UNIQUE uq_rec_alert_rule là lớp chặn cuối (patch 002).
 * Ngữ cảnh xác minh được đọc riêng; phiên bản sau thay đổi chỉ nối thêm vào recommendation_revisions khi migration đã được duyệt.
 * Không có cảnh báo -> không sinh (alert_id NOT NULL): khuyến nghị CL = 0 độc lập BLOCKED - J-26 / B8.
 */
class RecommendationService extends BaseService
{
    public const NOTE = 'Khuyến nghị mang tính hỗ trợ; người phụ trách quyết định hành động xử lý.';

    private AlertRepository $alerts;
    private RecommendationRepository $recommendations;
    private RecommendationRuleRepository $rules;
    private RecommendationRevisionRepository $revisions;

    public function __construct()
    {
        $this->alerts = new AlertRepository();
        $this->recommendations = new RecommendationRepository();
        $this->rules = new RecommendationRuleRepository();
        $this->revisions = new RecommendationRevisionRepository();
    }

    /**
     * @return array{created:int[], duplicates:int, not_matched:array<int,string>}
     */
    public function generateForAlert(int $alertId): array
    {
        $this->assertGenerator();
        $evaluator = new RecommendationRuleEvaluator();

        return $this->transaction($this->recommendations, function () use ($alertId, $evaluator): array {
            $locked = $this->alerts->findForUpdate($alertId); // tuần tự hoá các lần sinh cho cùng cảnh báo (T4)
            if ($locked === null) {
                throw ValidationException::withMessage('Cảnh báo không tồn tại.');
            }
            if ($locked['status'] === 'da_dong') {
                throw ValidationException::withMessage('Cảnh báo đã đóng — không sinh khuyến nghị mới; khuyến nghị đã sinh vẫn xem được.');
            }
            $alert = $this->alerts->findDetailed($alertId);
            $context = (new RecommendationWorkflowContext())->forAlert($alert);
            if (!$context['actionable']) {
                return ['created'=>[], 'duplicates'=>0, 'not_matched'=>['_context'=>$context['reason']]];
            }
            $useRevision = $context['stage'] !== 'initial' || $context['evidence']['last_reopen_audit_id'] > 0;
            $storage=$this->revisions->readiness();
            if ($useRevision && !$storage['ready']) {
                throw ValidationException::withMessage($storage['message']);
            }

            $summary = ['created' => [], 'duplicates' => 0, 'not_matched' => []];
            $log = [];
            foreach ($this->rules->activeRules() as $rule) {
                $result = $evaluator->evaluate($rule, $alert);
                if (!$result['matched']) {
                    $summary['not_matched'][(int) $rule['id']] = $result['reason'];
                    continue;
                }
                $hash = RecommendationWorkflowContext::hash($context,$rule);
                if ($useRevision ? $this->revisions->existsContext($alertId,(int)$rule['id'],$hash) : $this->recommendations->exists($alertId, (int) $rule['id'])) {
                    $summary['duplicates']++;
                    continue;
                }
                $text = $useRevision ? $context['text'] : self::render((string) $rule['recommendation_text'], $rule, $alert);
                if ($useRevision) {
                    $legacy = $this->recommendations->forAlert($alertId);
                    $previous = array_values(array_filter($this->revisions->forAlert($alertId),fn($r)=>(int)$r['recommendation_rule_id']===(int)$rule['id']));
                    $legacy = array_values(array_filter($legacy,fn($r)=>(int)$r['recommendation_rule_id']===(int)$rule['id']));
                    $id = $this->revisions->insertRow(['alert_id'=>$alertId,'recommendation_rule_id'=>(int)$rule['id'],
                        'legacy_recommendation_id'=>$legacy[0]['id']??null,'previous_revision_id'=>$previous===[]?null:$previous[count($previous)-1]['id'],
                        'workflow_stage'=>$context['stage'],'category'=>$context['category'],'recommendation_text'=>$text,'context_hash'=>$hash,
                        'snapshot_json'=>json_encode(['context'=>$context,'rule'=>$rule,'match'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'created_by'=>$this->actorId()]);
                } else {
                    $id = $this->recommendations->insertRow([
                    'alert_id' => $alertId,
                    'recommendation_rule_id' => (int) $rule['id'],
                    'recommendation_text' => $text,
                ]);
                }
                $summary['created'][] = $id;
                $log[] = [
                    'recommendation_id' => $id,
                    'storage' => $useRevision ? 'revision' : 'legacy',
                    'context_hash' => $hash,
                    'workflow' => $context,
                    'rule' => array_intersect_key($rule, array_flip(['id', 'name', 'condition_type', 'condition_params', 'recommendation_text'])),
                    'reason' => $result['reason'],
                    'evidence' => $result['evidence'],
                    'text' => $text,
                ];
            }

            $this->audit('GENERATE_RECOMMENDATIONS', 'alert', $alertId, null, [
                'alert_status' => $locked['status'], // không đổi
                'created' => $log,
                'duplicates' => $summary['duplicates'],
                'not_matched' => $summary['not_matched'],
            ]);

            return $summary;
        });
    }

    /** Khuyến nghị đã sinh của cảnh báo + giải thích "vì sao" (bằng chứng lúc sinh, từ audit). */
    public function forAlert(int $alertId): array
    {
        $alert=$this->alerts->findDetailed($alertId);
        $context=$alert===null?null:(new RecommendationWorkflowContext())->forAlert($alert);
        $why = [];
        foreach ((new AuditLogRepository())->forEntity('alert', (string) $alertId) as $log) {
            if ($log['action'] !== 'GENERATE_RECOMMENDATIONS') {
                continue;
            }
            foreach (json_decode((string) $log['new_value'], true)['created'] ?? [] as $entry) {
                if (($entry['storage']??'legacy')!=='legacy') continue;
                $why[(int) $entry['recommendation_id']] = $entry + ['generated_by' => $log['user_name'] ?? null];
            }
        }

        $rows = array_map(static fn (array $r): array => $r + [
            'why' => $why[(int) $r['id']] ?? null,
            'rule_changed' => $r['rule_text_now'] !== ($why[(int) $r['id']]['rule']['recommendation_text'] ?? $r['rule_text_now']),
            'history_label' => 'Lịch sử khuyến nghị gốc',
        ], $this->recommendations->forAlert($alertId));
        foreach ($this->revisions->forAlert($alertId) as $r) {
            $snapshot=json_decode($r['snapshot_json'],true);
            $currentRule=$this->rules->find((int)$r['recommendation_rule_id']);
            $current=$context!==null && $context['actionable'] && $currentRule!==null && $currentRule['status']==='bat'
                && hash_equals($r['context_hash'],RecommendationWorkflowContext::hash($context,$currentRule));
            $rows[]=$r+['why'=>['rule'=>$snapshot['rule'],'reason'=>$snapshot['match']['reason'],'evidence'=>$snapshot['match']['evidence'],'workflow'=>$snapshot['context'],'generated_by'=>null],
                'rule_changed'=>$currentRule===null || $currentRule['recommendation_text']!==$snapshot['rule']['recommendation_text'],
                'history_label'=>$current?'Hiện hành':'Lịch sử — không còn áp dụng cho ngữ cảnh hiện tại'];
        }
        return $rows;
    }

    /** Read-only next-step proposal; never creates recommendations or audit events. */
    public function previewForAlert(int $alertId): array
    {
        $alert=$this->alerts->findDetailed($alertId);
        if ($alert===null) throw ValidationException::withMessage('Cảnh báo không tồn tại.');
        $context=(new RecommendationWorkflowContext())->forAlert($alert);
        $matched=[]; $evaluator=new RecommendationRuleEvaluator();
        if ($context['actionable']) foreach ($this->rules->activeRules() as $rule) {
            $result=$evaluator->evaluate($rule,$alert);
            if ($result['matched']) $matched[]=['rule_id'=>(int)$rule['id'],'reason'=>$result['reason'],'evidence'=>$result['evidence']];
        }
        $storage=$this->revisions->readiness();
        return ['context'=>$context,'matched_rules'=>$matched,'actionable'=>$context['actionable']&&$matched!==[],
            'needs_revision'=>$context['stage'] !== 'initial' || $context['evidence']['last_reopen_audit_id'] > 0,
            'revision_storage_ready'=>$storage['ready'],'revision_storage'=>$storage,'note'=>self::NOTE];
    }
    private function assertGenerator(): void
    {
        $user=(new \App\Repositories\UserRepository())->find((int)$this->actorId());
        if ($user===null || $user['status']!=='hoat_dong' || $user['role']!=='bql' || !can('recommendations.generate')) {
            throw ValidationException::withMessage('Chỉ Ban quản lý đang hoạt động được sinh khuyến nghị.');
        }
    }

    /** Thay placeholder đơn giản bằng giá trị thuần (T6) - không sinh câu mới; escape khi hiển thị. */
    public static function render(string $template, array $rule, array $alert): string
    {
        $params = RecommendationRuleService::params($rule);
        $text = strtr($template, [
            '{utility}' => $alert['utility_type'] === null ? 'điện + nước' : (config('alerts.utility_word')[$alert['utility_type']] ?? $alert['utility_type']),
            '{period}' => (string) $alert['period_code'],
            '{apartment}' => (string) $alert['apartment_code'],
            '{meter}' => (string) ($alert['meter_code'] ?? ''),
            '{n_periods}' => (string) ($params['n_periods'] ?? ''),
        ]);

        return mb_substr($text, 0, 500);
    }
}
