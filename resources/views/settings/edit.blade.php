<x-layouts.app title="Settings">
    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Site</h4>

                        <div class="mb-3">
                            <label for="site_name" class="form-label">Site name</label>
                            <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $settings['site_name']) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('site_name')])>
                            @error('site_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <x-company-select name="owner_company_id" label="Owner company" :selected="$settings['owner_company_id']"
                            help="Your own business. Its name, ABN, address, phone and email go on invoices, and its email sends them." />

                        <div class="form-check">
                            <input type="checkbox" id="bcc_owner" name="bcc_owner" value="1" class="form-check-input" @checked(old('bcc_owner', $settings['bcc_owner']))>
                            <label for="bcc_owner" class="form-check-label">Send the owner a blind copy of every email to a client</label>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Tasks</h4>

                        <div class="mb-3">
                            <label for="default_labour_product_id" class="form-label">Default labour product</label>
                            <select id="default_labour_product_id" name="default_labour_product_id" @disabled(! $products)
                                @class(['form-select', 'is-invalid' => $errors->has('default_labour_product_id')])>
                                <option value="">None</option>
                                @foreach ($products as $id => $name)
                                    <option value="{{ $id }}" @selected((int) old('default_labour_product_id', $settings['default_labour_product_id']) === $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('default_labour_product_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">
                                    The product a new time log is charged as.
                                    @if (! $products) There are no products to choose from yet: add one under Products. @endif
                                </div>
                            @enderror
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="time_logs_billable" name="time_logs_billable" value="1" class="form-check-input" @checked(old('time_logs_billable', $settings['time_logs_billable']))>
                            <label for="time_logs_billable" class="form-check-label">A new time log is billable</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Invoices</h4>

                        <div class="mb-3">
                            <label for="overdue_days" class="form-label">Days until an invoice is due</label>
                            <input type="number" id="overdue_days" name="overdue_days" value="{{ old('overdue_days', $settings['overdue_days']) }}" min="0" max="365" required
                                @class(['form-control', 'is-invalid' => $errors->has('overdue_days')])>
                            @error('overdue_days')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Counted from the day the invoice is issued. It is overdue after that.</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="payment_instructions" class="form-label">Payment instructions</label>
                            <textarea id="payment_instructions" name="payment_instructions" rows="5"
                                @class(['form-control', 'is-invalid' => $errors->has('payment_instructions')])>{{ old('payment_instructions', $settings['payment_instructions']) }}</textarea>
                            @error('payment_instructions')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Printed on invoices and included in the emails that carry them.</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="card" x-data="{ gst: {{ old('gst_enabled', $settings['gst_enabled']) ? 'true' : 'false' }} }">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Tax</h4>

                        <div class="mb-3">
                            <label for="tax_estimate_rate" class="form-label">Tax estimate rate</label>
                            <div class="input-group">
                                <input type="number" id="tax_estimate_rate" name="tax_estimate_rate" value="{{ old('tax_estimate_rate', $settings['tax_estimate_rate']) }}" min="0" max="100" step="0.01" required
                                    @class(['form-control', 'is-invalid' => $errors->has('tax_estimate_rate')])>
                                <span class="input-group-text">%</span>
                                @error('tax_estimate_rate')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">Applied to net profit for the estimated tax on the profit and loss.</div>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" id="gst_enabled" name="gst_enabled" value="1" class="form-check-input" x-model="gst" @checked(old('gst_enabled', $settings['gst_enabled']))>
                            <label for="gst_enabled" class="form-check-label">Registered for GST</label>
                        </div>

                        {{-- The rate is posted either way, so it is kept while GST is off. --}}
                        <div class="mb-3" x-show="gst" x-cloak>
                            <label for="gst_rate" class="form-label">GST rate</label>
                            <div class="input-group">
                                <input type="number" id="gst_rate" name="gst_rate" value="{{ old('gst_rate', $settings['gst_rate']) }}" min="0" max="100" step="0.01" required
                                    @class(['form-control', 'is-invalid' => $errors->has('gst_rate')])>
                                <span class="input-group-text">%</span>
                                @error('gst_rate')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mb-4">Save</button>
    </form>
</x-layouts.app>
