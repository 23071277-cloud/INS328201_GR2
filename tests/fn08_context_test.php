<?php
declare(strict_types=1);
// Synthetic isolated fixtures only. No live writes, demo import, DROP or cleanup SQL.
$name=$argv[1]??'';
if (!preg_match('/^energy_dss_fn08_context_test_[a-z0-9_]+$/',$name)) { fwrite(STDERR,"Provide a new energy_dss_fn08_context_test_* name\n"); exit(2); }
putenv('DB_DATABASE='.$name);
require dirname(__DIR__).'/bootstrap.php';
restore_error_handler(); restore_exception_handler();
use App\Core\Database;
use App\Core\ValidationException;
use App\Services\RecommendationService;
use App\Services\RecommendationWorkflowContext;
use App\Services\AlertService;
use App\Services\AlertWorkflowService;
use App\Services\AlertVerificationService;
use App\Services\AlertEvaluationService;
use App\Repositories\AlertRepository;
use App\Repositories\MeterReadingRepository;

$c=config('database');
$admin=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?'); $q->execute([$name]);
if ($q->fetchColumn()) { fwrite(STDERR,"Existing database: abort without changing it\n"); exit(2); }
$admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db=Database::connection(); if ($db->query('SELECT DATABASE()')->fetchColumn()!==$name) exit(2);
function schema(PDO $db,string $path,?array $lines=null): void {
 $delimiter=';'; $buffer=''; foreach($lines??file($path) as $line) {
  if (preg_match('/^DELIMITER\s+(\S+)/',trim($line),$m)) { $delimiter=$m[1]; continue; }
  if (trim($buffer)==='' && str_starts_with(trim($line),'--')) continue;
  $buffer.=$line."\n";
  if (str_ends_with(rtrim($buffer),$delimiter)) { $sql=substr(rtrim($buffer),0,-strlen($delimiter)); if (trim($sql)!=='') $db->exec($sql); $buffer=''; }
 } if(trim($buffer)!=='') throw new RuntimeException('Incomplete fixture SQL');
}
$db->exec('SET FOREIGN_KEY_CHECKS=0'); schema($db,__DIR__.'/support/fn08_context_schema.sql'); $db->exec('SET FOREIGN_KEY_CHECKS=1');
$checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException('FAIL '.$label); ++$checks; echo 'PASS '.$label.PHP_EOL; }
function denied(callable $call,string $label): void { try { $call(); } catch(ValidationException $e) { check(true,$label); return; } throw new RuntimeException('FAIL '.$label); }
function actor(int $id,string $role): void { $_SESSION=['user_id'=>$id,'user_role'=>$role,'username'=>'fixture','full_name'=>'Fixture','csrf_token'=>'test-only']; }
function insert(string $table,array $row): int { global $db; $q=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')'); $q->execute(array_values($row)); return (int)$db->lastInsertId(); }
function token(int $alert): string { return AlertService::versionToken((new AlertRepository())->find($alert)); }
foreach([1=>'bql',2=>'ky_thuat',3=>'ke_toan'] as $id=>$role) insert('users',['id'=>$id,'username'=>'test_'.$role,'password_hash'=>'synthetic-not-a-login','full_name'=>$role,'role'=>$role]);
$building=insert('buildings',['code'=>'FN08-TEST','name'=>'Synthetic']);
$apartment=insert('apartments',['building_id'=>$building,'code'=>'UNIT-X','area'=>'80.00']);
$period=insert('billing_periods',['period_code'=>'12/2026','start_date'=>'2026-12-01','end_date'=>'2026-12-31']);
$meter=insert('meters',['apartment_id'=>$apartment,'meter_code'=>'UNIT-X-DIEN','utility_type'=>'dien','usage_type'=>'sinh_hoat']);
$reading=insert('meter_readings',['meter_id'=>$meter,'billing_period_id'=>$period,'previous_reading'=>'0.00','current_reading'=>'310.00','consumption'=>'310.00','status'=>'da_xac_nhan','entered_by'=>2,'confirmed_by'=>1]);
$rule=insert('alert_rules',['name'=>'Synthetic threshold','measure_type'=>'tieu_thu','utility_type'=>'dien','compare_base'=>'baseline','operator'=>'gt','threshold_value'=>'30','scope_type'=>'toan_toa','priority_level'=>'trung_binh','status'=>'bat']);
insert('baselines',['scope_type'=>'meter','scope_id'=>$meter,'utility_type'=>'dien','method'=>'nhap_tay','manual_value'=>'181.67','effective_date'=>'2026-12-01','status'=>'hieu_luc','created_by'=>1]);
$recRule=insert('recommendation_rules',['name'=>'Synthetic enabled recommendation','condition_type'=>'vuot_nguong_lien_tiep','n_periods'=>1,'utility_type'=>'dien','recommendation_text'=>'Kiểm tra {meter} kỳ {period}; không kết luận lỗi khi chưa có bằng chứng.','status'=>'bat','created_by'=>1]);
$alert=insert('alerts',['alert_rule_id'=>$rule,'meter_id'=>$meter,'apartment_id'=>$apartment,'billing_period_id'=>$period,'utility_type'=>'dien','source'=>'rule','actual_value'=>'310','compare_value'=>'181.67','percent_change'=>'70.64','priority'=>'trung_binh']);
insert('audit_logs',['user_id'=>1,'action'=>'CREATE_ALERT_AUTO','entity_type'=>'alert','entity_id'=>(string)$alert,
 'new_value'=>json_encode(['rule_snapshot'=>['id'=>$rule,'measure_type'=>'tieu_thu','utility_type'=>'dien','compare_base'=>'baseline','operator'=>'gt','threshold_value'=>'30']])]);
