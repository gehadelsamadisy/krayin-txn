<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        {{ $isCalcomBooking ? ($canAssignCalcomWorker ? 'Worker assignment' : 'Cal.com booking') : trans('admin::app.activities.edit.title') }}
    </x-slot>

    {!! view_render_event('admin.activities.edit.form.before') !!}

    <x-admin::form
        :action="route('admin.activities.update', $activity->id)"
        method="PUT"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    @unless ($isCalcomBooking)
                        <x-admin::breadcrumbs
                            name="activities.edit"
                            :entity="$activity"
                        />
                    @endunless

                    <!-- Page Title -->
                    <div class="text-xl font-bold dark:text-gray-300">
                        {{ $isCalcomBooking ? ($canAssignCalcomWorker ? 'Worker assignment' : 'Cal.com booking') : trans('admin::app.activities.edit.title') }}
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <!-- Create button for person -->
                    <div class="flex items-center gap-x-2.5">
                        @if (! $isCalcomBooking && bouncer()->hasPermission('activities.delete'))
                            <button
                                type="button"
                                class="secondary-button"
                                onclick="deleteWorkerActivity()"
                            >
                                Delete
                            </button>
                        @endif

                        @if (! $isCalcomBooking || $canAssignCalcomWorker)
                            {!! view_render_event('admin.activities.edit.save_button.before') !!}

                            <!-- Save Button -->
                            <button
                                type="submit"
                                class="primary-button"
                            >
                                {{ $isCalcomBooking ? 'Save worker assignment' : trans('admin::app.activities.edit.save-btn') }}
                            </button>

                            {!! view_render_event('admin.activities.edit.save_button.after') !!}
                        @endif
                    </div>
                </div>
            </div>

            <!-- Form Content -->
            <div class="flex gap-2.5 max-xl:flex-wrap-reverse">
                <!-- Left sub-component -->
                <div class="box-shadow flex-1 gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 max-xl:flex-auto">
                    {!! view_render_event('admin.activities.edit.form_controls.before') !!}

                    @if ($isCalcomBooking)
                        <div class="mb-4 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                            @if ($canAssignCalcomWorker)
                                This appointment is booked through Cal.com. Its time and customer details cannot be changed here.
                                Changing the worker does not change or notify the customer.
                                {{ \Webkul\Admin\Http\Controllers\Activity\ActivityController::CALCOM_CANCEL_MESSAGE }}
                            @else
                                This appointment is cancelled or rejected in Cal.com. Its worker assignment cannot be changed here.
                            @endif
                        </div>

                        <div class="mb-5 grid gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-medium text-gray-500">Scheduled from</p>
                                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $activity->schedule_from?->format('M j, Y g:i A') }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500">Scheduled to</p>
                                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $activity->schedule_to?->format('M j, Y g:i A') }}</p>
                            </div>
                        </div>
                    @else
                    <!-- Schedule Date -->
                    <x-admin::form.control-group>
                        <div class="flex gap-2 max-sm:flex-wrap">
                            <div class="w-full">
                                <x-admin::form.control-group.label class="required">
                                    @lang('admin::app.activities.edit.schedule_from')
                                </x-admin::form.control-group.label>

                                <x-admin::flat-picker.datetime class="!w-full" ::allow-input="true">
                                    <input
                                        name="schedule_from"
                                        value="{{ old('schedule_from') ?? $activity->schedule_from }}"
                                        class="flex w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                                        placeholder="@lang('admin::app.activities.edit.schedule_from')"
                                    />
                                </x-admin::flat-picker.datetime>
                            </div>

                            <div class="w-full">
                                <x-admin::form.control-group.label class="required">
                                    @lang('admin::app.activities.edit.schedule_to')
                                </x-admin::form.control-group.label>

                                <x-admin::flat-picker.datetime class="!w-full" ::allow-input="true">
                                    <input
                                        name="schedule_to"
                                        value="{{ old('schedule_to') ?? $activity->schedule_to }}"
                                        class="flex w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                                        placeholder="@lang('admin::app.activities.edit.schedule_to')"
                                    />
                                </x-admin::flat-picker.datetime>
                            </div>
                        </div>
                    </x-admin::form.control-group>

                    <!-- Comment -->
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('admin::app.activities.edit.comment')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            name="comment"
                            id="comment"
                            :value="old('comment') ?? $activity->comment"
                            :label="trans('admin::app.activities.edit.comment')"
                            :placeholder="trans('admin::app.activities.edit.comment')"
                        />

                        <x-admin::form.control-group.error control-name="comment" />
                    </x-admin::form.control-group>

                    <!-- Participants -->
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('admin::app.activities.edit.participants')
                        </x-admin::form.control-group.label>

                        <!-- Participants Multi lookup Vue Component -->
                        <v-multi-lookup-component>
                            <div
                                class="relative rounded border border-gray-200 px-2 py-1 hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                role="button"
                            >
                                <ul class="flex flex-wrap items-center gap-1">
                                    <li>
                                        <input
                                            type="text"
                                            class="w-full px-1 py-1 dark:bg-gray-900 dark:text-gray-300"
                                            placeholder="@lang('admin::app.activities.edit.participants')"
                                        />
                                    </li>
                                </ul>

                                <span class="icon-down-arrow absolute top-1.5 text-2xl ltr:right-1.5 rtl:left-1.5"></span>
                            </div>
                        </v-multi-lookup-component>
                    </x-admin::form.control-group>
                    @endif

                    <!-- Assigned Workers -->
                    @if (! $isCalcomBooking || $canAssignCalcomWorker)
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            {{ $isCalcomBooking ? 'Assigned worker' : 'Workers' }}
                        </x-admin::form.control-group.label>

                        <input type="hidden" name="worker_assignment_present" value="1">

                        @if ($isCalcomBooking)
                            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                                Choose the worker who will handle this appointment.
                            </p>
                        @endif

                        <div class="grid gap-2 sm:grid-cols-2">
                            @forelse ($workers as $worker)
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input
                                        type="{{ $isCalcomBooking ? 'radio' : 'checkbox' }}"
                                        name="worker_ids[]"
                                        value="{{ $worker->id }}"
                                        @if ($isCalcomBooking)
                                            required
                                            @checked((int) $worker->id === (int) $selectedWorkerId)
                                        @else
                                            @checked(in_array($worker->id, old('worker_ids', $activity->workers->pluck('id')->all())))
                                        @endif
                                    >
                                    @if ($worker->calendar_color)
                                        <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $worker->calendar_color }}"></span>
                                    @endif
                                    <span>
                                        {{ $worker->name }}
                                        @unless ($worker->is_active)
                                            <span class="text-xs text-amber-600">(inactive)</span>
                                        @endunless
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-gray-500">No active workers are registered.</p>
                            @endforelse
                        </div>
                        <x-admin::form.control-group.error control-name="worker_ids" />
                    </x-admin::form.control-group>
                    @endif

                    <!-- Lead -->
                    @unless ($isCalcomBooking)
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.label>
                            @lang('admin::app.activities.edit.lead')
                        </x-admin::form.control-group.label>

                        <x-admin::attributes.edit.lookup/>

                        <!-- Lead Lookup Vue Component -->
                        <v-lookup-component
                            :attribute="{'code': 'lead_id', 'name': 'Lead', 'lookup_type': 'leads'}"
                            :value='@json($lookUpEntityData)'
                        >
                            <x-admin::form.control-group.control
                                type="text"
                                placeholder="@lang('admin::app.common.start-typing')"
                            />
                        </v-lookup-component>
                    </x-admin::form.control-group>
                    @endunless

                    {!! view_render_event('admin.activities.edit.form_controls.after') !!}
                </div>

                <!-- Right sub-component -->
                @if ($isCalcomBooking)
                    <div class="box-shadow w-[360px] max-w-full rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 max-xl:w-full">
                        <h2 class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Cal.com booking details</h2>
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Title</dt>
                                <dd class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $activity->title }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500">Type</dt>
                                <dd class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ ucfirst($activity->type) }}</dd>
                            </div>
                            @if ($activity->location)
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Location</dt>
                                    <dd class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $activity->location }}</dd>
                                </div>
                            @endif
                            @if ($activity->comment)
                                <div>
                                    <dt class="text-xs font-medium text-gray-500">Booking notes</dt>
                                    <dd class="mt-1 whitespace-pre-line text-sm text-gray-800 dark:text-gray-200">{{ $activity->comment }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @else
                <div class="w-[360px] max-w-full gap-2 max-xl:w-full">
                    {!! view_render_event('admin.activities.edit.accordion.general.before') !!}

                    <x-admin::accordion>
                        <x-slot:header>
                            <div class="flex items-center justify-between">
                                <p class="p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                    @lang('admin::app.activities.edit.general')
                                </p>
                            </div>
                        </x-slot>

                        <x-slot:content>
                            <!-- Title -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('admin::app.activities.edit.title')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="title"
                                    id="title"
                                    rules="required"
                                    :value="old('title') ?? $activity->title"
                                    :label="trans('admin::app.activities.edit.title')"
                                    :placeholder="trans('admin::app.activities.edit.title')"
                                />

                                <x-admin::form.control-group.error control-name="title" />
                            </x-admin::form.control-group>

                            <!-- Edit Type -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('admin::app.activities.edit.type')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    name="type"
                                    id="type"
                                    :value="old('type') ?? $activity->type"
                                    rules="required"
                                    :label="trans('admin::app.activities.edit.type')"
                                    :placeholder="trans('admin::app.activities.edit.type')"
                                >
                                    <option value="call">
                                        @lang('admin::app.activities.edit.call')
                                    </option>

                                    <option value="meeting">
                                        @lang('admin::app.activities.edit.meeting')
                                    </option>

                                    <option value="lunch">
                                        @lang('admin::app.activities.edit.lunch')
                                    </option>

                                    @if ($isWorkerTask)
                                        <option value="task">Worker booking</option>
                                    @endif
                                </x-admin::form.control-group.control>

                                <x-admin::form.control-group.error control-name="type" />
                            </x-admin::form.control-group>

                            @if ($isWorkerTask)
                                <x-admin::form.control-group class="!mb-0">
                                    <input type="hidden" name="is_done" value="0">
                                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" name="is_done" value="1" @checked($activity->is_done)>
                                        Mark this work task as done
                                    </label>
                                </x-admin::form.control-group>
                            @endif

                            <!-- Location -->
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.label>
                                    @lang('admin::app.activities.edit.location')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    name="location"
                                    id="location"
                                    :value="old('location') ?? $activity->location"
                                    :label="trans('admin::app.activities.edit.location')"
                                    :placeholder="trans('admin::app.activities.edit.location')"
                                />

                                <x-admin::form.control-group.error control-name="location" />
                            </x-admin::form.control-group>
                        </x-slot>
                    </x-admin::accordion>

                    {!! view_render_event('admin.activities.edit.accordion.general.after') !!}
                </div>
                @endif
            </div>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.activities.edit.form.after') !!}

    @if (! $isCalcomBooking && bouncer()->hasPermission('activities.delete'))
        <script>
            // Worker events (busy blocks, personal time) are flexible: delete and
            // return to the worker schedule when that is where we came from.
            function deleteWorkerActivity() {
                if (! confirm('Delete this activity? The time becomes available again.')) {
                    return;
                }

                axios.delete("{{ route('admin.activities.delete', $activity->id) }}")
                    .then(() => {
                        window.location.href = document.referrer.includes('/schedule')
                            ? document.referrer
                            : "{{ route('admin.activities.index') }}";
                    })
                    .catch((error) => alert(error.response?.data?.message ?? 'The activity could not be deleted.'));
            }
        </script>
    @endif

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-multi-lookup-component-template"
        >
            <!-- Search Button -->
            <div class="relative">
                <div class="relative rounded border border-gray-200 px-2 py-1 hover:border-gray-400 focus:border-gray-400 dark:border-gray-800" role="button">
                    <ul class="flex flex-wrap items-center gap-1">
                        <!-- Added Participants -->
                        <template v-for="userType in ['users', 'persons']">
                            <template v-if="! addedParticipants[userType].length">
                                <input
                                    type="hidden"
                                    :name="`participants[${userType}][]`"
                                    value=""
                                />
                            </template>

                            <li
                                class="flex items-center gap-1 rounded-md bg-slate-100 pl-2 dark:bg-slate-950 dark:text-gray-300"
                                v-for="(user, index) in addedParticipants[userType]"
                            >
                                <!-- Person and User Hidden Input Field -->
                                <input
                                    type="hidden"
                                    :name="`participants[${userType}][]`"
                                    :value="user.id"
                                />

                                @{{ user.name }}

                                <span
                                    class="icon-cross-large cursor-pointer p-0.5 text-xl"
                                    @click="remove(userType, user)"
                                ></span>
                            </li>
                        </template>

                        <!-- Search Input Box -->
                        <li>
                            <input
                                type="text"
                                class="w-full px-1 py-1 dark:bg-gray-900 dark:text-gray-300"
                                placeholder="@lang('admin::app.activities.edit.participants')"
                                v-model.lazy="searchTerm"
                                v-debounce="500"
                            />
                        </li>
                    </ul>

                    <!-- Search and Spinner Icon -->
                    <div>
                        <template v-if="! isSearching.users && ! isSearching.persons">
                            <span
                                class="absolute top-1.5 text-2xl ltr:right-1.5 rtl:left-1.5"
                                :class="[searchTerm.length >= 2 ? 'icon-up-arrow' : 'icon-down-arrow']"
                            ></span>
                        </template>

                        <template v-else>
                            <x-admin::spinner class="absolute top-2 ltr:right-2 rtl:left-2" />
                        </template>
                    </div>
                </div>

                <!-- Search Dropdown -->
                <div
                    class="absolute z-10 w-full rounded bg-white shadow-[0px_10px_20px_0px_#0000001F] dark:bg-gray-900"
                    v-if="searchTerm.length >= 2"
                >
                    <ul class="flex flex-col gap-1 p-2">
                        <!-- Users and Person Searched Participants -->
                        <li
                            class="flex flex-col gap-2"
                            v-for="userType in ['users', 'persons']"
                        >
                            <h3 class="text-sm font-bold text-gray-600 dark:text-gray-400">
                                <template v-if="userType === 'users'">
                                    @lang('admin::app.activities.edit.users')
                                </template>

                                <template v-else>
                                    @lang('admin::app.activities.edit.persons')
                                </template>
                            </h3>

                            <ul>
                                <li
                                    class="rounded-sm px-5 py-2 text-sm text-gray-800 dark:text-gray-300"
                                    v-if="! searchedParticipants[userType].length && ! isSearching[userType]"
                                >
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        @lang('admin::app.activities.edit.no-result-found')
                                    </p>
                                </li>

                                <li
                                    class="cursor-pointer rounded-sm px-3 py-2 text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-950"
                                    v-for="user in searchedParticipants[userType]"
                                    @click="add(userType, user)"
                                >
                                    @{{ user.name }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-multi-lookup-component', {
                template: '#v-multi-lookup-component-template',

                data() {
                    return {
                        isSearching: {
                            users: false,

                            persons: false,
                        },

                        searchTerm: '',

                        addedParticipants: {
                            users: [],

                            persons: [],
                        },

                        searchedParticipants: {
                            users: [],

                            persons: [],
                        },

                        searchEnpoints: {
                            users: "{{ route('admin.settings.users.search') }}",

                            persons: "{{ route('admin.contacts.persons.search') }}",
                        },
                    };
                },

                watch: {
                    searchTerm(newVal, oldVal) {
                        this.search('users');

                        this.search('persons');
                    },
                },

                created() {
                    @json($activity->participants).forEach(participant => {
                        if (participant.user) {
                            this.addedParticipants.users.push(participant.user);
                        } else if (participant.person) {
                            this.addedParticipants.persons.push(participant.person);
                        }
                    });
                },

                methods: {
                    search(userType) {
                        if (this.searchTerm.length <= 1) {
                            this.searchedParticipants[userType] = [];

                            this.isSearching[userType] = false;

                            return;
                        }

                        this.isSearching[userType] = true;

                        this.$axios.get(this.searchEnpoints[userType], {
                                params: {
                                    search: 'name:' + this.searchTerm,
                                    searchFields: 'name:like',
                                }
                            })
                            .then ((response) => {
                                this.addedParticipants[userType].forEach(addedParticipant =>
                                    response.data.data = response.data.data.filter(participant => participant.id !== addedParticipant.id)
                                );

                                this.searchedParticipants[userType] = response.data.data;

                                this.isSearching[userType] = false;
                            })
                            .catch (function (error) {
                                this.isSearching[userType] = false;
                            });
                    },

                    add(userType, participant) {
                        this.addedParticipants[userType].push(participant);

                        this.searchTerm = '';

                        this.searchedParticipants = {
                            users: [],

                            persons: [],
                        };
                    },

                    remove(userType, participant) {
                        this.addedParticipants[userType] = this.addedParticipants[userType].filter(addedParticipant =>
                            addedParticipant.id !== participant.id
                        );
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
