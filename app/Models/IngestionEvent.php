<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['status' => 'queued'];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'api_source_id' => 'integer', 'branch_id' => 'integer'];
    }

    public function source()
    {
        return $this->belongsTo(ApiSource::class, 'api_source_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
