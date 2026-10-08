<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the existing `user_allergies` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 0 at audit time.
 * $timestamps = false (created_at).
 *
 * severity: 'mild','moderate','severe'
 *
 * @method User user()  belongsTo users (user_id)
 */
class UserAllergy extends Model
{
    /** The table associated with the model. */
    protected $table = 'user_allergies';

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
        'medicine_id',
        'category_id',
        'allergen_name',
        'severity',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** user_id -> users.id */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
