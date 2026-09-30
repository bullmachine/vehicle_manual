<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualFeedback extends Model
{
    use HasFactory;

    protected $fillable = ['manual_id', 'customer_session_id', 'rating', 'issue_type', 'comment'];

    public function manual(): BelongsTo
    {
        return $this->belongsTo(Manual::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CustomerSession::class, 'customer_session_id');
    }
}
