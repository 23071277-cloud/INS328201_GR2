<?php
declare(strict_types=1);
$database=$argv[1]??'';
if (!preg_match('/^energy_dss_fn08_context_test_[a-z0-9_]+$/',$database)) exit(2);
putenv('DB_DATABASE='.$database);
require dirname(__DIR__,2).'/bootstrap.php';
restore_error_handler(); restore_exception_handler();
$_SESSION=['user_id'=>1,'user_role'=>'bql','username'=>'test_bql','full_name'=>'Fixture'];
try { echo json_encode((new App\Services\RecommendationService())->generateForAlert((int)($argv[2]??0))); }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); }
