{{--
    The short add form, for the shared dialog: enough to make a company without leaving another form.
    The rest of its details are filled in later on its own page.

    Its fields are filled from the request, not from old(): a failed save re-renders it in the same request.
    $type: the type to begin with, when the dialog has just been opened.
--}}
@php($chosen = request()->input('type', isset($type) ? $type->value : 'client'))

<x-modal title="Add company" :action="route('companies.store')">
    <div class="mb-3">
        <label for="dialog_type" class="form-label">Type</label>
        <select id="dialog_type" name="type" required @class(['form-select', 'is-invalid' => $errors->has('type')])>
            @foreach ($types as $option)
                <option value="{{ $option->value }}" @selected($option->value === $chosen)>{{ $option->label() }}</option>
            @endforeach
        </select>
        @error('type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @foreach (['name' => ['Name', 'text'], 'contact_name' => ['Contact name', 'text'], 'email' => ['Email', 'email'], 'phone' => ['Phone', 'tel']] as $field => [$label, $inputType])
        <div class="mb-3">
            <label for="dialog_{{ $field }}" class="form-label">{{ $label }}</label>
            <input type="{{ $inputType }}" id="dialog_{{ $field }}" name="{{ $field }}" value="{{ request()->input($field) }}" @required($field === 'name') @if ($field === 'name') autofocus @endif
                @class(['form-control', 'is-invalid' => $errors->has($field)])>
            @error($field)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    @endforeach

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Add</button>
    </x-slot:footer>
</x-modal>
