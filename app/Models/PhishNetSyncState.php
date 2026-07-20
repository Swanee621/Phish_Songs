<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhishNetSyncState extends Model
{
    protected $table = 'phishnet_sync_states';

    protected $guarded = [];

    protected $attributes = [
        'row_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'checked_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
