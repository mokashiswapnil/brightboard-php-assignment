<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * Replaced dangerous guarded = [] with strict fillable whitelist.
     */
    protected $fillable = [
        'account_id',
        'owner_id',
        'external_id',
        'name',
        'is_shared',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_shared' => 'boolean',
    ];

    /**
     * Relationship to the file owner.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Relationship to file tags.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'file_id');
    }
}
