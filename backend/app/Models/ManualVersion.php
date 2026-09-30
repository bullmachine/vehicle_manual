<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'manual_id',
        'version',
        'effective_date',
        'description',
        'file_disk',
        'file_path',
        'search_text',
        'original_name',
        'mime_type',
        'file_size',
        'checksum',
        'page_count',
        'status',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function manual(): BelongsTo
    {
        return $this->belongsTo(Manual::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
