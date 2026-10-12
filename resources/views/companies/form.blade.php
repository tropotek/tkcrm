{{-- The add and edit form. --}}
<x-layouts.app :title="$company->exists ? 'Edit '.$company->name : 'Add company'">
    <form method="POST" action="{{ $company->exists ? route('companies.update', $company) : route('companies.store') }}">
        @csrf
        @if ($company->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Company</h4>

                        <div class="mb-3">
                            <label for="type" class="form-label">Type</label>
                            <select id="type" name="type" required @class(['form-select', 'is-invalid' => $errors->has('type')])>
                                @foreach ($types as $type)
                                    <option value="{{ $type->value }}" @selected($type->value === old('type', $company->type->value))>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <x-field name="name" label="Name" :value="$company->name" required maxlength="255" />
                        <x-field name="alias" label="Alias" :value="$company->alias" maxlength="255" help="A short or trading name, also searched." />
                        <x-field name="abn" label="ABN" :value="$company->abn" maxlength="16" />
                        <x-field name="website" label="Website" :value="$company->website" maxlength="255" />
                        <x-field name="address" label="Address" type="textarea" :value="$company->address" rows="3" help="Printed on invoices and statements." />

                        {{-- Unticking Active on a client with recurring items still billing offers to stop those too. --}}
                        @php($billing = $company->exists ? $company->recurrings()->active()->with('product')->get() : collect())

                        <div x-data="{ active: @js((bool) old('active', $company->active)) }">
                            <div class="form-check">
                                <input type="checkbox" id="active" name="active" value="1" class="form-check-input" x-model="active" @checked(old('active', $company->active))>
                                <label for="active" class="form-check-label">Active</label>
                            </div>

                            @if ($billing->isNotEmpty())
                                <div class="alert alert-warning mt-3 mb-0" x-show="! active" x-cloak>
                                    <p class="mb-2">
                                        {{ $company->name }} has {{ $billing->count() === 1 ? '1 recurring item' : $billing->count().' recurring items' }} still billing,
                                        {{ App\Support\Money::sum($billing->map->yearlyValue())->format() }} a year:
                                        {{ $billing->map->label()->join(', ') }}.
                                    </p>
                                    <div class="form-check">
                                        <input type="checkbox" id="stop_recurrings" name="stop_recurrings" value="1" class="form-check-input" @checked(old('stop_recurrings', true))>
                                        <label for="stop_recurrings" class="form-check-label">Make {{ $billing->count() === 1 ? 'it' : 'them' }} inactive too, so nothing more is billed</label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Contact</h4>

                        <x-field name="contact_name" label="Contact name" :value="$company->contact_name" maxlength="255" />
                        <x-field name="phone" label="Phone" type="tel" :value="$company->phone" maxlength="32" />
                        <x-field name="email" label="Email" type="email" :value="$company->email" maxlength="255" help="Invoices and statements are sent here." />
                        <x-field name="accounts_email" label="Accounts email" type="email" :value="$company->accounts_email" maxlength="255" help="Gets a copy of each invoice and statement." />
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <x-field name="notes" label="Notes" type="textarea" :value="$company->notes" rows="5" help="For your eyes only. Never printed or sent." />
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="{{ $company->exists ? route('companies.show', $company) : route('companies.index') }}" class="btn btn-link">Cancel</a>
        </div>
    </form>
</x-layouts.app>
