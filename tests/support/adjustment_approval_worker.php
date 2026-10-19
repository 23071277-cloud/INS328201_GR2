<?php
declare(strict_types=1);
$name=$argv[1] ?? '';
if (!preg_match('/^energy_dss_adjustment_test_[a-z0-9_]+$/',$name)) exit(2);
putenv('DB_DATABASE='.$name);
require dirname(__DIR__,2).'/bootstrap.php';
restore_error_handler(); restore_exception_handler();
$_SESSION=['user_id'=>1,'user_role'=>'bql','username'=>'test_bql','full_name'=>'Test'];
try { echo (new App\Services\BillingAdjustmentService())->decide((int)($argv[2] ?? 0),'approve','Concurrent approval test'); }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
