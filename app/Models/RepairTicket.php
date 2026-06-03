<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RepairTicket extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_WAITING_PARTS = 'WAITING_PARTS';
    public const STATUS_OUTSOURCED = 'OUTSOURCED';
    public const STATUS_REPAIRED = 'REPAIRED';
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'hospital_id', 'ticket_no', 'equipment_id', 'location_id',
        'reported_at', 'reported_by', 'reporter_name', 'symptom', 'urgency', 'status',
        'sla_due_at',
        'assigned_to', 'acknowledged_at', 'started_at', 'completed_at', 'closed_at',
        'root_cause', 'action_taken', 'parts_used', 'repair_cost',
        'vendor_name', 'outsource_ref', 'outsourced_at', 'expected_return_at',
        'verified_at', 'verified_by',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'outsourced_at' => 'datetime',
        'expected_return_at' => 'datetime',
        'verified_at' => 'datetime',
        'repair_cost' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'urgency', 'assigned_to', 'symptom', 'action_taken', 'vendor_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(RepairProgressLog::class)->orderBy('changed_at');
    }
}
