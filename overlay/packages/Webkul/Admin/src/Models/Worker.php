<?php

namespace Webkul\Admin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Activity\Models\ActivityProxy;

class Worker extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'notes',
        'is_active',
        'start_address',
        'home_latitude',
        'home_longitude',
        'work_hours',
        'priority',
        'services',
        'max_visits_per_day',
        'calendar_color',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'work_hours' => 'array',
        'services' => 'array',
        'home_latitude' => 'float',
        'home_longitude' => 'float',
    ];

    public function timeOffs(): HasMany
    {
        return $this->hasMany(WorkerTimeOff::class)->orderBy('start_date');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(ActivityProxy::modelClass(), 'activity_workers');
    }

    /**
     * Activities the worker is assigned to (pivot, or the older single
     * worker_id column) that overlap [$from, $to). Times are wall times.
     * A missing end, or one not after the start, counts as starting in range.
     */
    public function activitiesBetween(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $fromValue = $from->format('Y-m-d H:i:s');
        $toValue = $to->format('Y-m-d H:i:s');

        return ActivityProxy::modelClass()::query()
            ->where(fn ($query) => $query
                ->where('activities.worker_id', $this->id)
                ->orWhereExists(fn ($pivot) => $pivot
                    ->select(DB::raw(1))
                    ->from('activity_workers')
                    ->whereColumn('activity_workers.activity_id', 'activities.id')
                    ->where('activity_workers.worker_id', $this->id)))
            ->where('activities.schedule_from', '<', $toValue)
            ->where(fn ($query) => $query
                ->where('activities.schedule_to', '>', $fromValue)
                ->orWhere(fn ($query) => $query
                    ->where(fn ($query) => $query
                        ->whereNull('activities.schedule_to')
                        ->orWhereColumn('activities.schedule_to', '<=', 'activities.schedule_from'))
                    ->where('activities.schedule_from', '>=', $fromValue)));
    }
}
