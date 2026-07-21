<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetlistEntry extends Model
{
    protected $primaryKey = 'uniqueid';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'artistid' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'transition' => 'integer',
            'gap' => 'integer',
            'isjam' => 'boolean',
            'isreprise' => 'boolean',
            'isjamchart' => 'boolean',
            'is_original' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, 'showid', 'showid');
    }
}
