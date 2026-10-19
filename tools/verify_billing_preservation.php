<?php
declare(strict_types=1);
// SELECT-only verification. Never prints credentials or message content.
require dirname(__DIR__).'/bootstrap.php';
$db = App\Core\Database::connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'energy_dss') { exit(2); }
$queries = [
 'charge21'=>'SELECT * FROM apartment_charges WHERE id=21',
 'tiers21'=>'SELECT * FROM charge_tier_details WHERE apartment_charge_id=21 ORDER BY id',
 'email1'=>'SELECT * FROM email_notifications WHERE id=1',
 'charge21_audit'=>"SELECT * FROM audit_logs WHERE entity_type='apartment_charge' AND entity_id='21' ORDER BY id",
 'charges'=>'SELECT * FROM apartment_charges ORDER BY id',
 'readings'=>'SELECT * FROM meter_readings ORDER BY id',
 'prices'=>'SELECT * FROM pricing_plans ORDER BY id',
 'payments'=>'SELECT * FROM payment_transactions ORDER BY id',
 'emails'=>'SELECT * FROM email_notifications ORDER BY id',
 'audit'=>'SELECT * FROM audit_logs ORDER BY id',
];
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
 $result = [];
 foreach ($queries as $key=>$sql) { $rows=$db->query($sql)->fetchAll(); $result[$key]=['rows'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))]; }
 echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
} finally { $db->rollBack(); }
