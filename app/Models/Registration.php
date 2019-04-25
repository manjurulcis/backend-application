<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key',
        'email',
        'expires_at',
    ];

    protected $dates = [
        'expires_at',
    ];
}