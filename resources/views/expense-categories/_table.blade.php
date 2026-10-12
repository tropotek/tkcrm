{{-- The category table. Sent alone when htmx asks for #category-table, which it does after a save in the dialog. --}}
<div id="category-table" hx-get="{{ route('expense-categories.index') }}" hx-trigger="categories-changed from:body" hx-target="this" hx-swap="outerHTML">
    <div class="table-responsive">
        <table class="table table-hover table-centered mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="text-end">Claimed</th>
                    <th class="text-end">Expenses</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr @class(['text-muted' => ! $category->active])>
                        <td>
                            <span class="fw-medium">{{ $category->name }}</span>
                            @unless ($category->active)
                                <span class="badge bg-secondary ms-1">Inactive</span>
                            @endunless
                        </td>
                        <td>{{ $category->description }}</td>
                        <td class="text-end">{{ App\Support\BasisPoints::format($category->claim_ratio) }}</td>
                        <td class="text-end">
                            @if ($category->expenses_count)
                                <a href="{{ route('expenses.index', ['category' => $category->id, 'year' => 'all']) }}">{{ $category->expenses_count }}</a>
                            @else
                                0
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light" hx-get="{{ route('expense-categories.edit', $category) }}" hx-target="#app-modal-content">Edit</button>
                            @unless ($category->expenses_count)
                                <form method="POST" action="{{ route('expense-categories.destroy', $category) }}" class="d-inline"
                                    onsubmit="return confirm('Delete this category?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted">No categories yet. An expense needs one.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
