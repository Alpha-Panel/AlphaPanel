<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebAuthn extends Model
{
    protected $table = 'webauthn_credentials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
    ];
}
