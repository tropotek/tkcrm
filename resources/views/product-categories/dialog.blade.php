{{--
    The add and edit form for a category, in the shared dialog.
    Its fields are filled from the request when it comes back with errors, and from the category otherwise.
--}}
@php
    $posted = request()->isMethod('GET') ? null : request();
    $active = $posted ? $posted->boolean('active') : $category->active;
@endphp

<x-modal :title="$category->exists ? 'Edit category' : 'Add category'"
    :action="$category->exists ? route('product-categories.update', $category) : route('product-categories.store')"
    :method="$category->exists ? 'put' : 'post'">
    <div class="mb-3">
        <label for="category_name" class="form-label">Name</label>
        <input type="text" id="category_name" name="name" value="{{ $posted ? $posted->input('name') : $category->name }}" required maxlength="255"
            @class(['form-control', 'is-invalid' => $errors->has('name')])>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
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
