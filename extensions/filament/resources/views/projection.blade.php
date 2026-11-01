<p>{{ $inspection->code }}</p>
<dl>
    @foreach ($inspection->fields as $name => $value)
        <dt>{{ $name }}</dt><dd>{{ $value === null ? 'unknown / not observed' : $value }}</dd>
    @endforeach
</dl>
