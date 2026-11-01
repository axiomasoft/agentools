<x-filament-panels::page>
    <form wire:submit="draft">
        <label>Bundle ID <input wire:model="target" maxlength="256" /></label>
        <label>Bundle version <input wire:model="version" type="number" min="1" /></label>
        <label>Content hash <input wire:model="hash" maxlength="72" /></label>
        <label>Expected revision <input wire:model="expected" type="number" min="0" /></label>
        <label>Reason reference <input wire:model="reason" maxlength="256" /></label>
        <label>Canonical draft manifest <textarea wire:model="manifest" maxlength="1048576"></textarea></label>
        <p>Published content is immutable. Edit by creating a draft with a new bundle version.</p>
        <button type="submit">Save draft</button>
        <button type="button" wire:click="validateDraft">Validate draft</button>
        <button type="button" wire:click="publish" wire:confirm="Publish this validated version?">Publish</button>
        <label>Instance ID <input wire:model="instance" maxlength="256" /></label>
        <label>Command reference <input wire:model="command" maxlength="256" /></label>
        <p>Assignment affects new sessions. Active sessions retain their pins.</p>
        <button type="button" wire:click="assign" wire:confirm="Assign this bundle for new sessions?">Assign new sessions</button>
        <button type="button" wire:click="deprecate" wire:confirm="Deprecate this version?">Deprecate</button>
        <button type="button" wire:click="retireProfile" wire:confirm="Retire this deprecated version?">Retire</button>
        <p>Explicit migration applies only to the host-selected session. Enter its expected pin revision, command and reason references. Compatibility is checked by the host.</p>
        <button type="button" wire:click="migrateSession" wire:confirm="Migrate the selected active session with this expected pin revision?">Migrate selected session</button>
        <button type="button" wire:click="$refresh">Inspect current version</button>
    </form>
    <p>{{ $feedback }}</p>
    @include('agentools-inspector::profile-projection')
</x-filament-panels::page>
