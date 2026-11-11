<?php

namespace Webkul\Admin\Http\Controllers\Activity;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Activity\Repositories\FileRepository;
use Webkul\Admin\DataGrids\Activity\ActivityDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\ActivityResource;
use Webkul\Admin\Models\Worker;
use Webkul\Admin\Models\WorkerTimeOff;
use Webkul\Attribute\Repositories\AttributeRepository;

class ActivityController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ActivityRepository $activityRepository,
        protected FileRepository $fileRepository,
        protected AttributeRepository $attributeRepository,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('admin::activities.index');
    }

    /**
     * Returns a listing of the resource.
     */
    public function get(): JsonResponse
    {
        if (! request()->has('view_type')) {
            return datagrid(ActivityDataGrid::class)->process();
        }

        $startDate = request()->get('startDate')
            ? Carbon::createFromTimeString(request()->get('startDate').' 00:00:01')
            : Carbon::now()->startOfWeek()->format('Y-m-d H:i:s');

        $endDate = request()->get('endDate')
            ? Carbon::createFromTimeString(request()->get('endDate').' 23:59:59')
            : Carbon::now()->endOfWeek()->format('Y-m-d H:i:s');

        $activities = $this->activityRepository->getActivities([$startDate, $endDate]);
        $workerAssignments = DB::table('activity_workers')
            ->join('workers', 'workers.id', '=', 'activity_workers.worker_id')
            ->whereIn('activity_workers.activity_id', $activities->pluck('id'))
            ->get([
                'activity_workers.activity_id',
                'workers.id',
                'workers.name',
                'workers.calendar_color',
            ])
            ->groupBy('activity_id');

        foreach ($activities as $activity) {
            $activity->setAttribute(
                'workers',
                ($workerAssignments[$activity->id] ?? collect())
                    ->map(fn ($worker) => [
                        'id' => $worker->id,
                        'name' => $worker->name,
                        'color' => $worker->calendar_color,
                    ])
                    ->values()
                    ->all()
            );
        }

        return response()->json([
            'activities' => $activities->toArray(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse|JsonResponse
    {
        $this->validate(request(), [
            'type' => 'required',
            'comment' => 'required_if:type,note',
            'schedule_from' => 'required_unless:type,note,file',
            'schedule_to' => ['required_unless:type,note,file', 'nullable', 'after:schedule_from'],
            'file' => 'required_if:type,file',
            'worker_ids' => ['nullable', 'array'],
            'worker_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('workers', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);
        $workerIds = array_map('intval', (array) request()->input('worker_ids', []));
        $activityData = request()->except(['worker_ids', 'worker_assignment_present']);

        if (request('type') === 'meeting') {
            /**
             * Check if meeting is overlapping with other meetings.
             */
            $isOverlapping = $this->activityRepository->isDurationOverlapping(
                request()->input('schedule_from'),
                request()->input('schedule_to'),
                request()->input('participants'),
                request()->input('id')
            );

            if ($isOverlapping) {
                if (request()->ajax()) {
                    return response()->json([
                        'message' => trans('admin::app.activities.overlapping-error'),
                    ], 400);
                }

                session()->flash('success', trans('admin::app.activities.overlapping-error'));

                return redirect()->back();
            }
        }

        if (
            $workerIds
            && request()->filled('schedule_from')
            && request()->filled('schedule_to')
            && $this->hasWorkerOverlap(
                $workerIds,
                request()->input('schedule_from'),
                request()->input('schedule_to')
            )
        ) {
            return $this->workerOverlapResponse();
        }

        Event::dispatch('activity.create.before');

        $activity = DB::transaction(function () use ($activityData, $workerIds) {
            $activity = $this->activityRepository->create(array_merge($activityData, [
                'is_done' => request('type') == 'note' ? 1 : 0,
                'user_id' => auth()->guard('user')->user()->id,
                'worker_id' => $workerIds[0] ?? null,
            ]));

            $activity->workers()->sync($workerIds);

            return $activity;
        });
        $activity->load('workers');

        Event::dispatch('activity.create.after', $activity);

        if (request()->ajax()) {
            return response()->json([
                'data' => new ActivityResource($activity),
                'message' => trans('admin::app.activities.create-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.activities.create-success'));

        return redirect()->back();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $activity = $this->activityRepository->findOrFail($id);
        $activity->load('workers');

        $leadId = old('lead_id') ?? optional($activity->leads()->first())->id;

        $lookUpEntityData = $this->attributeRepository->getLookUpEntity('leads', $leadId);
        $workers = Worker::query()
            ->where('is_active', true)
            ->orWhereIn('id', $activity->workers->pluck('id'))
            ->orderBy('name')
            ->get();
        $selectedWorkerId = old('worker_ids.0') ?? $activity->workers->first()?->id;
        $isCalcomBooking = $this->isCalcomBooking($activity);
        $canAssignCalcomWorker = ! in_array(
            data_get($this->additionalData($activity), 'calcom.status'),
            ['cancelled', 'rejected'],
            true
        );
        $isWorkerTask = data_get($this->additionalData($activity), 'txn.kind') === 'worker_task';

        return view('admin::activities.edit', compact('activity', 'lookUpEntityData', 'workers', 'selectedWorkerId', 'isCalcomBooking', 'canAssignCalcomWorker', 'isWorkerTask'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update($id): RedirectResponse|JsonResponse
    {
        Event::dispatch('activity.update.before', $id);
        $existingActivity = $this->activityRepository->findOrFail($id);
        $existingActivity->load('workers');

        $this->validate(request(), [
            'worker_ids' => ['nullable', 'array'],
            'worker_ids.*' => ['integer', 'distinct', Rule::exists('workers', 'id')],
        ]);
        $shouldSyncWorkers = request()->exists('worker_assignment_present')
            || request()->exists('worker_ids');
        $workerIds = array_map('intval', (array) request()->input('worker_ids', []));
        $data = request()->except(['worker_ids', 'worker_assignment_present']);

        if ($this->isCalcomBooking($existingActivity)) {
            return $this->updateCalcomWorkerAssignment($id, $existingActivity, $workerIds, $shouldSyncWorkers);
        }

        $scheduleFrom = $data['schedule_from']
            ?? $existingActivity->schedule_from?->format('Y-m-d H:i:s');
        $scheduleTo = $data['schedule_to']
            ?? $existingActivity->schedule_to?->format('Y-m-d H:i:s');

        if (filled($scheduleFrom) && filled($scheduleTo) && strtotime($scheduleTo) <= strtotime($scheduleFrom)) {
            throw ValidationException::withMessages([
                'schedule_to' => 'The end must be after the start.',
            ]);
        }

        // A time change is checked against the workers the activity keeps.
        $timeChanged = array_key_exists('schedule_from', $data) || array_key_exists('schedule_to', $data);
        $overlapWorkerIds = $shouldSyncWorkers
            ? $workerIds
            : array_values(array_unique(array_filter(array_map('intval', [
                ...$existingActivity->workers->pluck('id')->all(),
                $existingActivity->worker_id,
            ]))));

        if (
            ($shouldSyncWorkers || $timeChanged)
            && $overlapWorkerIds
            && filled($scheduleFrom)
            && filled($scheduleTo)
            && $this->hasWorkerOverlap($overlapWorkerIds, $scheduleFrom, $scheduleTo, $id)
        ) {
            return $this->workerOverlapResponse();
        }

        $newWorkerIds = array_diff($workerIds, $existingActivity->workers->pluck('id')->all());

        if (
            $shouldSyncWorkers
            && Worker::query()
                ->whereIn('id', $newWorkerIds)
                ->where('is_active', false)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'worker_ids' => 'Inactive workers cannot be newly assigned to an activity.',
            ]);
        }

        if ($shouldSyncWorkers) {
            $data['worker_id'] = $workerIds[0] ?? null;
        }

        $activity = DB::transaction(function () use ($data, $id, $shouldSyncWorkers, $workerIds) {
            $activity = $this->activityRepository->update($data, $id);

            if ($shouldSyncWorkers) {
                $activity->workers()->sync($workerIds);
            }

            return $activity;
        });

        $activity->load('workers');

        /**
         * We will not use `empty` directly here because `lead_id` can be a blank string
         * from the activity form. However, on the activity view page, we are only updating the
         * `is_done` field, so `lead_id` will not be present in that case.
         */
        if (isset($data['lead_id'])) {
            $activity->leads()->sync(
                ! empty($data['lead_id'])
                    ? [$data['lead_id']]
                    : []
            );
        }

        Event::dispatch('activity.update.after', $activity);

        if (request()->ajax()) {
            return response()->json([
                'data' => new ActivityResource($activity),
                'message' => trans('admin::app.activities.update-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.activities.update-success'));

        return redirect()->route('admin.activities.index');
    }

    private function updateCalcomWorkerAssignment(
        int $id,
        $activity,
        array $workerIds,
        bool $shouldSyncWorkers
    ): RedirectResponse|JsonResponse {
        if (! $shouldSyncWorkers) {
            throw ValidationException::withMessages([
                'worker_ids' => 'Only the worker of a Cal.com booking can be changed here. '.self::CALCOM_CANCEL_MESSAGE,
            ]);
        }

        if (in_array(data_get($this->additionalData($activity), 'calcom.status'), ['cancelled', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'worker_ids' => 'Cancelled or rejected Cal.com bookings cannot be assigned to a worker.',
            ]);
        }

        if (count($workerIds) !== 1) {
            throw ValidationException::withMessages([
                'worker_ids' => 'Choose exactly one worker for this Cal.com booking.',
            ]);
        }

        $unexpectedFields = array_keys(request()->except(['_token', '_method', 'worker_ids', 'worker_assignment_present']));

        if ($unexpectedFields) {
            throw ValidationException::withMessages([
                'activity' => 'Only the worker of a Cal.com booking can be changed here. '.self::CALCOM_CANCEL_MESSAGE,
            ]);
        }

        if (
            $workerIds
            && filled($activity->schedule_from)
            && filled($activity->schedule_to)
            && $this->hasWorkerOverlap(
                $workerIds,
                $activity->schedule_from->format('Y-m-d H:i:s'),
                $activity->schedule_to->format('Y-m-d H:i:s'),
                $id
            )
        ) {
            return $this->workerOverlapResponse();
        }

        $newWorkerIds = array_diff($workerIds, $activity->workers->pluck('id')->all());

        if (
            Worker::query()
                ->whereIn('id', $newWorkerIds)
                ->where('is_active', false)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'worker_ids' => 'Inactive workers cannot be newly assigned to an activity.',
            ]);
        }

        if (
            $newWorkerIds
            && filled($activity->schedule_from)
            && filled($activity->schedule_to)
            && $this->hasWorkerTimeOff(
                $newWorkerIds,
                $activity->schedule_from->format('Y-m-d H:i:s'),
                $activity->schedule_to->format('Y-m-d H:i:s')
            )
        ) {
            throw ValidationException::withMessages([
                'worker_ids' => 'This worker has time off during this appointment.',
            ]);
        }

        $additional = $this->additionalData($activity);
        $calcom = is_array($additional['calcom'] ?? null) ? $additional['calcom'] : [];
        $calcom['worker_override_ids'] = $workerIds;
        $additional['calcom'] = $calcom;

        $activity = DB::transaction(function () use ($activity, $workerIds, $additional) {
            $activity = $this->activityRepository->update([
                'worker_id' => $workerIds[0] ?? null,
                'additional' => json_encode($additional),
            ], $activity->id);

            $activity->workers()->sync($workerIds);

            return $activity;
        });

        $activity->load('workers');
        Event::dispatch('activity.update.after', $activity);

        if (request()->ajax()) {
            return response()->json([
                'data' => new ActivityResource($activity),
                'message' => 'Worker assignment updated. The customer booking was not changed or notified.',
            ]);
        }

        return redirect()
            ->route('admin.activities.edit', $activity->id)
            ->with('success', 'Worker assignment updated. The customer booking was not changed or notified.');
    }

    /** Shown whenever someone tries to cancel, delete or close a Cal.com visit in Krayin. */
    public const CALCOM_CANCEL_MESSAGE = 'Customer appointments can only be cancelled or rescheduled in Cal.com: '
        .'the customer uses the Cancel or Reschedule link in their Cal.com confirmation email, '
        .'or staff cancel it under Bookings in Cal.com. Then click "Sync Cal.com bookings" to update Krayin.';

    /** The same for toasts, which don't wrap long text. */
    private const CALCOM_CANCEL_TOAST = 'Cal.com bookings are cancelled in Cal.com, not in Krayin. Then click "Sync Cal.com bookings".';

    /**
     * Customer appointments: real Cal.com bookings and seeded customer visits.
     * Only the customer reschedules these, so Krayin may only reassign the worker.
     */
    private function isCalcomBooking($activity): bool
    {
        $additional = $this->additionalData($activity);

        return filled(data_get($additional, 'calcom.uid'))
            || data_get($additional, 'txn.kind') === 'visit';
    }

    private function additionalData($activity): array
    {
        $additional = $activity->additional;

        if (is_array($additional)) {
            return $additional;
        }

        if (! is_string($additional) || $additional === '') {
            return [];
        }

        $decoded = json_decode($additional, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function hasWorkerOverlap(
        array $workerIds,
        string $scheduleFrom,
        string $scheduleTo,
        ?int $ignoreActivityId = null
    ): bool {
        $query = DB::table('activities')
            ->where('activities.is_done', false)
            ->where('activities.schedule_from', '<', $scheduleTo)
            ->where('activities.schedule_to', '>', $scheduleFrom)
            ->where(fn ($query) => $query
                ->whereIn('activities.worker_id', $workerIds)
                ->orWhereExists(fn ($pivot) => $pivot
                    ->select(DB::raw(1))
                    ->from('activity_workers')
                    ->whereColumn('activity_workers.activity_id', 'activities.id')
                    ->whereIn('activity_workers.worker_id', $workerIds)));

        if ($ignoreActivityId !== null) {
            $query->where('activities.id', '!=', $ignoreActivityId);
        }

        return $query->exists();
    }

    /** Whole-day time off on any date of the range, or a part-day entry that overlaps it. */
    private function hasWorkerTimeOff(array $workerIds, string $scheduleFrom, string $scheduleTo): bool
    {
        return WorkerTimeOff::query()
            ->whereIn('worker_id', $workerIds)
            ->whereDate('start_date', '<=', substr($scheduleTo, 0, 10))
            ->whereDate('end_date', '>=', substr($scheduleFrom, 0, 10))
            ->get()
            ->contains(fn (WorkerTimeOff $entry) => ! $entry->start_time
                || ! $entry->end_time
                || ($entry->start_date->format('Y-m-d').' '.$entry->start_time < $scheduleTo
                    && $scheduleFrom < $entry->start_date->format('Y-m-d').' '.$entry->end_time));
    }

    private function workerOverlapResponse(): never
    {
        $message = 'One or more selected workers already have an activity scheduled during this time.';

        throw ValidationException::withMessages(['worker_ids' => $message]);
    }

    /**
     * Mass Update the specified resources.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $activities = $this->activityRepository->findWhereIn('id', $massUpdateRequest->input('indices'));

        if ($activities->contains(fn ($activity) => $this->isCalcomBooking($activity))) {
            return response()->json([
                'message' => self::CALCOM_CANCEL_TOAST,
            ], 422);
        }

        foreach ($activities as $activity) {
            Event::dispatch('activity.update.before', $activity->id);

            $activity = $this->activityRepository->update([
                'is_done' => $massUpdateRequest->input('value'),
            ], $activity->id);

            Event::dispatch('activity.update.after', $activity);
        }

        return response()->json([
            'message' => trans('admin::app.activities.mass-update-success'),
        ]);
    }

    /**
     * Download file from storage.
     */
    public function download(int $id): StreamedResponse
    {
        try {
            $file = $this->fileRepository->findOrFail($id);

            return Storage::download($file->path);
        } catch (\Exception $exception) {
            abort(404);
        }
    }

    /*
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $activity = $this->activityRepository->findOrFail($id);

        if ($this->isCalcomBooking($activity)) {
            return response()->json([
                'message' => self::CALCOM_CANCEL_TOAST,
            ], 422);
        }

        try {
            Event::dispatch('activity.delete.before', $id);

            $activity?->delete($id);

            Event::dispatch('activity.delete.after', $id);

            return response()->json([
                'message' => trans('admin::app.activities.destroy-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.activities.destroy-failed'),
            ], 400);
        }
    }

    /**
     * Mass Delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $activities = $this->activityRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        if ($activities->contains(fn ($activity) => $this->isCalcomBooking($activity))) {
            return response()->json([
                'message' => self::CALCOM_CANCEL_TOAST,
            ], 422);
        }

        try {
            foreach ($activities as $activity) {
                Event::dispatch('activity.delete.before', $activity->id);

                $this->activityRepository->delete($activity->id);

                Event::dispatch('activity.delete.after', $activity->id);
            }

            return response()->json([
                'message' => trans('admin::app.activities.mass-destroy-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.activities.mass-delete-failed'),
            ], 400);
        }
    }
}
