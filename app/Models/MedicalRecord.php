<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the existing `medical_records` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 1 at audit time.
 * $timestamps = false (updated_at).
 *
 * semester: '1st','2nd','summer'
 *
 * @method User user()  belongsTo users (user_id)
 */
class MedicalRecord extends Model
{
    /** The table associated with the model. */
    protected $table = 'medical_records';

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
        'blood_type',
        'height_cm',
        'weight_kg',
        'allergies_text',
        'existing_conditions',
        'current_medications',
        'immunizations',
        'current_symptoms',
        'school_year',
        'semester',
        'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'submitted_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** user_id -> users.id */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
