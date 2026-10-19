<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$out=$argv[1]??''; $target=$argv[2]??'';
if (!preg_match('/^energy_dss_fn08_restore_verify_20261014_[0-9]{6}$/',$target) || !is_dir($out)) throw new RuntimeException('Invalid destination');
$db=App\Core\Database::connection();
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='energy_dss') throw new RuntimeException('Wrong production target');
function rows(PDO $d,string $q): array { return $d->query($q)->fetchAll(PDO::FETCH_ASSOC); }
function saveReport(string $name,array $v): void { global $out; file_put_contents($out.'/'.$name,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function snap(PDO $d,string $schema): array {
 $q=$d->quote($schema); $s=['tables'=>[]];
 foreach(rows($d,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=$q AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $t) {
  $n=$t['TABLE_NAME']; $ddl=rows($d,"SHOW CREATE TABLE `$schema`.`$n`")[0]['Create Table'];
  $ddl=str_replace("`$schema`.",'',$ddl);
  $data=rows($d,"SELECT * FROM `$schema`.`$n`");
  $enc=array_map(fn($r)=>json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$data); sort($enc,SORT_STRING);
  $s['tables'][$n]=['rows'=>count($data),'sha256'=>hash('sha256',json_encode($enc,JSON_THROW_ON_ERROR)),'definition'=>$ddl];
 }
 $s['indexes']=rows($d,"SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=$q ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX");
 $s['foreign_keys']=rows($d,"SELECT TABLE_NAME,CONSTRAINT_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE,REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=$q ORDER BY TABLE_NAME,CONSTRAINT_NAME");
 $s['fk_columns']=rows($d,"SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=$q AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION");
 $s['triggers']=rows($d,"SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=$q ORDER BY TRIGGER_NAME");
 $s['routines']=[];
 foreach(rows($d,"SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=$q ORDER BY ROUTINE_NAME") as $r) $s['routines'][]=rows($d,"SHOW CREATE {$r['ROUTINE_TYPE']} `$schema`.`{$r['ROUTINE_NAME']}`")[0];
 $s['events']=rows($d,"SELECT EVENT_NAME,EVENT_DEFINITION,EVENT_TYPE,EXECUTE_AT,INTERVAL_VALUE,INTERVAL_FIELD,STARTS,ENDS,STATUS,ON_COMPLETION,SQL_MODE,DEFINER,TIME_ZONE FROM information_schema.EVENTS WHERE EVENT_SCHEMA=$q ORDER BY EVENT_NAME");
 $s['critical']=[];
 foreach(['charge21'=>['apartment_charges',21],'email1'=>['email_notifications',1],'reconciliation1'=>['billing_reconciliations',1],'debit1'=>['supplementary_debits',1],'alert5'=>['alerts',5],'case2'=>['inspection_cases',2],'recommendation4'=>['recommendations',4]] as $key=>[$table,$id]) {
  $r=rows($d,"SELECT * FROM `$schema`.`$table` WHERE id=$id"); $s['critical'][$key]=['rows'=>count($r),'sha256'=>hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
 }
 $s['orphans']=[];
 foreach($s['foreign_keys'] as $fk) {
  $cols=array_values(array_filter($s['fk_columns'],fn($c)=>$c['TABLE_NAME']===$fk['TABLE_NAME']&&$c['CONSTRAINT_NAME']===$fk['CONSTRAINT_NAME']));
  $join=implode(' AND ',array_map(fn($c)=>"c.`{$c['COLUMN_NAME']}`=p.`{$c['REFERENCED_COLUMN_NAME']}`",$cols));
  $nonnull=implode(' AND ',array_map(fn($c)=>"c.`{$c['COLUMN_NAME']}` IS NOT NULL",$cols));
  $n=(int)$d->query("SELECT COUNT(*) FROM `$schema`.`{$fk['TABLE_NAME']}` c WHERE $nonnull AND NOT EXISTS (SELECT 1 FROM `$schema`.`{$fk['REFERENCED_TABLE_NAME']}` p WHERE $join)")->fetchColumn();
  $s['orphans'][$fk['CONSTRAINT_NAME']]=$n;
 }
 return $s;
}
function runClient(array $command,?string $input,string $prefix): int {
 global $out;
 $spec=[0=>$input===null?['pipe','r']:['file',$input,'r'],1=>['file',$out.'/'.$prefix.'.stdout.log','w'],2=>['file',$out.'/'.$prefix.'.stderr.log','w']];
 $p=proc_open($command,$spec,$pipes,null,null,['bypass_shell'=>true]); if(!is_resource($p)) throw new RuntimeException('Client launch failed');
 if($input===null) fclose($pipes[0]); return proc_close($p);
}
$cfg=App\Core\Config::get('database'); putenv('MYSQL_PWD='.$cfg['password']);
$common=['--host='.$cfg['host'],'--port='.$cfg['port'],'--user='.$cfg['username'],'--default-character-set=utf8mb4'];
$dump=$out.'/energy_dss_fn08_pre_migration.sql'; $locked=false;
try {
 if((int)$db->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=".$db->quote($target))->fetchColumn()) throw new RuntimeException('Verification database already exists');
 $sessions=rows($db,"SELECT ID,USER,COMMAND FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND USER<>'system user'");
 if($sessions!==[]) throw new RuntimeException('Other database client sessions exist');
 $db->exec('SET SESSION lock_wait_timeout=10'); $db->exec('FLUSH TABLES WITH READ LOCK'); $locked=true;
 $before=snap($db,'energy_dss'); saveReport('production-before.json',$before);
 if(count($before['tables'])!==32 || count($before['triggers'])!==6 || count($before['routines'])!==1 || count($before['events'])!==0 || array_sum($before['orphans'])!==0) throw new RuntimeException('Unexpected inventory or orphan records');
 $command=array_merge(['C:/xampp/mysql/bin/mysqldump.exe'],$common,['--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--skip-add-drop-table','--skip-add-drop-trigger','--result-file='.$dump,'energy_dss']);
 $exit=runClient($command,null,'dump');
 $sql=is_file($dump)?file_get_contents($dump):''; $errors=file_get_contents($out.'/dump.stderr.log');
 $backup=['path'=>$dump,'bytes'=>strlen($sql),'sha256'=>hash('sha256',$sql),'exit_code'=>$exit,'stderr'=>$errors,'completion_marker'=>(bool)preg_match('/-- Dump completed on /',$sql),'command_options'=>array_slice($command,1)];
 saveReport('backup-manifest.json',$backup);
 if($exit!==0 || $errors!=='' || strlen($sql)===0 || !$backup['completion_marker']) throw new RuntimeException('Backup validation failed');
 // No database selectors, schema-qualified production targets, CALLs or active events are allowed.
 if(preg_match('/\b(?:CREATE\s+DATABASE|USE\s+`|CALL\s+|`energy_dss`\s*\.)/i',$sql)) throw new RuntimeException('Unsafe dump database switch or invocation');
 $restore=preg_replace('/^.*\bDROP PROCEDURE IF EXISTS `cleanup_demo_keep_112026`.*\R/m','',$sql,$removed);
 // Routine body contains DELETE statements but is only defined, never invoked.
 if($removed!==1 || preg_match('/\bDROP\s+(?:TABLE|DATABASE|TRIGGER|PROCEDURE)\b/i',$restore)) throw new RuntimeException('Unexpected destructive DDL in restore');
 file_put_contents($out.'/isolated-restore.sql',$restore);
 saveReport('restore-safety.json',['database_switches'=>0,'schema_qualified_production_targets'=>0,'routine_calls'=>0,'removed_drop_procedure_statements'=>$removed,'restore_sha256'=>hash('sha256',$restore)]);
 $db->exec('UNLOCK TABLES'); $locked=false;
 $db->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
 $exit=runClient(array_merge(['C:/xampp/mysql/bin/mysql.exe'],$common,['--database='.$target]),$out.'/isolated-restore.sql','restore');
 saveReport('restore-execution.json',['target'=>$target,'exit_code'=>$exit,'stderr'=>file_get_contents($out.'/restore.stderr.log')]);
 if($exit!==0 || file_get_contents($out.'/restore.stderr.log')!=='') throw new RuntimeException('Restore failed; isolated database retained');
 $restored=snap($db,$target); saveReport('restored.json',$restored);
 $after=snap($db,'energy_dss'); saveReport('production-after.json',$after);
 $differences=[]; foreach($before as $key=>$value) if($value!==$restored[$key]) $differences[]=$key;
 $report=['restore_database'=>$target,'backup'=>$backup,'table_count'=>count($restored['tables']),'trigger_count'=>count($restored['triggers']),'routine_count'=>count($restored['routines']),'event_count'=>count($restored['events']),'foreign_key_count'=>count($restored['foreign_keys']),'orphan_count'=>array_sum($restored['orphans']),'differences'=>$differences,'production_unchanged'=>$before===$after,'critical'=>$restored['critical'],'pass'=>$differences===[]&&$before===$after];
 saveReport('verification-report.json',$report); echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
 if(!$report['pass']) exit(3);
} catch(Throwable $e) { saveReport('failure.json',['error'=>$e->getMessage()]); fwrite(STDERR,$e->getMessage().PHP_EOL); exit(2); }
finally { if($locked) $db->exec('UNLOCK TABLES'); putenv('MYSQL_PWD'); }
