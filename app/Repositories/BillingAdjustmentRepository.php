<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\BaseRepository;

final class BillingAdjustmentRepository extends BaseRepository
{
    protected string $table = 'billing_adjustment_requests';
    public function available(): bool
    {
        $tables = (int)$this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
            AND table_name IN ('billing_adjustment_requests','billing_reconciliations','supplementary_debits')")->fetchColumn();
        $triggers = (int)$this->db->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('br_no_update','br_no_delete','sd_no_update','sd_no_delete','bar_no_delete','bar_transition')")->fetchColumn();
        return $tables === 3 && $triggers === 6;
    }
    public function rows(string $sql, array $params = []): array
    {
        $q = $this->db->prepare($sql); $q->execute($params); return $q->fetchAll();
    }
    public function execute(string $sql, array $params): void
    {
        $q = $this->db->prepare($sql); $q->execute($params);
    }
    public function insert(string $table, array $row): int
    {
        if (!in_array($table, ['billing_adjustment_requests','billing_reconciliations','supplementary_debits'], true)) {
            throw new \InvalidArgumentException('Invalid adjustment table');
        }
        $this->execute('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')', array_values($row));
        return (int)$this->db->lastInsertId();
    }
    public function history(int $charge): array
    {
        return $this->rows('SELECT q.*,r.id reconciliation_id,d.id debit_id,d.amount debit_amount
            FROM billing_adjustment_requests q LEFT JOIN billing_reconciliations r ON r.request_id=q.id
            LEFT JOIN supplementary_debits d ON d.reconciliation_id=r.id WHERE q.charge_id=? ORDER BY q.id DESC',[$charge]);
    }
}
