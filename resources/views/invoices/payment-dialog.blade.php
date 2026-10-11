{{--
    Records a payment against an unpaid invoice, in the shared dialog.
    It begins with everything still owing, by EFT, received today.
    Its fields are filled from the request when it comes back with errors.
--}}
@php
    use App\Enums\PaymentMethod;
    use App\Support\LocalTime;

    $posted = request()->isMethod('GET') ? null : request();
    $value = fn (string $field, mixed $begin) => $posted ? $posted->input($field) : $begin;
    $today = LocalTime::of(now())->toDateString();
    $method = $value('method', PaymentMethod::Eft->value);
@endphp

<x-modal title="Record payment" :action="route('invoices.payments.store', $invoice)">
    <p class="mb-3">
        Invoice {{ $invoice->number }} for {{ $invoice->company->name }}:
        <span class="fw-medium">{{ $invoice->outstanding()->format() }}</span> still owing{{ $invoice->paidTotal()->isZero() ? '' : ' of '.$invoice->total->format() }}.
    </p>

    <div class="row">
        <div class="col-sm-4">
            <div class="mb-3">
                <label for="payment_amount" class="form-label">Amount</label>
                <div class="input-group has-validation">
                    <span class="input-group-text">$</span>
                    <input type="text" id="payment_amount" name="amount" value="{{ $value('amount', $invoice->outstanding()->toDecimal()) }}" required autofocus inputmode="decimal"
                        @class(['form-control', 'text-end', 'is-invalid' => $errors->has('amount')])>
                    @error('amount')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="mb-3">
                <label for="payment_method" class="form-label">Method</label>
                <select id="payment_method" name="method" required @class(['form-select', 'is-invalid' => $errors->has('method')])>
                    @foreach (PaymentMethod::cases() as $choice)
                        <option value="{{ $choice->value }}" @selected($choice->value === $method)>{{ $choice->label() }}</option>
                    @endforeach
                </select>
                @error('method')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <div class="col-sm-4">
            <div class="mb-3">
                <label for="payment_received_on" class="form-label">Received</label>
                <input type="date" id="payment_received_on" name="received_on" value="{{ $value('received_on', $today) }}" max="{{ $today }}" required
                    @class(['form-control', 'is-invalid' => $errors->has('received_on')])>
                @error('received_on')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>

    <div class="mb-0">
        <label for="payment_notes" class="form-label">Notes</label>
        <input type="text" id="payment_notes" name="notes" value="{{ $value('notes', '') }}" maxlength="2000"
            @class(['form-control', 'is-invalid' => $errors->has('notes')])>
        @error('notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @else
            <div class="form-text">A part payment leaves the rest owing. Nothing is sent to the client.</div>
        @enderror
    </div>

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Record payment</button>
    </x-slot:footer>
</x-modal>
