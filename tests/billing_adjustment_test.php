<?php
declare(strict_types=1);
// Creates a NEW isolated schema, synthetic fixtures only. No drops or demo imports.
// Retains the test database for inspection. Rejects existing/non-test database names.
$name = $argv[1] ?? '';
if (!preg_match('/^energy_dss_adjustment_test_[a-z0-9_]+$/',$name)) { fwrite(STDERR,"Provide a unique energy_dss_adjustment_test_* database name\n"); exit(2); }
putenv('DB_DATABASE='.$name);
require dirname(__DIR__).'/bootstrap.php';
restore_error_handler(); restore_exception_handler();
use App\Core\Database;
use App\Core\ValidationException;
use App\Services\BillingAdjustmentService;
use App\Services\BillingCalculationService;
use App\Services\BillingConfirmationService;
use App\Services\PeriodClosingService;
use App\Services\IssuedChargeGuard;
use App\Repositories\ApartmentChargeRepository;
use App\Core\Config;

$config=config('database');
$admin=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?'); $q->execute([$name]);
if ($q->fetchColumn()) { fwrite(STDERR,"Test database already exists; refusing to touch it\n"); exit(2); }
$admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$db=Database::connection();
if ($db->query('SELECT DATABASE()')->fetchColumn()!==$name) exit(2);

function runSql(PDO $db,string $path): void
{
    $delimiter=';'; $buffer='';
    foreach (file($path) as $line) {
        if (preg_match('/^DELIMITER\s+(\S+)/',trim($line),$m)) { $delimiter=$m[1]; continue; }
        if (str_starts_with(trim($line),'--')) continue;
        $buffer.=$line;
        if (str_ends_with(rtrim($buffer),$delimiter)) {
            $sql=substr(rtrim($buffer),0,-strlen($delimiter)); if (trim($sql)!=='') $db->exec($sql); $buffer='';
        }
    }
    if (trim($buffer)!=='') throw new RuntimeException('Unterminated SQL');
}
$db->exec('SET FOREIGN_KEY_CHECKS=0');
runSql($db,__DIR__.'/support/reconciliation_schema.sql');
$db->exec('SET FOREIGN_KEY_CHECKS=1');
runSql($db,dirname(__DIR__).'/database/migrations/20261013_billing_reconciliation.sql');

