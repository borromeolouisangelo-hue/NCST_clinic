<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the existing `medicines` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 21 at audit time.
 * $timestamps = false (created_at, updated_at come from the MySQL column
 * defaults, exactly like the original's INSERT/UPDATE statements).
 *
 * @method MedicineCategory category()  belongsTo medicine_categories (category_id)
 * @method Illuminate\Database\Eloquent\Collection|DispenseLog[] dispenseLogs() hasMany dispense_logs
 * @method Illuminate\Database\Eloquent\Collection|MedicineBatch[] medicineBatches() hasMany medicine_batches
 * @method Illuminate\Database\Eloquent\Collection|MedicineRequest[] medicineRequests() hasMany medicine_requests
 * @method Illuminate\Database\Eloquent\Collection|StockAdjustment[] stockAdjustments() hasMany stock_adjustments
 * @method Illuminate\Database\Eloquent\Collection|StockMovement[] stockMovements() hasMany stock_movements
 */
class Medicine extends Model
{
    /** The table associated with the model. */
    protected $table = 'medicines';

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
        'name',
        'generic_name',
        'category_id',
        'unit',
        'description',
        'low_stock_threshold',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** category_id -> medicine_categories.id */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'category_id');
    }

    /** dispense_logs joins back through FK column 'medicine_id'. */
    public function dispenseLogs(): HasMany
    {
        return $this->hasMany(DispenseLog::class, 'medicine_id');
    }

    /** medicine_batches joins back through FK column 'medicine_id'. */
    public function medicineBatches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class, 'medicine_id');
    }

    /** medicine_requests joins back through FK column 'medicine_id'. */
    public function medicineRequests(): HasMany
    {
        return $this->hasMany(MedicineRequest::class, 'medicine_id');
    }

    /** stock_adjustments joins back through FK column 'medicine_id'. */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'medicine_id');
    }

    /** stock_movements joins back through FK column 'medicine_id'. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'medicine_id');
    }
}
