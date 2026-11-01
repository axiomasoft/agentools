<p>{{ $result->code }}</p>
@if ($result->code === 'outcome_unknown')
    <p>Outcome unknown. Inspect the bundle or reconcile the command reference before retrying.</p>
@endif
<dl>
    @foreach ($result->fields as $name => $value)
        <dt>{{ $name }}</dt><dd>{{ json_encode($value, JSON_THROW_ON_ERROR) }}</dd>
    @endforeach
</dl>
@foreach ($result->issues as $issue)
    <p>{{ $issue }}</p>
@endforeach
@if ($result->conflict !== null)
    <p>{{ $result->conflict->code }}: {{ $result->conflict->field }}</p>
    <p>{{ $result->conflict->reason }}</p>
    <pre>{{ json_encode($result->conflict->sourceRefs, JSON_THROW_ON_ERROR) }}</pre>
    <pre>{{ json_encode($result->conflict->sourceValuesHashes, JSON_THROW_ON_ERROR) }}</pre>
@endif