$checks=0;
function check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); ++$checks; echo 'PASS '.$label.PHP_EOL; }
function denied(callable $fn,string $label): void { try { $fn(); } catch (ValidationException $e) { check(true,$label); return; } throw new RuntimeException('FAIL: '.$label); }
function actor(int $id,string $role): void { $_SESSION=['user_id'=>$id,'user_role'=>$role,'username'=>$role,'full_name'=>$role]; }
function insert(string $table,array $row): int {
    global $db; $q=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
    $q->execute(array_values($row)); return (int)$db->lastInsertId();
}
foreach ([1=>'bql',2=>'ke_toan',3=>'ky_thuat'] as $id=>$role) { insert('users',['id'=>$id,'username'=>'test_'.$role,'password_hash'=>'test-only-not-a-login','full_name'=>'Test '.$role,'role'=>$role]); }
$building=insert('buildings',['code'=>'SYNTHETIC','name'=>'Isolated fixture']);
$period=insert('billing_periods',['period_code'=>'11/2026','start_date'=>'2026-11-01','end_date'=>'2026-11-30','status'=>'mo_lai']);
foreach (['dich_vu_co_o'=>3000,'bao_hiem_chay_no'=>1953,'gui_xe_dap_dien'=>100000,'gui_xe_may'=>50000] as $type=>$rate) {
    insert('pricing_plans',['price_type'=>'fixed_fee','fee_type'=>$type,'flat_unit_price'=>$rate,'effective_date'=>'2024-01-01','status'=>'hieu_luc']);
}
Config::set('billing.fire_insurance.enabled',true);
$electric=insert('pricing_plans',['price_type'=>'utility','utility_type'=>'dien','usage_type'=>'sinh_hoat','vat_percent'=>'8.00','effective_date'=>'2024-01-01','status'=>'hieu_luc']);
foreach ([[1,0,50,1806],[2,50,100,1866],[3,100,200,2167],[4,200,null,2500]] as [$order,$from,$to,$price]) {
    insert('pricing_tiers',['pricing_plan_id'=>$electric,'tier_order'=>$order,'tier_from'=>$from,'tier_to'=>$to,'unit_price'=>$price]);
}
$water=insert('pricing_plans',['price_type'=>'utility','utility_type'=>'nuoc','usage_type'=>'sinh_hoat','effective_date'=>'2024-01-01','status'=>'hieu_luc']);
insert('pricing_tiers',['pricing_plan_id'=>$water,'tier_order'=>1,'tier_from'=>0,'tier_to'=>null,'unit_price'=>11045]);
function fixture(string $code,bool $parking = true): int {
    global $building,$period;
    $apartment=insert('apartments',['building_id'=>$building,'code'=>$code,'area'=>'80.00']);
    foreach (['dien'=>[200,360,160],'nuoc'=>[20,30,10]] as $utility=>[$previous,$current,$consumption]) {
        $meter=insert('meters',['apartment_id'=>$apartment,'meter_code'=>$code.'-'.$utility,'utility_type'=>$utility,'usage_type'=>'sinh_hoat']);
        insert('meter_readings',['meter_id'=>$meter,'billing_period_id'=>$period,'previous_reading'=>$previous,'current_reading'=>$current,
            'consumption'=>$consumption,'entered_by'=>3,'confirmed_by'=>1,'status'=>'da_xac_nhan']);
    }
    $calc=new BillingCalculationService(); actor(2,'ke_toan');
    $charge=$calc->calculateApartment($period,$apartment)['charge_id'];
    $repo=new ApartmentChargeRepository(); $repo->updateById($charge,['status'=>'da_xac_nhan','confirmed_by'=>2,'confirmed_at'=>'2026-10-12 19:14:11']);
    insert('email_notifications',['notification_type'=>'bill_notice','apartment_id'=>$apartment,'billing_period_id'=>$period,
        'apartment_charge_id'=>$charge,'recipient_email'=>'fixture@example.invalid','subject'=>'Original fixture',
        'body_html'=>'<p>Immutable original</p>','body_text'=>'Immutable original','content_hash'=>hash('sha256','fixture'),
        'transport'=>'array','status'=>'sent','sent_at'=>'2026-10-12 19:51:05']);
    if ($parking) { insert('parking_vehicles',['apartment_id'=>$apartment,'vehicle_type'=>'xe_dap_dien','quantity'=>2]); insert('parking_vehicles',['apartment_id'=>$apartment,'vehicle_type'=>'xe_may','quantity'=>1]); }
    return $charge;
}
function proposal(BillingAdjustmentService $s,int $charge): array {
    $snapshot=$s->capture($charge);
    return ['effective_date'=>'2026-11-01','full_period'=>'1','explanation'=>'Full November parking liability',
        'evidence'=>'Synthetic decision, effective 2026-11-01','input_hash'=>$s::fingerprint($snapshot),'idempotency_key'=>bin2hex(random_bytes(32))];
}
function originalHash(int $charge): string {
    global $db;
    $result=[];
    foreach (['SELECT * FROM apartment_charges WHERE id=?','SELECT * FROM charge_tier_details WHERE apartment_charge_id=? ORDER BY id',
        'SELECT * FROM email_notifications WHERE apartment_charge_id=? ORDER BY id',
        "SELECT * FROM audit_logs WHERE entity_type='apartment_charge' AND entity_id=? ORDER BY id",
        'SELECT * FROM payment_transactions WHERE apartment_charge_id=? ORDER BY id'] as $sql) { $q=$db->prepare($sql); $q->execute([$charge]); $result[]=$q->fetchAll(); }
    return hash('sha256',json_encode($result));
}
$charge=fixture('ISSUED'); $s=new BillingAdjustmentService();
insert('payment_transactions',['transaction_id'=>'synthetic-original-payment','amount'=>'845399.60','received_at'=>'2026-10-12 20:00:00',
    'apartment_charge_id'=>$charge,'match_status'=>'matched','raw_payload'=>'{}']);
