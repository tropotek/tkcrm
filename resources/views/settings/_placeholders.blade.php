{{-- The placeholders a message's wording can use, beside its editor. $placeholders: name => what it stands for. --}}
<h5 class="font-14 mt-0">Placeholders</h5>
<table class="table table-sm table-borderless small mb-3">
    <tbody>
        @foreach ($placeholders as $name => $meaning)
            <tr>
                <td class="ps-0 text-nowrap"><code>{{ '{'.$name.'}' }}</code></td>
                <td class="text-muted">{{ $meaning }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
