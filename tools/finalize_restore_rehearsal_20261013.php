<?php
declare(strict_types=1);
// Corrects only comments lost by the original parser in OUR newly created isolated routine.
// Uses a restore receipt; never recreates/reuses any unrelated database or executes routines.
define('RESTORE_REHEARSAL_LIBRARY_ONLY',true);
require __DIR__.'/restore_rehearsal_20261013.php';
$receiptPath=dirname(__DIR__).'/storage/restore-rehearsal-20261013.json';
$report=json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR);
if (($report['isolated_database']??'')!==$target || ($report['checks']['restored exact inspected backup']??'')!=='PASS'
    || hash_file('sha256',$file)!==$report['backup']['sha256']) throw new RuntimeException('Restore receipt mismatch');
$source=App\Core\Database::connection();
if ($source->query('SELECT DATABASE()')->fetchColumn()!=='energy_dss') throw new RuntimeException('Unexpected source');
$c=config('database');
$dest=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$target.';charset=utf8mb4',$c['username'],$c['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
if ($dest->query('SELECT DATABASE()')->fetchColumn()!==$target) throw new RuntimeException('Unexpected destination');
$routine=null;
foreach (dumpStatements($file) as $statement) {
 if (preg_match('/^CREATE DEFINER=`root`@`localhost` PROCEDURE `cleanup_demo_keep_112026`/',trim($statement))) $routine=$statement;
}
if (!$routine) throw new RuntimeException('Expected routine not found');
$a=$source->query('SHOW CREATE PROCEDURE energy_dss.cleanup_demo_keep_112026')->fetch(PDO::FETCH_ASSOC)['Create Procedure'];
$b=$dest->query('SHOW CREATE PROCEDURE `'.$target.'`.cleanup_demo_keep_112026')->fetch(PDO::FETCH_ASSOC)['Create Procedure'];
$normalize=fn($x)=>trim(str_replace(["\r\n","\r"],"\n",$x));
$withoutComments=fn($x)=>implode("\n",array_values(array_filter(array_map('rtrim',explode("\n",$normalize($x))),
    fn($line)=>trim($line)!==''&&!str_starts_with(trim($line),'--'))));
if ($withoutComments($a)!==$withoutComments($b) || $withoutComments($a)!==$withoutComments($routine)) {
 throw new RuntimeException('Executable routine differs: stop without correction');
}
$sourceMode=$source->query("SELECT SQL_MODE FROM information_schema.routines WHERE routine_schema='energy_dss' AND routine_name='cleanup_demo_keep_112026'")->fetchColumn();
$destMode=$dest->query("SELECT SQL_MODE FROM information_schema.routines WHERE routine_schema='energy_dss_restore_verify_20261013' AND routine_name='cleanup_demo_keep_112026'")->fetchColumn();
if ($normalize($b)!==$normalize($a) || $sourceMode!==$destMode) {
    // No procedure call. Only replace this newly restored verification object's definition.
    $dest->exec('SET SESSION sql_mode='.$dest->quote($sourceMode));
    $dest->exec(preg_replace('/^CREATE /','CREATE OR REPLACE ',trim($routine),1));
    $report['routine_comment_correction']='Seven comment lines preserved from original dump; isolated routine definition only; routine never executed.';
}
$source->exec('SET TRANSACTION READ ONLY'); $source->beginTransaction();
$original=inventory($source,'energy_dss'); $originalObjects=objects($source,'energy_dss'); $originalFK=foreignKeys($source,'energy_dss');
$source->rollBack();
$restored=inventory($dest,$target); $restoredObjects=objects($dest,$target); $restoredFK=foreignKeys($dest,$target);
$report['tables']=[];
foreach ($original as $table=>$data) {
 $report['tables'][$table]=['original'=>$data,'restored'=>$restored[$table]??null,
    'row_count_match'=>$data['rows']===($restored[$table]['rows']??null),
    'data_match'=>$data['data_sha256']===($restored[$table]['data_sha256']??null),
    'schema_match'=>$data['schema_sha256']===($restored[$table]['schema_sha256']??null)];
}
$report['checks']['all 29 table names match']=count($original)===29&&array_keys($original)===array_keys($restored)?'PASS':'FAIL';
foreach (['all row counts match'=>'row_count_match','all table data fingerprints match'=>'data_match','all schema indexes and foreign keys match'=>'schema_match'] as $label=>$key) {
 $report['checks'][$label]=array_reduce($report['tables'],fn($ok,$r)=>$ok&&$r[$key],true)?'PASS':'FAIL';
}
$report['objects']=['original'=>$originalObjects,'restored'=>$restoredObjects];
$report['checks']['routine trigger event definitions match']=$originalObjects===$restoredObjects?'PASS':'FAIL';
$report['foreign_key_orphans']=['original'=>$originalFK,'restored'=>$restoredFK];
$report['checks']['foreign key integrity']=array_sum($originalFK)===0&&array_sum($restoredFK)===0&&array_keys($originalFK)===array_keys($restoredFK)?'PASS':'FAIL';
$report['routine_definition_comparison']='Exact definition after CRLF/LF normalization; creation timestamps excluded.';
$report['checks']['restored routine matches exact backup definition']=$normalize($dest->query('SHOW CREATE PROCEDURE `'.$target.'`.cleanup_demo_keep_112026')->fetch(PDO::FETCH_ASSOC)['Create Procedure'])===$normalize($routine)?'PASS':'FAIL';
$report['checks']['backup unchanged']=hash_file('sha256',$file)===$report['backup']['sha256']?'PASS':'FAIL';
$report['result']=in_array('FAIL',$report['checks'],true)?'FAIL':'PASS';
file_put_contents($receiptPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['result'=>$report['result'],'backup'=>$report['backup'],'checks'=>$report['checks'],
 'objects'=>array_map('count',$originalObjects),'foreign_keys'=>count($originalFK),'tables'=>count($original)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['result']==='PASS'?0:1);
