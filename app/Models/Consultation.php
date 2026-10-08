<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the existing `consultations` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 0 at audit time.
 * $timestamps = false (no timestamp columns).
 *
 * @method User patient()  belongsTo users (patient_id)
 * @method User staff()  belongsTo users (staff_id)
 */
class Consultation extends Model
{
    /** The table associated with the model. */
    protected $table = 'consultations';

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
        'patient_id',
        'staff_id',
        'complaint',
        'blood_pressure',
        'temperature',
        'pulse',
        'respiration',
        'weight_kg',
        'height_cm',
        'diagnosis',
        'treatment',
        'notes',
        'consultation_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:1',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'consultation_date' => 'datetime',
        ];
    }

    /** patient_id -> users.id */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /** staff_id -> users.id */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
