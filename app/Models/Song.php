<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    protected $primaryKey = 'songid';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
        'times_played' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_played' => 'integer',
            'gap' => 'integer',
        ];
    }
}
