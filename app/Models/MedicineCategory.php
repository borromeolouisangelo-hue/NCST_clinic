<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the existing `medicine_categories` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 11 at audit time.
 * $timestamps = false (created_at).
 *
 * type: 'medicine','first_aid'
 *
 * @method MedicineCategory parent()  belongsTo medicine_categories (parent_id)
 * @method Illuminate\Database\Eloquent\Collection|Medicine[] medicines() hasMany medicines
 */
class MedicineCategory extends Model
{
    /** The table associated with the model. */
    protected $table = 'medicine_categories';

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
        'parent_id',
        'name',
        'type',
        'description',
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

    /** parent_id -> medicine_categories.id */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'parent_id');
    }

    /** medicines joins back through FK column 'category_id'. */
    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class, 'category_id');
    }
}
