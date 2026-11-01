<x-filament-panels::page>
    <form method="GET">
        <label>Resource <select name="kind"><option value="run" @selected($kind === 'run')>Run</option><option value="action" @selected($kind === 'action')>Action</option></select></label>
        <label>ID <input name="target" value="{{ $target }}" required maxlength="256" /></label>
        <button type="submit">Inspect</button>
    </form>
    @include('agentools-inspector::projection')
</x-filament-panels::page>
