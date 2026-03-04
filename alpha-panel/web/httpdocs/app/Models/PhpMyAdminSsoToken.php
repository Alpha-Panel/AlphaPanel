<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhpMyAdminSsoToken extends Model
{
    protected $table = 'phpmyadmin_sso_tokens';
    protected $primaryKey = 'token';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'token',
        'mysql_user',
        'mysql_pass',
        'mysql_host',
        'mysql_port',
        'client_ip',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
