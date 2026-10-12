{{-- The expense categories. The add and edit form opens in the shared dialog, and a save refreshes the table. --}}
<x-layouts.app title="Expense categories">
    <x-slot:actions>
        <a href="{{ route('expenses.index') }}" class="btn btn-light">Expenses</a>
        <button type="button" class="btn btn-primary" hx-get="{{ route('expense-categories.create') }}" hx-target="#app-modal-content"><i class="ri-add-line me-1"></i>Add category</button>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            @include('expense-categories._table')
        </div>
    </div>
</x-layouts.app>