$before=originalHash($charge);
check(IssuedChargeGuard::protected($charge),'legacy sent notice protects confirmed charge');
denied(fn()=>(new BillingCalculationService())->calculateApartment($period,1),'recalculation blocked');
denied(fn()=>(new ApartmentChargeRepository())->updateById($charge,['total_due'=>'1.00']),'repository mutation blocked');
denied(fn()=>(new ApartmentChargeRepository())->delete($charge),'charge deletion blocked');
denied(fn()=>(new ApartmentChargeRepository())->deleteTierDetails($charge),'tier deletion blocked');
denied(fn()=>(new BillingConfirmationService())->updateOldDebt($charge,'1.00','test',BillingCalculationService::versionToken((new ApartmentChargeRepository())->find($charge))),'old debt mutation blocked');
check($s->capture($charge)['amount']==='250000.00','parking delta uses approved applicable rates');
actor(3,'ky_thuat'); denied(fn()=>$s->submit($charge,proposal($s,$charge)),'technical staff cannot submit');
actor(1,'bql'); denied(fn()=>$s->submit($charge,proposal($s,$charge)),'management cannot initiate');
actor(2,'ke_toan'); $input=proposal($s,$charge);
$badHash=$input; $badHash['input_hash']=str_repeat('0',64); denied(fn()=>$s->submit($charge,$badHash),'stale preview fingerprint rejected');
$invalid=$input; $invalid['effective_date']='2026-12-01'; denied(fn()=>$s->submit($charge,$invalid),'effective period must be explicitly full November');
$request=$s->submit($charge,$input);
check($s->submit($charge,$input)===$request,'submission retry idempotent');
denied(fn()=>$s->submit($charge,proposal($s,$charge)),'duplicate pending request blocked');
denied(fn()=>$s->decide($request,'approve','test'),'accounting cannot approve');
check(!$s->reconciled($charge),'unapproved request does not clear stale');
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'đề nghị đối soát'),'submitted proposal blocks closing');
check((new PeriodClosingService())->lockBlockers($period)!==[],'stale issued charge blocks closing');
actor(1,'bql'); check($s->decide($request,'approve','Reviewed full-period parking evidence')==='approved','management approval');
check($s->decide($request,'approve','Retry')==='approved','approval retry idempotent');
check((int)$db->query('SELECT COUNT(*) FROM supplementary_debits')->fetchColumn()===1,'exactly one debit');
check($s->reconciled($charge),'approved complete reconciliation resolves specific difference');
check((new PeriodClosingService())->lockBlockers($period)===[],'reconciliation permits normal closing checks');
check($s->balances($charge)['combined']==='1095399.60','combined liability adds supplement without overwrite');
check($s->balances($charge)['original_paid']==='845399.60' && $s->balances($charge)['original_remaining']==='0.00','original payment is counted only against original charge');
check($s->balances($charge)['supplementary_remaining']==='250000.00','supplement balance independent');
check(originalHash($charge)===$before,'original record, email, tiers, audit, payments unchanged');
foreach (['billing_reconciliations','supplementary_debits'] as $table) {
    try { $db->exec('UPDATE '.$table.' SET id=id'); throw new RuntimeException('Immutable trigger missing'); }
    catch (PDOException $e) { check($e->getCode()==='45000','immutable '.$table); }
}
$db->exec("UPDATE parking_vehicles SET quantity=3 WHERE apartment_id=1 AND vehicle_type='xe_dap_dien'");
check(!$s->reconciled($charge),'changed input invalidates approved fingerprint');
check((new PeriodClosingService())->lockBlockers($period)!==[],'changed input restores closing blocker');

$c2=fixture('REJECT'); actor(2,'ke_toan'); $r2=$s->submit($c2,proposal($s,$c2));
actor(1,'bql'); denied(fn()=>$s->decide($r2,'reject',''),'rejection requires reason');
check($s->decide($r2,'reject','Evidence incomplete')==='rejected','rejected path');
actor(2,'ke_toan'); $r3=$s->submit($c2,proposal($s,$c2));
check($s->decide($r3,'withdraw','Withdraw to correct evidence')==='withdrawn','withdrawal path');
$r4=$s->submit($c2,proposal($s,$c2));
$db->exec("UPDATE parking_vehicles SET quantity=3 WHERE apartment_id=2 AND vehicle_type='xe_dap_dien'");
actor(1,'bql'); check($s->decide($r4,'approve','test')==='stale_input','stale submitted inputs cannot post');
check((int)$db->query('SELECT COUNT(*) FROM supplementary_debits')->fetchColumn()===1,'reject withdraw stale create no debit');
$c3=fixture('ZERO',false); actor(2,'ke_toan'); $r5=$s->submit($c3,proposal($s,$c3));
actor(1,'bql'); check($s->decide($r5,'approve','No monetary delta')==='approved' && $s->reconciled($c3),'zero delta reconciliation');
check((int)$db->query('SELECT COUNT(*) FROM supplementary_debits')->fetchColumn()===1,'zero delta creates no debit');
// Change actor role to simulate privilege changes on the same initiating account.
$c4=fixture('SEPARATION'); actor(2,'ke_toan'); $r6=$s->submit($c4,proposal($s,$c4));
$db->exec("UPDATE users SET role='bql' WHERE id=2"); actor(2,'bql');
denied(fn()=>$s->decide($r6,'approve','test'),'same account cannot self-approve after role change');
$db->exec("UPDATE users SET role='ke_toan' WHERE id=2");

