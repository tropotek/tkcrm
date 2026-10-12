{{--
    The add and edit form. $categories: the categories that can be chosen.
    $gst: GST is on, so the GST in the expense is asked for. With it off the field is not shown.
    Receipts are attached once the expense exists, so a new one comes back here after its first save.
--}}
<x-layouts.app :title="$expense->exists ? 'Edit expense' : 'Add expense'">
    <div class="row">
        <div class="col-lg-6">
            <form id="expense-form" method="POST" action="{{ $expense->exists ? route('expenses.update', $expense) : route('expenses.store') }}">
                @csrf
                @if ($expense->exists)
                    @method('PUT')
                @endif

                <div class="card">
                    <div class="card-body">
                        <x-company-select name="company_id" label="Supplier" :selected="$expense->company_id" type="supplier" required />

                        <div class="mb-3">
                            <label for="expense_category_id" class="form-label">Category</label>
                            <select id="expense_category_id" name="expense_category_id" required @class(['form-select', 'is-invalid' => $errors->has('expense_category_id')])>
                                <option value="">Choose…</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string) $category->id === (string) old('expense_category_id', $expense->expense_category_id))>{{ $category->name }}{{ $category->claim_ratio === 10000 ? '' : ', '.App\Support\BasisPoints::format($category->claim_ratio).' claimed' }}{{ $category->active ? '' : ' (inactive)' }}</option>
                                @endforeach
                            </select>
                            @error('expense_category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-4">
                                <x-field name="purchased_on" label="Purchased" type="date" :value="$expense->purchased_on?->toDateString()" required />
                            </div>
                            <div class="col-sm-4">
                                <div class="mb-3">
                                    <label for="total" class="form-label">Total</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="text" id="total" name="total" value="{{ old('total', $expense->total?->toDecimal()) }}" required inputmode="decimal"
                                            @class(['form-control', 'text-end', 'is-invalid' => $errors->has('total')])>
                                        @error('total')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="form-text">What was paid{{ $gst ? ', GST and all' : '' }}. A refund is a minus amount.</div>
                                </div>
                            </div>
                            @if ($gst)
                                <div class="col-sm-4">
                                    <div class="mb-3">
                                        <label for="gst_included" class="form-label">GST in it</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="text" id="gst_included" name="gst_included" value="{{ old('gst_included', $expense->gst_included?->isZero() === false ? $expense->gst_included->toDecimal() : '') }}" inputmode="decimal"
                                                @class(['form-control', 'text-end', 'is-invalid' => $errors->has('gst_included')])>
                                            @error('gst_included')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="form-text">As the supplier's invoice shows it. Blank for none.</div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-sm-6">
                                <x-field name="invoice_number" label="Their invoice number" :value="$expense->invoice_number" maxlength="64" />
                            </div>
                            <div class="col-sm-6">
                                <x-field name="receipt_number" label="Receipt number" :value="$expense->receipt_number" maxlength="64" />
                            </div>
                        </div>

                        <x-field name="description" label="Description" type="textarea" :value="$expense->description" rows="3" />
                    </div>
                </div>
            </form>

            <div class="d-flex align-items-center mb-4">
                <button type="submit" form="expense-form" class="btn btn-primary">Save</button>
                <a href="{{ route('expenses.index') }}" class="btn btn-link">{{ $expense->exists ? 'Back to the list' : 'Cancel' }}</a>

                @if ($expense->exists)
                    <form method="POST" action="{{ route('expenses.destroy', $expense) }}" class="ms-auto"
                        onsubmit="return confirm('Delete this expense and its receipts? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">Delete</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Receipts</h4>

                    @if ($expense->exists)
                        <x-attachments :for="$expense" />
                    @else
                        <p class="text-muted mb-0">Save the expense first, then attach its receipt here.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
