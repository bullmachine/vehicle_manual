<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'mobile', 'last_accessed_at'];

    protected function casts(): array
    {
        return ['last_accessed_at' => 'datetime'];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CustomerSession::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(ManualAccessLog::class);
    }
}
