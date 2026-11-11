<?php

namespace Webkul\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerTimeOff extends Model
{
    protected $fillable = [
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'type',
        'note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function worker()
    {
        return $this->belongsTo(Worker::class);
    }
}
