<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOperation extends Model
{
    protected $guarded = [];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expected_quantity' => 'integer', 'resulting_quantity' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function destination()
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
