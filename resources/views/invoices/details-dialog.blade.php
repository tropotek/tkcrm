{{--
    An open invoice's own details, in the shared dialog.
    Its fields are filled from the request when it comes back with errors, and from the invoice otherwise.
--}}
@php
    use App\Support\BasisPoints;

    $posted = request()->isMethod('GET') ? null : request();
    $value = fn (string $field, mixed $saved) => $posted ? $posted->input($field) : $saved;
@endphp

<x-modal title="Invoice details" :action="route('invoices.update', $invoice)" method="put">
    <div class="row">
        <div class="col-sm-7">
            <div class="mb-3">
                <label for="invoice_purchase_order" class="form-label">Purchase order</label>
                <input type="text" id="invoice_purchase_order" name="purchase_order" value="{{ $value('purchase_order', $invoice->purchase_order) }}" maxlength="255" autofocus
                    @class(['form-control', 'is-invalid' => $errors->has('purchase_order')])>
                @error('purchase_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <div class="col-sm-5">
            <div class="mb-3">
                <label for="invoice_discount" class="form-label">Discount</label>
                <div class="input-group">
                    <input type="text" id="invoice_discount" name="discount" value="{{ $value('discount', $invoice->discount ? BasisPoints::toPercentage($invoice->discount) : '') }}" inputmode="decimal" placeholder="0"
                        @class(['form-control', 'text-end', 'is-invalid' => $errors->has('discount')])>
                    <span class="input-group-text">%</span>
                    @error('discount')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <label for="invoice_billing_address" class="form-label">Billing address</label>
        <textarea id="invoice_billing_address" name="billing_address" rows="3" placeholder="{{ $invoice->company->address }}"
            @class(['form-control', 'is-invalid' => $errors->has('billing_address')])>{{ $value('billing_address', $invoice->billing_address) }}</textarea>
        @error('billing_address')
            <div class="invalid-feedback">{{ $message }}</div>
        @else
            <div class="form-text">Leave it empty to use the client's own address.</div>
        @enderror
    </div>

    <div class="mb-0">
        <label for="invoice_notes" class="form-label">Notes</label>
        <textarea id="invoice_notes" name="notes" rows="3"
            @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ $value('notes', $invoice->notes) }}</textarea>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @else
            <div class="form-text">Printed on the invoice.</div>
        @enderror
    </div>

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Save</button>
    </x-slot:footer>
</x-modal>
