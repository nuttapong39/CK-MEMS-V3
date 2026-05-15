<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, LogsActivity;

    protected $fillable = [
        'hospital_id',
        'department_id',
        'employee_code',
        'name',
        'username',
        'full_name',
        'email',
        'phone',
        'provider_id',
        'avatar_path',
        'is_active',
        'last_login_at',
        'password',
        'moph_client_key',
        'moph_secret_key',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'moph_secret_key',   // ไม่ expose ใน API response โดยตรง
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'hospital_id' => $this->hospital_id,
            'roles' => $this->getRoleNames()->all(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_code', 'full_name', 'email', 'is_active', 'department_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'responsible_user_id');
    }

    public function repairTickets(): HasMany
    {
        return $this->hasMany(RepairTicket::class, 'reported_by');
    }
}
