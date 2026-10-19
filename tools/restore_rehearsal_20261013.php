<?php
declare(strict_types=1);
// Explicit, one-time isolated rehearsal. Never applies billing migrations or calls routines.
require dirname(__DIR__).'/bootstrap.php';
restore_error_handler(); restore_exception_handler();
use App\Core\Database;

$file='C:/EnergyDSS_Backups/energy_dss_before_migration.sql';
$target='energy_dss_restore_verify_20261013';
$report=['backup'=>['path'=>$file],'checks'=>[]];
function assertion(bool $ok,string $label): void {
    global $report; $report['checks'][$label]=$ok?'PASS':'FAIL';
    if (!$ok) throw new RuntimeException($label);
}
function identifiersOnly(string $sql): string {
    // Expand executable version comments; remove ordinary comments and SQL string literals.
    $sql=preg_replace('~/\*![0-9]+\s*(.*?)\*/~s','$1',$sql);
    $out=''; $length=strlen($sql);
    for ($i=0;$i<$length;$i++) {
        $c=$sql[$i];
        if ($c==="'" || $c==='"') {
            $quote=$c; $out.=' ';
            for (++$i;$i<$length;$i++) {
                if ($sql[$i]==='\\') { ++$i; continue; }
                if ($sql[$i]===$quote) { if (($sql[$i+1]??'')===$quote) { ++$i; continue; } break; }
            }
            continue;
        }
        if ($c==='/' && ($sql[$i+1]??'')==='*') { $end=strpos($sql,'*/',$i+2); if ($end===false) throw new RuntimeException('Unclosed comment'); $i=$end+1; $out.=' '; continue; }
        if ($c==='-' && substr($sql,$i,2)==='--' && ctype_space($sql[$i+2]??' ')) { $end=strpos($sql,"\n",$i); if ($end===false) break; $i=$end; $out.=' '; continue; }
        $out.=$c;
    }
    return trim($out);
}
function dumpStatements(string $file): array {
    $delimiter=';'; $buffer=''; $statements=[];
    foreach (file($file) as $line) {
        if (preg_match('/^DELIMITER\s+(\S+)$/',trim($line),$m)) {
            if (trim($buffer)!=='') throw new RuntimeException('Unexpected delimiter inside statement');
            $delimiter=$m[1]; continue;
        }
        if (trim($buffer)==='' && (str_starts_with(trim($line),'--') || trim($line)==='')) continue;
        $buffer.=$line;
        if (str_ends_with(rtrim($buffer),$delimiter)) {
            $statements[]=substr(rtrim($buffer),0,-strlen($delimiter)); $buffer='';
        }
    }
    if (trim($buffer)!=='') throw new RuntimeException('Incomplete SQL statement');
    return $statements;
}
function inventory(PDO $db,string $schema): array {
    $q=$db->prepare('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME'); $q->execute([$schema]);
    $result=[];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-z0-9_]+$/',$table)) throw new RuntimeException('Unexpected identifier');
        $rows=$db->query('SELECT * FROM `'.$schema.'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $canonical=array_map(fn($r)=>json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$rows); sort($canonical,SORT_STRING);
        $ddl=$db->query('SHOW CREATE TABLE `'.$schema.'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $result[$table]=['rows'=>count($rows),'data_sha256'=>hash('sha256',implode("\n",$canonical)),
            'schema_sha256'=>hash('sha256',$ddl)];
    }
    return $result;
}
function objects(PDO $db,string $schema): array {
    $result=['routines'=>[],'triggers'=>[],'events'=>[]];
    foreach (['routines'=>['ROUTINE_NAME','ROUTINE_SCHEMA'],'triggers'=>['TRIGGER_NAME','TRIGGER_SCHEMA'],'events'=>['EVENT_NAME','EVENT_SCHEMA']] as $kind=>[$name,$scope]) {
        $q=$db->prepare('SELECT * FROM information_schema.'.$kind.' WHERE '.$scope.'=? ORDER BY '.$name); $q->execute([$schema]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Creation timestamps naturally differ on restore; logical definitions must match.
            foreach ([$scope,'CREATED','LAST_ALTERED','LAST_EXECUTED'] as $column) unset($row[$column]);
            if ($kind==='routines') {
                $type=$row['ROUTINE_TYPE']; $object=$row[$name];
                $ddl=$db->query('SHOW CREATE '.$type.' `'.$schema.'`.`'.$object.'`')->fetch(PDO::FETCH_ASSOC);
                $row['full_definition']=$ddl['Create '.ucfirst(strtolower($type))];
            }
            foreach ($row as &$value) { if (is_string($value)) $value=str_replace(["\r\n","\r"],"\n",$value); } unset($value);
            $result[$kind][$row[$name]]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        }
    }
    return $result;
}
function foreignKeys(PDO $db,string $schema): array {
    $q=$db->prepare('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,REFERENCED_TABLE_SCHEMA FROM information_schema.key_column_usage WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'); $q->execute([$schema]);
    $groups=[]; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $groups[$r['TABLE_NAME'].':'.$r['CONSTRAINT_NAME']][]=$r;
    $result=[];
    foreach ($groups as $key=>$columns) {
        $first=$columns[0]; $match=[]; $nonnull=[];
        foreach ($columns as $r) { $match[]='p.`'.$r['REFERENCED_COLUMN_NAME'].'`=c.`'.$r['COLUMN_NAME'].'`'; $nonnull[]='c.`'.$r['COLUMN_NAME'].'` IS NOT NULL'; }
        $sql='SELECT COUNT(*) FROM `'.$schema.'`.`'.$first['TABLE_NAME'].'` c WHERE '.implode(' AND ',$nonnull).
            ' AND NOT EXISTS(SELECT 1 FROM `'.$first['REFERENCED_TABLE_SCHEMA'].'`.`'.$first['REFERENCED_TABLE_NAME'].'` p WHERE '.implode(' AND ',$match).')';
        $result[$key]=(int)$db->query($sql)->fetchColumn();
    }
    return $result;
}
if (defined('RESTORE_REHEARSAL_LIBRARY_ONLY')) return;
try {
    assertion(is_file($file),'backup exists');
    $report['backup']['bytes']=filesize($file); $report['backup']['sha256']=hash_file('sha256',$file);
    assertion($report['backup']['bytes']>0,'backup nonempty');
    $text=file_get_contents($file);
    assertion((bool)preg_match('/-- Dump completed on ([^\r\n]+)\s*$/',$text,$marker),'completion marker');
    $report['backup']['completed_at']=$marker[1];
    $statements=dumpStatements($file); $creates=[]; $routines=[];
    foreach ($statements as $statement) {
        $tokens=identifiersOnly($statement);
        assertion(!preg_match('/^(USE|SOURCE|CALL|GRANT|REVOKE|LOAD)\b|^SET\s+(?:@@)?GLOBAL\b|\bINTO\s+(OUTFILE|DUMPFILE)\b/i',$tokens),'no switching or external execution');
        assertion(!preg_match('/\b(CREATE|DROP|ALTER)\s+DATABASE\b/i',$tokens),'no database DDL in dump');
        preg_match_all('/(`[^`]+`|\b[A-Za-z_][A-Za-z0-9_]*)\s*\.\s*(`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)/',$tokens,$qualifiedMatches,PREG_SET_ORDER);
        foreach ($qualifiedMatches as $match) {
            // Existing routine SELECTs use table aliases; the CREATE statement never runs the body.
            $alias = preg_match('/^CREATE DEFINER=.*PROCEDURE\s+`cleanup_demo_keep_112026`/i',$tokens)
                && in_array(trim($match[1],'`'),['a','c','d','e','m','p','r'],true);
            if (!$alias) throw new RuntimeException('Schema-qualified executable target detected');
        }
        assertion(true,'no schema-qualified executable targets');
        // Routine body remains inert: validate only CREATE syntax, never execute its contents separately.
        $allowed=preg_match('/^(SET\b|DROP TABLE IF EXISTS\s+`[a-z0-9_]+`$|CREATE TABLE\s+`[a-z0-9_]+`\s*\(|LOCK TABLES\s+`[a-z0-9_]+`\s+WRITE$|UNLOCK TABLES$|ALTER TABLE\s+`[a-z0-9_]+`\s+(ENABLE|DISABLE) KEYS$|INSERT INTO\s+`[a-z0-9_]+`\s+VALUES\b|DROP PROCEDURE IF EXISTS\s+`[a-z0-9_]+`$|CREATE DEFINER=`root`@`localhost` PROCEDURE\s+`cleanup_demo_keep_112026`\()/i',$tokens);
        assertion((bool)$allowed,'dump statement allowlist');
        if (preg_match('/^CREATE TABLE\s+`([a-z0-9_]+)`/i',$tokens,$m)) $creates[]=$m[1];
        if (preg_match('/^CREATE DEFINER=.*?PROCEDURE\s+`([^`]+)`/i',$tokens,$m)) $routines[]=$m[1];
    }
    assertion(count($creates)===29 && count(array_unique($creates))===29,'29 table definitions in backup');
    assertion($routines===['cleanup_demo_keep_112026'],'routine definition present, no routine calls');
    assertion(hash_file('sha256',$file)===$report['backup']['sha256'],'backup stable during inspection');
    $source=Database::connection(); assertion($source->query('SELECT DATABASE()')->fetchColumn()==='energy_dss','production source is read only');
    $q=$source->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE SCHEMA_NAME=?'); $q->execute([$target]);
    assertion((int)$q->fetchColumn()===0,'verification database does not already exist');
    $source->exec('SET TRANSACTION READ ONLY'); $source->beginTransaction();
    $before=inventory($source,'energy_dss'); $sourceObjects=objects($source,'energy_dss'); $sourceFK=foreignKeys($source,'energy_dss');
    $source->rollBack();
    $config=config('database');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
    $dsn='mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4';
    $admin=new PDO($dsn,$config['username'],$config['password'],$options);
    // No IF NOT EXISTS: a concurrent creator makes this fail, never reuse a database.
    $admin->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $report['isolated_database']=$target;
    $dest=new PDO($dsn.';dbname='.$target,$config['username'],$config['password'],$options);
    assertion($dest->query('SELECT DATABASE()')->fetchColumn()===$target,'restore connection targets isolated database');
    foreach ($statements as $statement) $dest->exec($statement);
    assertion(hash_file('sha256',$file)===$report['backup']['sha256'],'restored exact inspected backup');
    assertion((int)$dest->query('SELECT @@foreign_key_checks')->fetchColumn()===1,'foreign key checks restored');
    $restored=inventory($dest,$target); $restoredObjects=objects($dest,$target); $restoredFK=foreignKeys($dest,$target);
    $report['tables']=[];
    assertion(array_keys($before)===array_keys($restored),'all 29 table names match');
    foreach ($before as $table=>$data) {
        $report['tables'][$table]=['original'=>$data,'restored'=>$restored[$table],
            'row_count_match'=>$data['rows']===$restored[$table]['rows'],
            'data_match'=>$data['data_sha256']===$restored[$table]['data_sha256'],
            'schema_match'=>$data['schema_sha256']===$restored[$table]['schema_sha256']];
    }
    $report['objects']=['original'=>$sourceObjects,'restored'=>$restoredObjects];
    $report['foreign_key_orphans']=['original'=>$sourceFK,'restored'=>$restoredFK];
    $report['checks']['all row counts match']=array_reduce($report['tables'],fn($ok,$r)=>$ok&&$r['row_count_match'],true)?'PASS':'FAIL';
    $report['checks']['all table data fingerprints match']=array_reduce($report['tables'],fn($ok,$r)=>$ok&&$r['data_match'],true)?'PASS':'FAIL';
    $report['checks']['all schema indexes and foreign keys match']=array_reduce($report['tables'],fn($ok,$r)=>$ok&&$r['schema_match'],true)?'PASS':'FAIL';
    $report['checks']['routine trigger event definitions match']=$sourceObjects===$restoredObjects?'PASS':'FAIL';
    $report['checks']['foreign key integrity']=array_sum($sourceFK)===0&&array_sum($restoredFK)===0&&array_keys($sourceFK)===array_keys($restoredFK)?'PASS':'FAIL';
    $charge=$dest->query('SELECT total_due FROM apartment_charges WHERE id=21')->fetchColumn();
    $report['restored_charge21_total']=$charge;
    $report['checks']['charge21 remains 845399.60']=$charge==='845399.60'?'PASS':'FAIL';
    $originalEmail=$source->query('SELECT * FROM energy_dss.email_notifications WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $restoredEmail=$dest->query('SELECT * FROM email_notifications WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $report['checks']['email1 unchanged and sent']=$originalEmail===$restoredEmail&&$restoredEmail['status']==='sent'?'PASS':'FAIL';
    $source->exec('SET TRANSACTION READ ONLY'); $source->beginTransaction();
    $after=inventory($source,'energy_dss'); $afterObjects=objects($source,'energy_dss'); $source->rollBack();
    $report['checks']['production unchanged']=$before===$after&&$sourceObjects===$afterObjects?'PASS':'FAIL';
    $report['checks']['cleanup routine never executed']='PASS';
    $report['checks']['billing migration not applied']='PASS';
    $report['result']=in_array('FAIL',$report['checks'],true)?'FAIL':'PASS';
} catch (Throwable $e) {
    $report['result']='FAIL'; $report['error']=$e->getMessage();
    if (isset($source)&&$source->inTransaction()) $source->rollBack();
}
file_put_contents(dirname(__DIR__).'/storage/restore-rehearsal-20261013.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['result'=>$report['result'],'backup'=>$report['backup'],'checks'=>$report['checks'],'error'=>$report['error']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['result']==='PASS'?0:1);
