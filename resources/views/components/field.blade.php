{{--
    A labelled text field of a form, with its validation error or a line of help under it.

    <x-field name="name" label="Name" :value="$company->name" required />
    <x-field name="email" label="Email" type="email" :value="$company->email" help="Where invoices are sent." />
    <x-field name="notes" label="Notes" type="textarea" :value="$company->notes" rows="4" />

    The value shown is what was last posted, when there is one. Other attributes go on the field itself.
--}}
@props(['name', 'label', 'value' => null, 'type' => 'text', 'help' => null, 'id' => null])

@php($id ??= $name)

<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>{{ old($name, $value) }}</textarea>
    @else
        <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" value="{{ old($name, $value) }}" {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
    @endif
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @else
        @if ($help)
            <div class="form-text">{{ $help }}</div>
        @endif
    @enderror
</div>
