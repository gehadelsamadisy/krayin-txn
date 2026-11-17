{{-- TXN Settings screens: title, description, save errors. Data: $title, $info. --}}
<div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
    <div>
        <h1 class="text-xl font-bold dark:text-white">{{ $title }}</h1>
        <p class="text-sm text-gray-500">{{ $info }} Empty or invalid values are never saved; a setting you reset goes back to its default.</p>
    </div>
    <a href="{{ route('admin.settings.index') }}" class="secondary-button">Back to settings</a>
</div>

@if ($errors->has('settings'))
    <div class="rounded-lg border border-red-200 bg-white p-4 text-sm text-red-600 dark:border-red-900 dark:bg-gray-900" role="alert">
        <p class="font-semibold">Not saved:</p>
        @foreach ($errors->get('settings') as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
