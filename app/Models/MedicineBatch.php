<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the existing `medicine_batches` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 22 at audit time.
 * $timestamps = false (created_at).
 *
 * @method Medicine medicine()  belongsTo medicines (medicine_id)
 * @method Supplier supplier()  belongsTo suppliers (supplier_id)
 * @method Illuminate\Database\Eloquent\Collection|DispenseLog[] dispenseLogs() hasMany dispense_logs
 * @method Illuminate\Database\Eloquent\Collection|StockAdjustment[] stockAdjustments() hasMany stock_adjustments
 * @method Illuminate\Database\Eloquent\Collection|StockMovement[] stockMovements() hasMany stock_movements
 */
class MedicineBatch extends Model
{
    /** The table associated with the model. */
    protected $table = 'medicine_batches';

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
        'medicine_id',
        'batch_no',
        'lot_no',
        'supplier_id',
        'quantity',
        'expiry_date',
        'date_received',
        'unit_cost',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'date_received' => 'date',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** medicine_id -> medicines.id */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id');
    }

    /** supplier_id -> suppliers.id */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /** dispense_logs joins back through FK column 'batch_id'. */
    public function dispenseLogs(): HasMany
    {
        return $this->hasMany(DispenseLog::class, 'batch_id');
    }

    /** stock_adjustments joins back through FK column 'batch_id'. */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'batch_id');
    }

    /** stock_movements joins back through FK column 'batch_id'. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }
}
