<?php

declare(strict_types=1);

namespace Modules\Master\Models;

use Modules\Master\Models\MasterModel;

class DoctorModel extends MasterModel
{
    protected $table = 'doctors';

    protected $allowedFields = [
        'name',
        'sip_number',
        'specialization',
        'phone',
        'address',
        'is_active',
        'notes',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Opsi aktif untuk dropdown (value = name, label = name).
     */
    public function getOptions(): array
    {
        return $this->select('name AS value, name AS label')
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
