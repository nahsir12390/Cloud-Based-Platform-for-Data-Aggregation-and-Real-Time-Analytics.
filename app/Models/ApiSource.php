<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiSource extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function events()
    {
        return $this->hasMany(IngestionEvent::class);
    }
}
