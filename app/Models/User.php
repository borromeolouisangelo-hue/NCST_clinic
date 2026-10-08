<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Eloquent model for the existing `users` table.
 *
 * Maps the LIVE school_clinic schema (read-first). Laravel owns no migrations
 * for this project yet - the table was created by database.sql.
 *
 * PK: id (auto-increment) | rows: 4 at audit time.
 * $timestamps = false (created_at, updated_at come from the MySQL column
 * defaults, exactly like the original's INSERT/UPDATE statements).
 *
 * role: 'user','staff','admin'
 * account_type: 'student','employee'
 * status: 'active','inactive'
 *
 * @method Illuminate\Database\Eloquent\Collection|AuditLog[] auditLogs() hasMany audit_logs
 * @method EmployeeDetail employeeDetail() hasOne employee_details
 * @method Illuminate\Database\Eloquent\Collection|MedicalRecord[] medicalRecords() hasMany medical_records
 * @method Illuminate\Database\Eloquent\Collection|Notification[] notifications() hasMany notifications
 * @method Illuminate\Database\Eloquent\Collection|NotificationQueue[] notificationQueue() hasMany notification_queue
 * @method Illuminate\Database\Eloquent\Collection|NotificationSetting[] notificationSettings() hasMany notification_settings
 * @method Profile profile() hasOne profiles
 * @method Illuminate\Database\Eloquent\Collection|StockAdjustment[] stockAdjustments() hasMany stock_adjustments
 * @method Illuminate\Database\Eloquent\Collection|StockMovement[] stockMovements() hasMany stock_movements
 * @method StudentDetail studentDetail() hasOne student_details
 * @method Illuminate\Database\Eloquent\Collection|UserAllergy[] userAllergies() hasMany user_allergies
 */
class User extends Authenticatable
{
    /** The table associated with the model. */
    protected $table = 'users';

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
     * The credential column is `password_hash` (bcrypt $2y$10$...), not the
     * framework default `password`.
     *
     * getAuthPasswordName() reads this property, so any framework code that
     * touches the password column (e.g. Auth::attempt rehashing) uses the live
     * schema instead of assuming a column this table does not have. Phase 5
     * verifies credentials with Hash::check() + Auth::login() so the existing
     * hashes are never rewritten.
     */
    protected $authPasswordName = 'password_hash';

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
        'username',
        'email',
        'password_hash',
        'role',
        'account_type',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
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
            'updated_at' => 'datetime',
        ];
    }

    /** audit_logs joins back through FK column 'user_id'. */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    /** employee_details joins back through FK column 'user_id'. */
    public function employeeDetail(): HasOne
    {
        return $this->hasOne(EmployeeDetail::class, 'user_id');
    }

    /** medical_records joins back through FK column 'user_id'. */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'user_id');
    }

    /** notifications joins back through FK column 'user_id'. */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    /** notification_queue joins back through FK column 'user_id'. */
    public function notificationQueue(): HasMany
    {
        return $this->hasMany(NotificationQueue::class, 'user_id');
    }

    /** notification_settings joins back through FK column 'user_id'. */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class, 'user_id');
    }

    /** profiles joins back through FK column 'user_id'. */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    /** stock_adjustments joins back through FK column 'performed_by'. */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'performed_by');
    }

    /** stock_movements joins back through FK column 'performed_by'. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'performed_by');
    }

    /** student_details joins back through FK column 'user_id'. */
    public function studentDetail(): HasOne
    {
        return $this->hasOne(StudentDetail::class, 'user_id');
    }

    /** user_allergies joins back through FK column 'user_id'. */
    public function userAllergies(): HasMany
    {
        return $this->hasMany(UserAllergy::class, 'user_id');
    }
}
