{{--
    A select of companies for another form, with a button that adds one in the shared dialog
    without leaving the form. The new company joins the list and is chosen.

    <x-company-select name="company_id" label="Client" :selected="$invoice->company_id" type="client" required />

    type: client or supplier to list only those (and to begin the dialog on); leave it out for both.
    Inactive companies are left out, except the one already selected.
--}}
@props(['name', 'label', 'selected' => null, 'type' => null, 'help' => null, 'id' => null])

@php
    $id ??= $name;
    $type = is_string($type) ? App\Enums\CompanyType::from($type) : $type;
    $selected = old($name, $selected);
    $companies = App\Models\Company::query()
        ->when($type, fn ($query) => $query->where('type', $type))
        ->where(fn ($query) => $query->where('active', true)->orWhere('id', $selected))
        ->orderByRaw('name COLLATE NOCASE')
        ->get(['id', 'name', 'active']);
@endphp

<div class="mb-3" x-data="{}"
    @company-added.window="if (! @js($type?->value) || $event.detail.type === @js($type?->value)) {
        $refs.select.add(new Option($event.detail.name, $event.detail.id, true, true));
        $refs.select.dispatchEvent(new Event('change', { bubbles: true }));
    }">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <div class="input-group">
        <select id="{{ $id }}" name="{{ $name }}" x-ref="select" {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}>
            <option value="">Choose…</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}" @selected((string) $company->id === (string) $selected)>{{ $company->name }}{{ $company->active ? '' : ' (inactive)' }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn-light" title="Add a company"
            hx-get="{{ route('companies.create', array_filter(['type' => $type?->value])) }}" hx-target="#app-modal-content">
            <i class="ri-add-line"></i> New
        </button>
        @error($name)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @if ($help)
        <div class="form-text">{{ $help }}</div>
    @endif
</div>
