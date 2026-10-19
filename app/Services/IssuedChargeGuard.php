<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;

final class IssuedChargeGuard
{
    public static function protected(int $id): bool
    {
        $db = Database::connection();
        $q = $db->prepare("SELECT COUNT(*) FROM apartment_charges c WHERE c.id=? AND
            (c.status='da_phat_hanh' OR c.issued_at IS NOT NULL OR EXISTS
            (SELECT 1 FROM email_notifications n WHERE n.apartment_charge_id=c.id
             AND n.notification_type='bill_notice' AND n.status IN ('sent','pending')))");
        $q->execute([$id]);
        return (int)$q->fetchColumn() > 0;
    }

    public static function assertMutable(int $id): void
    {
        if (self::protected($id)) {
            throw ValidationException::withMessage('Phiếu đã phát hành hoặc đang gửi: giữ nguyên bản gốc; dùng đề nghị đối soát / phí bổ sung.');
        }
    }
}
