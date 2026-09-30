<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportEnquiry extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'subject',
        'message',
        'status',
        'source',
        'qr_code_id',
        'customer_session_id',
        'ip_address',
        'user_agent',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CustomerSession::class, 'customer_session_id');
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return in_array($status, self::STATUSES, true) ? $query->where('status', $status) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(fn (Builder $builder) => $builder
            ->where('name', 'like', $like)
            ->orWhere('mobile', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->orWhere('subject', 'like', $like)
            ->orWhere('message', 'like', $like));
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }
}