// Original financial evidence protected throughout FN-08 operations.
$charge=insert('apartment_charges',['apartment_id'=>$apartment,'billing_period_id'=>$period,'status'=>'da_xac_nhan','total_due'=>'845399.60']);
insert('email_notifications',['apartment_id'=>$apartment,'billing_period_id'=>$period,'apartment_charge_id'=>$charge,'recipient_email'=>'fixture@example.invalid','subject'=>'Original','body_html'=>'<p>Original</p>','body_text'=>'Original','content_hash'=>hash('sha256','Original'),'transport'=>'array','status'=>'sent']);
$financialRequest=insert('billing_adjustment_requests',['charge_id'=>$charge,'initiated_by'=>3,'status'=>'approved','effective_date'=>'2026-12-01','explanation'=>'Synthetic approved supplement','evidence'=>'Synthetic evidence','amount'=>'250000','input_hash'=>str_repeat('a',64),'snapshot'=>'{}','idempotency_key'=>str_repeat('b',64),'decided_by'=>1]);
$financialReconciliation=insert('billing_reconciliations',['request_id'=>$financialRequest,'charge_id'=>$charge,'approved_by'=>1,'input_hash'=>str_repeat('a',64),'snapshot'=>'{}']);
insert('supplementary_debits',['reconciliation_id'=>$financialReconciliation,'charge_id'=>$charge,'amount'=>'250000']);
insert('payment_transactions',['transaction_id'=>'synthetic-original-payment','amount'=>'845399.60','received_at'=>'2026-12-01 00:00:00','apartment_charge_id'=>$charge,'match_status'=>'matched','raw_payload'=>'{}']);
function businessHash(): string { global $db; $rows=[]; foreach(['meter_readings','baselines','alert_rules','pricing_plans','pricing_tiers','apartment_charges','charge_tier_details','billing_adjustment_requests','billing_reconciliations','supplementary_debits','payment_transactions','email_notifications','billing_periods'] as $table) $rows[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(); return hash('sha256',json_encode($rows)); }
$business=businessHash();
$dryInitial=(new AlertEvaluationService())->evaluatePeriod($period,$rule,true);
check(count($dryInitial['would_create'])===1 && $dryInitial['would_create'][0]['percent']==='70.64','existing FN-07 threshold comparison still matches 70.64 percent');
$s=new RecommendationService(); actor(1,'bql');
$revisionRepo=new App\Repositories\RecommendationRevisionRepository();
check($revisionRepo->readiness()['status']==='missing','missing revision table has explicit read-only readiness');
$preview=$s->previewForAlert($alert); check($preview['context']['stage']==='initial' && str_contains($preview['context']['text'],'Chưa kết luận lỗi'),'new alert: safe initial verification in Vietnamese');
$first=$s->generateForAlert($alert); check(count($first['created'])===1,'initial recommendation persisted through existing model');
$original=$db->query('SELECT * FROM recommendations')->fetchAll(); $originalHash=hash('sha256',json_encode($original));
check($s->generateForAlert($alert)['created']===[],'initial generation idempotent');
(new AlertWorkflowService())->review($alert,'trung_binh','Read-only findings',token($alert));
check($s->previewForAlert($alert)['context']['stage']==='initial','reviewed alert without recheck still verifies');
$case=(new AlertVerificationService())->request($alert,'Check original reading','2',token($alert));
check($s->previewForAlert($alert)['context']['stage']==='pending_recheck','assigned recheck waits for evidence');
denied(fn()=>$s->generateForAlert($alert),'without revision migration, changed state cannot overwrite old recommendation');
check(hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()))===$originalHash,'legacy recommendation unchanged before migration');
// Proposed migration applied ONLY in this isolated synthetic test database.
$migrationPath=dirname(__DIR__).'/database/migrations/20261014_recommendation_revisions.sql';
[$tableSql,$triggerSql]=explode('DELIMITER $$',file_get_contents($migrationPath),2);
$db->exec($tableSql);
check($revisionRepo->readiness()['status']==='incomplete','table without protection triggers is incomplete');
denied(fn()=>$s->generateForAlert($alert),'partial schema cannot save revisions');
check($s->previewForAlert($alert)['context']['stage']==='pending_recheck' && !$s->previewForAlert($alert)['revision_storage_ready'],'partial schema preserves contextual preview without saving');
schema($db,$migrationPath,explode("\n",'DELIMITER $$'.$triggerSql));
check($revisionRepo->readiness()['ready'],'complete reviewed schema enables append-only persistence');
$guards=$db->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='recommendation_revisions'")->fetchAll();
$wrongGuard=$guards; $wrongGuard[0]['EVENT_OBJECT_TABLE']='recommendations';
check(App\Repositories\RecommendationRevisionRepository::guardErrors($wrongGuard)!==[],'same-named trigger on another table cannot enable persistence');
$wrongGuard=$guards; $wrongGuard[0]['ACTION_STATEMENT']='BEGIN SET @fixture=1; END';
check(App\Repositories\RecommendationRevisionRepository::guardErrors($wrongGuard)!==[],'same-named nonprotective trigger cannot enable persistence');
foreach ([
 "BEGIN\r\n    SIGNAL SQLSTATE '45000'\r\n    SET MESSAGE_TEXT = '%s';\r\nEND",
 "begin\n signal sqlstate '45000' set message_text = '%s';\nend;",
 " \tBeGiN\tSIGNAL\nSQLSTATE VALUE '45000' SET `MESSAGE_TEXT`\t=\n'%s' ; END \n",
] as $format) {
 $formatted=$guards;
 foreach ($formatted as &$guard) {
  $message=$guard['EVENT_MANIPULATION']==='UPDATE'?'Immutable recommendation revision':'Preserve recommendation revision history';
  $guard['ACTION_STATEMENT']=sprintf($format,$message);
 } unset($guard);
 check(App\Repositories\RecommendationRevisionRepository::guardErrors($formatted)===[],'MariaDB/phpMyAdmin harmless trigger formatting accepted');
}
foreach (['ACTION_TIMING'=>'AFTER','EVENT_MANIPULATION'=>'INSERT'] as $field=>$value) {
 $bad=$guards; $bad[0][$field]=$value;
 check(App\Repositories\RecommendationRevisionRepository::guardErrors($bad)!==[],'wrong trigger '.$field.' rejected');
}
check(App\Repositories\RecommendationRevisionRepository::guardErrors(array_slice($guards,1))!==[],'missing protection trigger rejected');
foreach ([
 "BEGIN SIGNAL SQLSTATE '01000' SET MESSAGE_TEXT='Immutable recommendation revision'; END",
 "BEGIN IF FALSE THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable recommendation revision'; END IF; END",
 "BEGIN DECLARE CONTINUE HANDLER FOR SQLSTATE '45000' BEGIN END; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable recommendation revision'; END",
 "BEGIN SET @message='SIGNAL SQLSTATE 45000'; END",
 "BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable recommendation revision'; END; SELECT 1",
] as $body) {
 $bad=$guards; $bad[0]['ACTION_STATEMENT']=$body;
 check(App\Repositories\RecommendationRevisionRepository::guardErrors($bad)!==[],'ineffective or noncanonical SIGNAL guard rejected');
}
$generated=$s->generateForAlert($alert); check(count($generated['created'])===1,'pending recheck creates appropriate revision');
foreach (['UPDATE recommendation_revisions SET recommendation_text=recommendation_text WHERE id=1'=>'revision UPDATE rejected by protection trigger','DELETE FROM recommendation_revisions WHERE id=1'=>'revision DELETE rejected by protection trigger'] as $sql=>$label) {
 $blocked=false;
 try { $db->exec($sql); } catch (PDOException $e) { $blocked=$e->getCode()==='45000'; }
 check($blocked,$label);
}
check($s->generateForAlert($alert)['created']===[],'assigned recheck does not request repeated inspection');
$before=businessHash(); $s->generateForAlert($alert); check(businessHash()===$before,'generation changes no readings/baselines/billing/payments/emails/locks');
actor(2,'ky_thuat'); denied(fn()=>$s->generateForAlert($alert),'technician cannot generate recommendations');
(new AlertVerificationService())->submitResult($alert,'canh_bao_dung','','Synthetic evidence');
actor(1,'bql'); check($s->previewForAlert($alert)['context']['stage']==='management_pending','technician result does not finalize Management decision');
check(count($s->generateForAlert($alert)['created'])===1,'Management-pending revision persisted');
(new AlertVerificationService())->confirm($alert,'confirm','Original data confirmed',token($alert));
$preview=$s->previewForAlert($alert);
check($preview['context']['stage']==='verified_confirm','audited Management confirmation yields post-verification');
check(str_contains($preview['context']['text'],'thông báo cư dân') && str_contains($preview['context']['text'],'Không yêu cầu kiểm tra lại'),'confirmed anomaly recommends resident usage/saving/follow-up');
$before=businessHash(); check(count($s->generateForAlert($alert)['created'])===1,'post-verification revision generated');
check($s->generateForAlert($alert)['created']===[] && businessHash()===$before,'repeat confirmation advice is idempotent and side-effect free');
check(hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()))===$originalHash,'all original recommendation snapshots intact');
$rows=$s->forAlert($alert); check(count(array_filter($rows,fn($r)=>$r['history_label']==='Hiện hành'))===1,'one current revision, prior context is historical');
foreach(['rec_revision_no_update','rec_revision_no_delete'] as $trigger) check((int)$db->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='$trigger'")->fetchColumn()===1,'immutable guard installed: '.$trigger);
(new AlertWorkflowService())->close($alert,'Resolved by Management',token($alert));
check(!$s->previewForAlert($alert)['actionable'],'closed alert has no new actionable recommendation');
denied(fn()=>$s->generateForAlert($alert),'closed alert generation rejected');
check(count($s->forAlert($alert))===count($rows),'closed alert preserves all history');
(new AlertWorkflowService())->reopen($alert,'New evidence warrants checking',token($alert));
check($s->previewForAlert($alert)['context']['stage']==='initial','reopened alert does not inherit old confirmation');
check(count($s->generateForAlert($alert)['created'])===1,'explicit reopening permits fresh verification revision');
(new AlertVerificationService())->request($alert,'New technical reason','2',token($alert));
actor(2,'ky_thuat'); (new AlertVerificationService())->submitResult($alert,'do_ghi_sai','','Corrected reading evidence');
actor(1,'bql'); $readingRow=(new MeterReadingRepository())->findByMeterPeriod($meter,$period);
(new AlertVerificationService())->confirm($alert,'adjust','Management corrects reading',token($alert),['current_reading'=>'250','reading_token'=>App\Services\MeterReadingService::versionToken($readingRow)]);
$preview=$s->previewForAlert($alert); check($preview['context']['stage']==='reading_adjusted','audited adjustment selects reevaluation');
check(str_contains($preview['context']['text'],'không mặc định bất thường cũ còn đúng') && str_contains($preview['context']['text'],'Giữ nguyên phiếu đã phát hành'),'corrected-data advice preserves billing safeguards');
$before=businessHash(); $s->generateForAlert($alert); check(businessHash()===$before,'adjustment advice does not automatically confirm/readjust/recalculate');
actor(3,'ke_toan'); denied(fn()=>$s->generateForAlert($alert),'Accounting cannot generate recommendations');
actor(1,'bql');
$detail=(new AlertRepository())->findDetailed($alert);
$unknown=RecommendationWorkflowContext::resolve($detail,[],[],null);
check(!$unknown['actionable'] && $unknown['stage']==='unknown','confirmed status alone does not invent completed inspection');
$caseRow=$db->query('SELECT * FROM inspection_cases ORDER BY id DESC LIMIT 1')->fetch();
$wrongEvent=[['id'=>999,'action'=>'CONFIRM_ALERT_DATA','new_value'=>json_encode(['case_id'=>9999,'status'=>'da_xac_nhan','decision'=>'confirm','adjusted'=>null])]];
check(!RecommendationWorkflowContext::resolve($detail,[$caseRow],$wrongEvent,(new MeterReadingRepository())->find($reading))['actionable'],'unrelated case decision cannot establish confirmation');
// Existing FN-07 condition evaluation remains unchanged after FN-08 changes.
$alertsBefore=(int)$db->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
$auditBefore=(int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$dry=(new AlertEvaluationService())->evaluatePeriod($period,$rule,true);
check($dry['would_create']===[] && isset($dry['skipped']['Chưa đủ dữ liệu đã xác nhận (incomplete)']),'FN-07 skips corrected draft reading');
check((int)$db->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===$alertsBefore && (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$auditBefore,'FN-07 dry run remains read only');
check(config('permissions.recommendations.generate')===['bql'] && config('permissions.recommendations.view')===['bql'],'FN-08 permission matrix unchanged');
check(RecommendationService::render('{utility} {period} {apartment} {meter}',['condition_params'=>null],$detail)==='điện 12/2026 UNIT-X UNIT-X-DIEN','existing FN-08 placeholder rendering preserved');
check(hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()))===$originalHash,'legacy recommendation history immutable throughout lifecycle');
// Policy precedence remains specific to post-adjustment state when generic FN-08 rule matches.
check($s->previewForAlert($alert)['context']['category']==='Đánh giá lại sau điều chỉnh','specific lifecycle policy takes precedence over generic threshold instruction');
(new AlertWorkflowService())->changePriority($alert,'cao','New follow-up priority',token($alert));
$beforeCount=(int)$db->query('SELECT COUNT(*) FROM recommendation_revisions')->fetchColumn();
$workers=[];
for($i=0;$i<2;$i++) { $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/support/fn08_context_worker.php',$name,(string)$alert],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $workers[]=[$process,$pipes]; }
$created=0;
foreach($workers as [$process,$pipes]) { $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); check(proc_close($process)===0,'concurrent FN-08 generation succeeds: '.$error); $created+=count(json_decode($output,true)['created']); }
check($created===1 && (int)$db->query('SELECT COUNT(*) FROM recommendation_revisions')->fetchColumn()===$beforeCount+1,'concurrent same-context generation posts one revision');
$beforeLegacy=hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()));
$beforePreview=(int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$s->previewForAlert($alert); $s->forAlert($alert);
check((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===$beforePreview && hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()))===$beforeLegacy,'preview/history are read only');
$db->exec("UPDATE recommendation_rules SET status='tat' WHERE id=".$recRule);
check(!$s->previewForAlert($alert)['actionable'],'disabled recommendation rule cannot produce actionable advice');
check(count(array_filter($s->forAlert($alert),fn($r)=>$r['history_label']==='Hiện hành'))===0,'disabled rule history remains but is not current');
$db->exec("UPDATE recommendation_rules SET status='bat',recommendation_text='New configured instruction' WHERE id=".$recRule);
$oldRows=$db->query('SELECT * FROM recommendation_revisions ORDER BY id')->fetchAll();
check(count($s->generateForAlert($alert)['created'])===1,'changed effective rule configuration may produce new revision');
$newRows=$db->query('SELECT * FROM recommendation_revisions ORDER BY id')->fetchAll();
check(array_slice($newRows,0,count($oldRows))===$oldRows,'rule changes never overwrite historical revision evidence');
$caseContradiction=$caseRow; $caseContradiction['status']='da_co_ket_qua'; $caseContradiction['result_conclusion']=null;
$waiting=$detail; $waiting['status']='can_kiem_tra_lai';
check(!RecommendationWorkflowContext::resolve($waiting,[$caseContradiction],[],null)['actionable'],'missing technician findings produce safe unknown result');
$olderOpen=$caseRow; $olderOpen['id']=1; $olderOpen['status']='da_giao'; $closedLatest=$caseRow; $closedLatest['id']=2; $closedLatest['status']='da_dong';
check(!RecommendationWorkflowContext::resolve($detail,[$olderOpen,$closedLatest],[],null)['actionable'],'contradictory multiple-case history is not silently inferred');
check(hash('sha256',json_encode($db->query('SELECT * FROM recommendations')->fetchAll()))===$originalHash,'all new revisions leave original recommendations unchanged');
$zeroApartment=insert('apartments',['building_id'=>$building,'code'=>'ZERO-REGRESSION','area'=>'80']);
$zeroMeter=insert('meters',['apartment_id'=>$zeroApartment,'meter_code'=>'ZERO-REGRESSION-DIEN','utility_type'=>'dien','usage_type'=>'sinh_hoat']);
insert('meter_readings',['meter_id'=>$zeroMeter,'billing_period_id'=>$period,'previous_reading'=>0,'current_reading'=>0,'consumption'=>0,'status'=>'da_xac_nhan','entered_by'=>2]);
$zeroAlert=$detail; $zeroAlert['apartment_id']=$zeroApartment; $zeroAlert['meter_id']=$zeroMeter;
$evaluator=new App\Services\RecommendationRuleEvaluator();
$zeroRule=['condition_type'=>'cl_zero_n_ky','condition_params'=>json_encode(['n_periods'=>1,'utility_type'=>'dien'])];
check($evaluator->evaluate($zeroRule,$zeroAlert)['matched'],'existing zero-consumption condition preserved');
$zeroRule['condition_params']=json_encode(['n_periods'=>2,'utility_type'=>'dien']);
check(!$evaluator->evaluate($zeroRule,$zeroAlert)['matched'],'existing missing-history behavior preserved');
$zeroRule['condition_params']=json_encode(['n_periods'=>1,'utility_type'=>'nuoc']);
check(!$evaluator->evaluate($zeroRule,$zeroAlert)['matched'],'existing utility filter preserved');
$targetApartment=insert('apartments',['building_id'=>$building,'code'=>'TARGET-REGRESSION','area'=>'80']);
$targetMeter=insert('meters',['apartment_id'=>$targetApartment,'meter_code'=>'TARGET-REGRESSION-DIEN','utility_type'=>'dien','usage_type'=>'sinh_hoat']);
insert('meter_readings',['meter_id'=>$targetMeter,'billing_period_id'=>$period,'previous_reading'=>0,'current_reading'=>200,'consumption'=>200,'status'=>'da_xac_nhan','entered_by'=>2]);
insert('consumption_targets',['scope_type'=>'apartment','scope_id'=>$targetApartment,'utility_type'=>'dien','billing_period_id'=>$period,'target_value'=>100,'created_by'=>1]);
$targetAlert=$detail; $targetAlert['apartment_id']=$targetApartment; $targetAlert['meter_id']=$targetMeter;
check($evaluator->evaluate(['condition_type'=>'khong_dat_muc_tieu','condition_params'=>null],$targetAlert)['matched'],'existing missed-target recommendation condition preserved');
echo "$checks/$checks PASS; isolated database retained: $name\n";
