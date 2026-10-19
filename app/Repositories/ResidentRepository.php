<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

/** FN-02.04 - bảng residents (chủ hộ / khách thuê). */
class ResidentRepository extends BaseRepository
{
    protected string $table = 'residents';

    public function listByApartment(int $apartmentId): array
    {
        return $this->fetchAllQuery(
            'SELECT * FROM residents WHERE apartment_id = :id ORDER BY is_active DESC, resident_role, full_name',
            ['id' => $apartmentId]
        );
    }
}
