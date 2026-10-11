{{--
    The add and edit form. $hasTasks: the project has tasks, so it cannot be deleted.
    The status is not a field: it follows from the three dates.
--}}
<x-layouts.app :title="$project->exists ? 'Edit '.$project->name : 'Add project'">
    <form id="project-form" method="POST" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}">
        @csrf
        @if ($project->exists)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-company-select name="company_id" label="Client" :selected="$project->company_id" type="client" required />
                        <x-field name="name" label="Name" :value="$project->name" required maxlength="255" />

                        <div class="mb-3">
                            <label for="quote" class="form-label">Quote</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" id="quote" name="quote" value="{{ old('quote', $project->quote->isZero() ? '' : $project->quote->toDecimal()) }}" inputmode="decimal"
                                    @class(['form-control', 'text-end', 'is-invalid' => $errors->has('quote')])>
                                @error('quote')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">What the client was quoted for the whole project. Leave it blank if there was no quote.</div>
                        </div>

                        <div class="row">
                            <div class="col-sm-4">
                                <x-field name="start_on" label="Start" type="date" :value="$project->start_on?->toDateString()" />
                            </div>
                            <div class="col-sm-4">
                                <x-field name="end_on" label="End" type="date" :value="$project->end_on?->toDateString()" />
                            </div>
                            <div class="col-sm-4">
                                <x-field name="cancelled_on" label="Cancelled" type="date" :value="$project->cancelled_on?->toDateString()" />
                            </div>
                            <div class="col-12 form-text mt-n2 mb-3">
                                The status follows from these: pending until the start date, active from then, completed once the end date has passed, and cancelled if it has a cancelled date.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body">
                        <x-field name="description" label="Description" type="textarea" :value="$project->description" rows="4" />
                        <x-field name="notes" label="Notes" type="textarea" :value="$project->notes" rows="5" help="For your eyes only. Never printed or sent." />
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center mb-4">
        <button type="submit" form="project-form" class="btn btn-primary">Save</button>
        <a href="{{ $project->exists ? route('projects.show', $project) : route('projects.index') }}" class="btn btn-link">Cancel</a>

        @if ($project->exists)
            @if ($hasTasks)
                <span class="text-muted ms-auto">It has tasks, so it cannot be deleted. Give it a cancelled date instead.</span>
            @else
                <form method="POST" action="{{ route('projects.destroy', $project) }}" class="ms-auto"
                    onsubmit="return confirm('Delete this project? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">Delete</button>
                </form>
            @endif
        @endif
    </div>
</x-layouts.app>
