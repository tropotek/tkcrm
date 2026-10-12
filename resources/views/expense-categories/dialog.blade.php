{{--
    The add and edit form for an expense category, in the shared dialog.
    Its fields are filled from the request when it comes back with errors, and from the category otherwise.
    The share claimed is typed as a percentage and kept in basis points.
--}}
@php
    $posted = request()->isMethod('GET') ? null : request();
    $active = $posted ? $posted->boolean('active') : $category->active;
    $inUse = $category->exists && $category->expenses()->exists();
@endphp

<x-modal :title="$category->exists ? 'Edit category' : 'Add category'"
    :action="$category->exists ? route('expense-categories.update', $category) : route('expense-categories.store')"
    :method="$category->exists ? 'put' : 'post'">
    <div class="mb-3">
        <label for="category_name" class="form-label">Name</label>
        <input type="text" id="category_name" name="name" value="{{ $posted ? $posted->input('name') : $category->name }}" required autofocus maxlength="255"
            @class(['form-control', 'is-invalid' => $errors->has('name')])>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="category_claim" class="form-label">Share claimed</label>
        <div class="input-group">
            <input type="text" id="category_claim" name="claim" value="{{ $posted ? $posted->input('claim') : App\Support\BasisPoints::toPercentage($category->claim_ratio) }}" required inputmode="decimal"
                @class(['form-control', 'text-end', 'is-invalid' => $errors->has('claim')])>
            <span class="input-group-text">%</span>
            @error('claim')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="form-text">
            How much of an expense in this category counts against income: 100 for all of it, 50 for half, 0 for none.
            @if ($inUse)
                Changing it changes what every expense already in the category claims, in past years too.
            @endif
        </div>
    </div>

    <div class="mb-3">
        <label for="category_description" class="form-label">Description</label>
        <textarea id="category_description" name="description" rows="3"
            @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ $posted ? $posted->input('description') : $category->description }}</textarea>
        @error('description')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="form-check">
        <input type="checkbox" id="category_active" name="active" value="1" class="form-check-input" @checked($active)>
        <label for="category_active" class="form-check-label">Active</label>
    </div>

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Save</button>
    </x-slot:footer>
</x-modal>