// Other lock checks remain enforced even for a reconciled issued charge.
$meter=insert('meters',['apartment_id'=>3,'meter_code'=>'MISSING','utility_type'=>'dien','usage_type'=>'sinh_hoat']);
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'chưa có chỉ số'),'missing reading blocker preserved');
insert('meter_readings',['meter_id'=>$meter,'billing_period_id'=>$period,'previous_reading'=>0,'current_reading'=>1,'consumption'=>1,'entered_by'=>3]);
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'Nháp'),'draft reading blocker preserved');
insert('import_batches',['billing_period_id'=>$period,'file_name'=>'synthetic.xlsx','uploaded_by'=>3]);
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'lô import'),'pending import blocker preserved');
check(originalHash($charge)===$before,'all workflow operations preserve original issued records');
// Audit failure must roll back request decision, reconciliation and debit together.
$c5=fixture('ROLLBACK'); actor(2,'ke_toan'); $r7=$s->submit($c5,proposal($s,$c5));
$db->exec("CREATE TRIGGER test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN
 IF NEW.action='DECIDE_BILLING_ADJUSTMENT' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.new_value,'$.reason'))='force audit failure'
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'; END IF; END");
$debits=(int)$db->query('SELECT COUNT(*) FROM supplementary_debits')->fetchColumn();
actor(1,'bql');
try { $s->decide($r7,'approve','force audit failure'); throw new RuntimeException('Missing failure'); }
catch (PDOException $e) { check($e->getCode()==='45000','audit error propagated'); }
check($s->request($r7)['status']==='submitted' && (int)$db->query('SELECT COUNT(*) FROM supplementary_debits')->fetchColumn()===$debits,'approval transaction fully rolled back');
// Two real database connections approving simultaneously must post exactly once.
$c6=fixture('CONCURRENT'); actor(2,'ke_toan'); $r8=$s->submit($c6,proposal($s,$c6));
$workers=[];
for ($i=0;$i<2;$i++) {
    $cmd=[PHP_BINARY,__DIR__.'/support/adjustment_approval_worker.php',$name,(string)$r8];
    $pipes=[]; $process=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $workers[]=[$process,$pipes];
}
foreach ($workers as [$process,$pipes]) {
    $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process)===0 && $stdout==='approved','concurrent approval succeeds idempotently: '.$stderr);
}
$count=$db->prepare('SELECT COUNT(*) FROM supplementary_debits WHERE charge_id=?'); $count->execute([$c6]);
check((int)$count->fetchColumn()===1,'concurrent approval creates exactly one debit');
check(config('permissions.billing_adjustments.submit')===['ke_toan'] && config('permissions.billing_adjustments.decide')===['bql'],'route roles correctly configured');
$router=new App\Core\Router(); require dirname(__DIR__).'/routes/web.php';
$routes=(new ReflectionClass($router))->getProperty('routes')->getValue($router);
foreach ($routes as $route) {
    if ($route['method']==='POST' && $route['action'][0]===App\Controllers\BillingAdjustmentController::class) {
        check(in_array('csrf',$route['middleware'],true) && in_array('auth',$route['middleware'],true),'adjustment POST has CSRF and authentication: '.$route['uri']);
    }
}
// Render the actual Vietnamese document view with synthetic persisted data.
$d=$s->debit((int)$db->query('SELECT id FROM supplementary_debits WHERE charge_id='.$charge)->fetchColumn());
$balances=$s->balances($charge); $history=$s->history($charge);
ob_start(); require dirname(__DIR__).'/resources/views/billing_adjustments/debit.php'; $html=ob_get_clean();
check(str_contains($html,'1.095.399,6') && str_contains($html,'845.399,6') && str_contains($html,'250.000'),'document UI displays original and combined liability');
// Nonparking differences cannot be covered by the parking workflow.
$reading=$db->query('SELECT r.id FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE m.apartment_id=6 AND m.utility_type="dien"')->fetchColumn();
$db->exec('UPDATE meter_readings SET current_reading=current_reading+1,consumption=consumption+1 WHERE id='.(int)$reading);
denied(fn()=>$s->capture($c6),'unrelated electricity difference cannot be reconciled');
check(!$s->reconciled($c6),'nonparking change invalidates approved reconciliation');
$unconfirmedApartment=insert('apartments',['building_id'=>$building,'code'=>'UNCONFIRMED','area'=>'80.00']);
actor(2,'ke_toan'); (new BillingCalculationService())->calculateApartment($period,$unconfirmedApartment);
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'bản tính chưa xác nhận'),'unconfirmed charge blocker preserved');
$missingApartment=insert('apartments',['building_id'=>$building,'code'=>'NO-CHARGE','area'=>'80.00']);
$missingMeter=insert('meters',['apartment_id'=>$missingApartment,'meter_code'=>'NO-CHARGE-DIEN','utility_type'=>'dien','usage_type'=>'sinh_hoat']);
insert('meter_readings',['meter_id'=>$missingMeter,'billing_period_id'=>$period,'previous_reading'=>0,'current_reading'=>1,'consumption'=>1,'entered_by'=>3,'status'=>'da_xac_nhan']);
check(str_contains(implode(';',(new PeriodClosingService())->lockBlockers($period)),'Căn chưa có bản tính phí'),'missing charge blocker preserved');
echo "$checks/$checks PASS; isolated database retained: $name\n";
