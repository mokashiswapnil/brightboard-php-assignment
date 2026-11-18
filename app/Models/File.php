<?php

// app/Models/File.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    // Fixed Mass Assignment vulnerability: Whitelist fillable attributes instead of unguarded []
    protected $fillable = [
        'account_id',
        'owner_id',
        'external_id',
        'name',
        'is_shared',
    ];

    protected $casts = [
        'is_shared' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'file_id');
    }
}
