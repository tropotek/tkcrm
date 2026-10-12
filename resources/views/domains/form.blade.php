{{--
    The add and edit form. $checked: the site has been checked, so it has a history and cannot be deleted.
    Its state is not a field: only the checks set it.
--}}
<x-layouts.app :title="$domain->exists ? 'Edit '.$domain->host() : 'Add domain'">
    <form id="domain-form" method="POST" action="{{ $domain->exists ? route('domains.update', $domain) : route('domains.store') }}">
        @csrf
        @if ($domain->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-company-select name="company_id" label="Company" :selected="$domain->company_id" required />

                        <x-field name="url" label="Address" :value="$domain->url" required maxlength="255" placeholder="https://example.com.au/up"
                            help="The page that is asked for. Any 2xx reply is up. A Laravel site's /up, or a ttek-stack site's /tkping, also reports its version or size." />

                        <div class="form-check mb-2">
                            <input type="checkbox" id="active" name="active" value="1" class="form-check-input" @checked(old('active', $domain->active))>
                            <label for="active" class="form-check-label">Active</label>
                            <div class="form-text">An inactive site is not checked.</div>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="alerts" name="alerts" value="1" class="form-check-input" @checked(old('alerts', $domain->alerts))>
                            <label for="alerts" class="form-check-label">Tell me when it goes down or comes back</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="notes" label="Notes" type="textarea" :value="$domain->notes" rows="6" help="For your eyes only." />
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center mb-4">
        <button type="submit" form="domain-form" class="btn btn-primary">Save</button>
        <a href="{{ $domain->exists ? route('domains.show', $domain) : route('domains.index') }}" class="btn btn-link">Cancel</a>

        @if ($domain->exists)
            @if ($checked)
                <span class="text-muted ms-auto">It has been checked, so it cannot be deleted. Untick Active to stop watching it.</span>
            @else
                <form method="POST" action="{{ route('domains.destroy', $domain) }}" class="ms-auto"
                    onsubmit="return confirm('Delete this domain? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Delete</button>
                </form>
            @endif
        @endif
    </div>
</x-layouts.app>
