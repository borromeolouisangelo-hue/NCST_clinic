<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the existing `equipment_loans` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 4 at audit time.
 * $timestamps = false (created_at).
 *
 * status: 'borrowed','returned'
 *
 * @method Equipment equipment()  belongsTo equipment (equipment_id)
 * @method User issuedBy()  belongsTo users (issued_by)
 * @method User receivedBy()  belongsTo users (received_by)
 * @method User user()  belongsTo users (user_id)
 * @method Illuminate\Database\Eloquent\Collection|Notification[] notifications() hasMany notifications
 */
class EquipmentLoan extends Model
{
    /** The table associated with the model. */
    protected $table = 'equipment_loans';

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
        'equipment_id',
        'quantity',
        'borrow_date',
        'borrowed_at',
        'due_date',
        'return_date',
        'status',
        'condition_on_return',
        'issued_by',
        'received_by',
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
            'borrow_date' => 'date',
            'borrowed_at' => 'datetime',
            'due_date' => 'date',
            'return_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** equipment_id -> equipment.id */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /** issued_by -> users.id */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** received_by -> users.id */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** user_id -> users.id */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** notifications joins back through FK column 'loan_id'. */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'loan_id');
    }
}
