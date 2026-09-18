<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Groups extends Model
{
    use HasFactory;
    protected $table = 'groups';
    protected $fillable = ['name', 'describe', 'invite_token', 'is_system'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'group_id');
    }
}
