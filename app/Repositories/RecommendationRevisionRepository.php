<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\BaseRepository;

final class RecommendationRevisionRepository extends BaseRepository
{
    protected string $table = 'recommendation_revisions';
    public function available(): bool
    {
        return $this->readiness()['ready'];
    }
    /** Metadata reads only: never installs or repairs database objects. */
    public function readiness(): array
    {
        try {
            $table=$this->fetchAllQuery("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recommendation_revisions'");
            if ($table===[]) return ['ready'=>false,'status'=>'missing','message'=>'Chưa triển khai bảng recommendation_revisions. Đề xuất theo ngữ cảnh chỉ được hiển thị; không lưu phiên bản mới.'];
            $errors=[];
            if ($table[0]['ENGINE']!=='InnoDB') $errors[]='ENGINE InnoDB';
            $columns=$this->fetchAllQuery("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recommendation_revisions'");
            $types=array_column($columns,'COLUMN_TYPE','COLUMN_NAME');
            foreach (['id'=>'bigint unsigned','alert_id'=>'bigint unsigned','recommendation_rule_id'=>'int unsigned','legacy_recommendation_id'=>'bigint unsigned','previous_revision_id'=>'bigint unsigned','workflow_stage'=>'varchar(40)','category'=>'varchar(80)','recommendation_text'=>'varchar(500)','context_hash'=>'char(64)','snapshot_json'=>'longtext','created_by'=>'int unsigned','created_at'=>'datetime'] as $name=>$type) {
                $actual=preg_replace('/^(bigint|int)\(\d+\)/','$1',$types[$name]??'');
                if ($actual!==$type) $errors[]='cột '.$name;
            }
            $indexes=$this->fetchAllQuery("SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recommendation_revisions' GROUP BY INDEX_NAME,NON_UNIQUE");
            $indexMap=array_column($indexes,null,'INDEX_NAME');
            foreach (['PRIMARY'=>['id',0],'uq_rec_revision_context'=>['alert_id,recommendation_rule_id,context_hash',0],'idx_rec_revision_alert'=>['alert_id,id',1]] as $name=>[$cols,$unique]) {
                if (($indexMap[$name]['columns_list']??'')!==$cols || (int)($indexMap[$name]['NON_UNIQUE']??-1)!==$unique) $errors[]='chỉ mục '.$name;
            }
            $keys=$this->fetchAllQuery("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='recommendation_revisions' AND k.REFERENCED_TABLE_NAME IS NOT NULL");
            $keyMap=array_column($keys,null,'CONSTRAINT_NAME');
            $schema=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();
            foreach (['fk_recr_alert'=>['alert_id','alerts'],'fk_recr_rule'=>['recommendation_rule_id','recommendation_rules'],'fk_recr_legacy'=>['legacy_recommendation_id','recommendations'],'fk_recr_previous'=>['previous_revision_id','recommendation_revisions'],'fk_recr_actor'=>['created_by','users']] as $name=>[$column,$parent]) {
                $key=$keyMap[$name]??[];
                if (($key['COLUMN_NAME']??'')!==$column || ($key['REFERENCED_TABLE_NAME']??'')!==$parent || ($key['REFERENCED_COLUMN_NAME']??'')!=='id' || ($key['REFERENCED_TABLE_SCHEMA']??'')!==$schema || !in_array($key['DELETE_RULE']??'', ['RESTRICT','NO ACTION'],true) || !in_array($key['UPDATE_RULE']??'', ['RESTRICT','NO ACTION'],true)) $errors[]='khóa ngoại '.$name;
            }
            $guards=$this->fetchAllQuery("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('rec_revision_no_update','rec_revision_no_delete')");
            $errors=array_merge($errors,self::guardErrors($guards));
            return ['ready'=>$errors===[],'status'=>$errors===[]?'ready':'incomplete','message'=>$errors===[]?'Lưu phiên bản nối tiếp đã sẵn sàng.':'Schema phiên bản khuyến nghị chưa hoàn chỉnh: '.implode(', ',$errors).'. Chỉ xem đề xuất; không lưu phiên bản mới.'];
        } catch (\PDOException $e) {
            return ['ready'=>false,'status'=>'unavailable','message'=>'Không thể xác minh schema phiên bản khuyến nghị. Chỉ xem đề xuất; không lưu phiên bản mới.'];
        }
    }
    public static function guardErrors(array $guards): array
    {
        $map=array_column($guards,null,'TRIGGER_NAME'); $errors=[];
        foreach (['rec_revision_no_update'=>['UPDATE','Immutable recommendation revision'],'rec_revision_no_delete'=>['DELETE','Preserve recommendation revision history']] as $name=>[$event,$message]) {
            $g=$map[$name]??[];
            // Match the complete unconditional guard, not a SIGNAL substring. SQL
            // keywords and spacing are formatting; conditionals/handlers are not.
            $pattern="~\\A\\s*BEGIN\\s+SIGNAL\\s+SQLSTATE\\s+(?:VALUE\\s+)?'45000'\\s+SET\\s+`?MESSAGE_TEXT`?\\s*=\\s*'".preg_quote($message,'~')."'\\s*;\\s*END\\s*;?\\s*\\z~i";
            if (($g['EVENT_OBJECT_TABLE']??'')!=='recommendation_revisions' || strtoupper(trim($g['EVENT_MANIPULATION']??''))!==$event || strtoupper(trim($g['ACTION_TIMING']??''))!=='BEFORE' || preg_match($pattern,$g['ACTION_STATEMENT']??'')!==1) $errors[]='trigger '.$name;
        }
        return $errors;
    }
    public function forAlert(int $alert): array
    {
        return $this->available() ? $this->fetchAllQuery('SELECT x.*,r.name rule_name,r.status rule_status,r.condition_type,r.recommendation_text rule_text_now FROM recommendation_revisions x JOIN recommendation_rules r ON r.id=x.recommendation_rule_id WHERE x.alert_id=:a ORDER BY x.id',['a'=>$alert]) : [];
    }
    public function existsContext(int $alert,int $rule,string $hash): bool
    {
        return (int)$this->scalarQuery('SELECT COUNT(*) FROM recommendation_revisions WHERE alert_id=:a AND recommendation_rule_id=:r AND context_hash=:h',['a'=>$alert,'r'=>$rule,'h'=>$hash])>0;
    }
}
