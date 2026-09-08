<?php

namespace App\Models;

use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory;

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
