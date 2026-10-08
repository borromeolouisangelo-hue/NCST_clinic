<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the existing `profiles` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 4 at audit time.
 * $timestamps = false (created_at, updated_at come from the MySQL column
 * defaults, exactly like the original's INSERT/UPDATE statements).
 *
 * gender: 'male','female','other'
 *
 * @method User user()  belongsTo users (user_id)
 */
class Profile extends Model
{
    /** The table associated with the model. */
    protected $table = 'profiles';

    /** The primary key for the model. */
    protected $primaryKey = 'id';

    /** Indicates if the IDs are auto-incrementing. */
    public $incrementing = true;

    /**
     * Both columns exist, but the original INSERT/UPDATE statements never
     * write them: created_at/updated_at are filled by MySQL's
     * DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP. $timestamps =
     * false keeps Eloquent from writing PHP-clock values instead, so the
     * stored wall time matches the reference app.
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
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'birthdate',
        'address',
        'contact_no',
        'emergency_name',
        'emergency_contact',
        'photo',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** user_id -> users.id */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
