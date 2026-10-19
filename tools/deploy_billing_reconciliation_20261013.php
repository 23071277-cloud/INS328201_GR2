<?php
declare(strict_types=1);
// Explicit reviewed deployment tool, NEVER referenced by startup.
define('RESTORE_REHEARSAL_LIBRARY_ONLY',true);
require __DIR__.'/restore_rehearsal_20261013.php';
restore_error_handler(); restore_exception_handler();
$apply=($argv[1]??'')==='--apply';
$migration=dirname(__DIR__).'/database/migrations/20261013_billing_reconciliation.sql';
$receipt=json_decode(file_get_contents(dirname(__DIR__).'/storage/restore-rehearsal-20261013.json'),true,512,JSON_THROW_ON_ERROR);
$reviewHash='75b185557656242c37d21d9c522f575dadba8a30e8b87afe9a2f511c87511ede';
$newTables=['billing_adjustment_requests','billing_reconciliations','supplementary_debits'];
$newTriggers=['bar_no_delete','bar_transition','br_no_delete','br_no_update','sd_no_delete','sd_no_update'];
$report=['mode'=>$apply?'apply':'preflight','target'=>'energy_dss','checks'=>[],'executed_sql'=>[]];
$db=null; $originalReadOnly=null; $gate=false; $started=false;
function requirePass(bool $ok,string $label): void {
 global $report; $report['checks'][$label]=$ok?'PASS':'FAIL'; if (!$ok) throw new RuntimeException($label);
}
function alone(PDO $db): bool {
 $rows=$db->query("SELECT ID,USER FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND USER<>'system user'")->fetchAll(PDO::FETCH_ASSOC);
 return count($rows)===0;
}
function sqlCommand(PDO $db,string $sql): void {
 global $report; $report['executed_sql'][]=$sql; $db->exec($sql);
}
try {
 requirePass(($receipt['result']??'')==='PASS','restore rehearsal passed');
 requirePass(hash_file('sha256',$migration)===$reviewHash,'migration reviewed SHA256 unchanged');
 requirePass(is_file($file)&&hash_file('sha256',$file)===$receipt['backup']['sha256'],'verified backup unchanged');
 $db=App\Core\Database::connection();
 requirePass($db->query('SELECT DATABASE()')->fetchColumn()==='energy_dss','target exactly energy_dss');
 requirePass((int)$db->query('SELECT @@foreign_key_checks')->fetchColumn()===1 && (int)$db->query('SELECT @@check_constraint_checks')->fetchColumn()===1,'FK and CHECK enforcement enabled');
 $before=inventory($db,'energy_dss'); $beforeObjects=objects($db,'energy_dss');
 requirePass(array_keys($before)===array_keys($receipt['tables']),'expected 29-table pre-migration schema');
 foreach ($receipt['tables'] as $table=>$expected) {
   requirePass($before[$table]===$expected['original'],'backup schema/count/data match: '.$table);
 }
 requirePass($beforeObjects===$receipt['objects']['original'],'backup routine trigger event definitions match');
 requirePass(count($beforeObjects['triggers'])===0,'no existing trigger conflicts');
 requirePass(array_sum(foreignKeys($db,'energy_dss'))===0,'pre-migration foreign key integrity');
 requirePass(alone($db),'no other database sessions or concurrent DDL');
 $statements=dumpStatements($migration);
 requirePass(count($statements)===9,'exactly nine reviewed DDL statements');
 foreach ($statements as $sql) {
  requirePass((bool)preg_match('/^CREATE (TABLE|TRIGGER)\s+[a-z_]+\b/',identifiersOnly($sql)),'only additive CREATE TABLE/TRIGGER statements');
 }
 $report['migration_sha256']=$reviewHash;
 if ($apply) {
   // The caller pauses all Apache/PHP entrypoints before invoking --apply.
   $pause=json_decode(file_get_contents(dirname(__DIR__).'/storage/billing-deployment-pause.json'),true,512,JSON_THROW_ON_ERROR);
   requirePass(($pause['paused']??false)===true,'application pause receipt present');
   requirePass((int)$db->query("SELECT GET_LOCK('energy_dss_billing_migration_20261013',0)")->fetchColumn()===1,'exclusive migration coordination lock'); $gate=true;
   $originalReadOnly=(int)$db->query('SELECT @@global.read_only')->fetchColumn();
   sqlCommand($db,'SET GLOBAL read_only=ON');
   requirePass(alone($db),'sole administrative session under read-only gate');
   // Recheck the backed-up state immediately before the first nontransactional DDL.
   requirePass(inventory($db,'energy_dss')===$before && objects($db,'energy_dss')===$beforeObjects,'production unchanged after write pause');
   requirePass(hash_file('sha256',$migration)===$reviewHash,'migration hash unchanged immediately before execution');
   foreach ($statements as $sql) {
       requirePass(alone($db),'no concurrent sessions before each DDL');
       $started=true; sqlCommand($db,$sql); // PDO throws on first error; no retry or --force.
   }
   $after=inventory($db,'energy_dss'); $afterObjects=objects($db,'energy_dss');
   $added=array_values(array_diff(array_keys($after),array_keys($before))); sort($added); $wanted=$newTables; sort($wanted);
   requirePass($added===$wanted && count($after)===32,'exactly three new tables');
   requirePass(array_keys($afterObjects['triggers'])===$newTriggers,'exactly six new triggers');
   foreach ($before as $table=>$original) { requirePass($after[$table]===$original,'historical schema/count/data unchanged: '.$table); }
   requirePass($afterObjects['routines']===$beforeObjects['routines']&&$afterObjects['events']===$beforeObjects['events'],'existing routines and events unchanged');
   // Compare full generated definitions against the successful isolated migration test.
   foreach ($newTables as $table) {
       $live=$db->query('SHOW CREATE TABLE energy_dss.'.$table)->fetch(PDO::FETCH_NUM)[1];
       $tested=$db->query('SHOW CREATE TABLE energy_dss_adjustment_test_20261013_e.'.$table)->fetch(PDO::FETCH_NUM)[1];
       $normalize=fn($sql)=>preg_replace('/AUTO_INCREMENT=\d+\s*/','',$sql);
       requirePass($normalize($live)===$normalize($tested),'full table/PK/FK/index/CHECK definition verified: '.$table);
       requirePass($after[$table]['rows']===0,'no real requests/reconciliations/debits created: '.$table);
   }
   $triggerQuery='SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT,SQL_MODE FROM information_schema.triggers WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME IN ('.implode(',',array_fill(0,6,'?')).') ORDER BY TRIGGER_NAME';
   $q=$db->prepare($triggerQuery); $q->execute(array_merge(['energy_dss'],$newTriggers)); $live=$q->fetchAll();
   $q->execute(array_merge(['energy_dss_adjustment_test_20261013_e'],$newTriggers)); $tested=$q->fetchAll();
   requirePass($live===$tested,'six complete trigger definitions match isolated tested migration');
   requirePass(array_sum(foreignKeys($db,'energy_dss'))===0,'post-migration foreign key integrity');
   requirePass((new App\Services\BillingAdjustmentService())->available(),'application recognizes complete migration');
   requirePass(App\Services\IssuedChargeGuard::protected(21),'legacy issued charge21 remains protected');
   requirePass($db->query('SELECT total_due FROM apartment_charges WHERE id=21')->fetchColumn()==='845399.60','charge21 original amount unchanged');
   requirePass($db->query('SELECT status FROM email_notifications WHERE id=1')->fetchColumn()==='sent','email1 remains sent');
   requirePass($db->query('SELECT status FROM billing_periods WHERE id=12')->fetchColumn()==='mo_lai','period12 remains reopened');
   $report['postmigration_verified']=true;
 }
 $report['result']='PASS';
} catch (Throwable $e) {
 $report['result']='FAIL'; $report['error']=$e->getMessage(); $report['ddl_started']=$started;
 // Do not revert partial schema or rerun. Keep writers paused/read-only if DDL began.
} finally {
 if ($db&&$originalReadOnly!==null&&(!$started||($report['postmigration_verified']??false))) {
    sqlCommand($db,'SET GLOBAL read_only='.$originalReadOnly);
 }
 if ($db&&$gate) $db->query("SELECT RELEASE_LOCK('energy_dss_billing_migration_20261013')");
}
$reportPath=dirname(__DIR__).'/storage/billing-deployment-'.($apply?'result':'preflight').'.json';
file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['result'=>$report['result'],'mode'=>$report['mode'],'checks'=>$report['checks'],'error'=>$report['error']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['result']==='PASS'?0:1);
