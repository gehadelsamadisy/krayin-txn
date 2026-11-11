<?php

namespace Webkul\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerScheduleSetting extends Model
{
    protected $table = 'worker_schedule_settings';

    protected $fillable = [
        'id',
        'default_work_hours',
        'holidays',
    ];

    protected $casts = [
        'default_work_hours' => 'array',
        'holidays' => 'array',
    ];
}
