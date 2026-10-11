{{--
    A company's own page. Its open tasks, invoices, recurring items and domains are added below the
    details by the tasks that build those modules.
--}}
<x-layouts.app :title="$company->name">
    <x-slot:actions>
        <a href="{{ route('companies.edit', $company) }}" class="btn btn-primary"><i class="ri-pencil-line me-1"></i>Edit</a>
    </x-slot:actions>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">
                        {{ $company->type->label() }}
                        @unless ($company->active)
                            <span class="badge bg-secondary ms-1">Inactive</span>
                        @endunless
                    </h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Account</dt>
                        <dd class="col-sm-8">{{ $company->account_number }}</dd>

                        @if ($company->alias)
                            <dt class="col-sm-4">Alias</dt>
                            <dd class="col-sm-8">{{ $company->alias }}</dd>
                        @endif

                        @if ($company->abn)
                            <dt class="col-sm-4">ABN</dt>
                            <dd class="col-sm-8">{{ $company->abn }}</dd>
                        @endif

                        @if ($company->website)
                            <dt class="col-sm-4">Website</dt>
                            <dd class="col-sm-8"><a href="{{ $company->website }}" target="_blank" rel="noopener noreferrer">{{ $company->website }}</a></dd>
                        @endif

                        @if ($company->address)
                            <dt class="col-sm-4">Address</dt>
                            <dd class="col-sm-8">{!! nl2br(e($company->address)) !!}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Contact</h4>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $company->contact_name ?: '—' }}</dd>

                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8">
                            @if ($company->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company->phone) }}">{{ $company->phone }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">
                            @if ($company->email)
                                <a href="mailto:{{ $company->email }}">{{ $company->email }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        @if ($company->accounts_email)
                            <dt class="col-sm-4">Accounts email</dt>
                            <dd class="col-sm-8"><a href="mailto:{{ $company->accounts_email }}">{{ $company->accounts_email }}</a></dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if ($company->notes)
                <div class="card">
                    <div class="card-body">
                        <h4 class="header-title mb-3">Notes</h4>
                        <p class="mb-0">{!! nl2br(e($company->notes)) !!}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
