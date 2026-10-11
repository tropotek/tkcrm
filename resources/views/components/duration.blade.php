{{--
    A length of time typed as hours and minutes, posted as name[hours] and name[minutes].
    App\Support\Duration::fromParts() turns the two back into whole minutes.

    <x-duration name="estimate" label="Estimate" :minutes="$task->estimated_minutes" help="…" />

    Nothing is shown for no time, so the fields start empty.
--}}
@props(['name', 'label', 'minutes' => 0, 'help' => null])

@php
    $hours = old("{$name}.hours", intdiv((int) $minutes, 60) ?: '');
    $rest = old("{$name}.minutes", (int) $minutes % 60 ?: '');
    $invalid = $errors->has("{$name}.hours") || $errors->has("{$name}.minutes") || $errors->has($name);
@endphp

<div class="mb-3">
    <label for="{{ $name }}_hours" class="form-label">{{ $label }}</label>
    <div @class(['input-group', 'has-validation' => $invalid])>
        <input type="number" id="{{ $name }}_hours" name="{{ $name }}[hours]" value="{{ $hours }}" min="0" max="9999" step="1" inputmode="numeric" aria-label="Hours"
            @class(['form-control', 'text-end', 'is-invalid' => $errors->has("{$name}.hours")])>
        <span class="input-group-text">h</span>
        <input type="number" id="{{ $name }}_minutes" name="{{ $name }}[minutes]" value="{{ $rest }}" min="0" max="59" step="1" inputmode="numeric" aria-label="Minutes"
            @class(['form-control', 'text-end', 'is-invalid' => $errors->has("{$name}.minutes")])>
        <span class="input-group-text">m</span>
        @if ($invalid)
            <div class="invalid-feedback d-block">{{ $errors->first("{$name}.hours") ?: $errors->first("{$name}.minutes") ?: $errors->first($name) }}</div>
        @endif
    </div>
    @if ($help && ! $invalid)
        <div class="form-text">{{ $help }}</div>
    @endif
</div>
