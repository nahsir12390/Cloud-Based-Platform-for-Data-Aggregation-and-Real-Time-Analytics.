<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $guarded = [];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
