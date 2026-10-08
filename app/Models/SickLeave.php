<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the existing `sick_leaves` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 2 at audit time.
 * $timestamps = false (created_at).
 *
 * status: 'pending','approved','rejected'
 *
 * @method User reviewedBy()  belongsTo users (reviewed_by)
 * @method User user()  belongsTo users (user_id)
 */
class SickLeave extends Model
{
    /** The table associated with the model. */
    protected $table = 'sick_leaves';

    /** The primary key for the model. */
    protected $primaryKey = 'id';

    /** Indicates if the IDs are auto-incrementing. */
    public $incrementing = true;

    /**
     * This table does NOT have both timestamp columns, so Eloquent must not
     * write them. Where created_at/updated_at exist they carry
     * DEFAULT CURRENT_TIMESTAMP (and ON UPDATE CURRENT_TIMESTAMP), which the
     * database applies itself - matching the behaviour of the original app.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * id is excluded (auto-increment PK); timestamp columns are excluded
     * because the database defaults own them. Controllers must only ever pass
     * validated data here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'reason',
        'start_date',
        'end_date',
        'status',
        'attachment',
        'reviewed_by',
        'review_note',
        'reviewed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'created_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** reviewed_by -> users.id */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** user_id -> users.id */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
