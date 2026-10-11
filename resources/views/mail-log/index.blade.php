{{--
    Every message tkcrm has sent or tried to send, newest first.
    $state, $kind: the App\Enums\MailState and MailKind the list is narrowed to, or null.
    $failed: how many messages have failed for good.
--}}
<x-layouts.app title="Mail log">
    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('mail-log.index') }}" class="row g-2 align-items-end mb-3">
                <div class="col-sm-4 col-lg-3">
                    <label for="state" class="form-label">State</label>
                    <select id="state" name="state" class="form-select" onchange="this.form.submit()">
                        <option value="">Any state</option>
                        @foreach (App\Enums\MailState::cases() as $choice)
                            <option value="{{ $choice->value }}" @selected($choice === $state)>{{ $choice->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-lg-3">
                    <label for="kind" class="form-label">Kind</label>
                    <select id="kind" name="kind" class="form-select" onchange="this.form.submit()">
                        <option value="">Every kind</option>
                        @foreach (App\Enums\MailKind::cases() as $choice)
                            <option value="{{ $choice->value }}" @selected($choice === $kind)>{{ $choice->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Filter</button>
                    <a href="{{ route('mail-log.index') }}" class="btn btn-link">Clear</a>
                </div>
                @if ($failed && $state !== App\Enums\MailState::Failed)
                    <div class="col-auto ms-auto">
                        <a href="{{ route('mail-log.index', ['state' => 'failed']) }}" class="btn btn-outline-danger">{{ $failed }} failed</a>
                    </div>
                @endif
            </form>

            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Queued</th>
                            <th>Kind</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @include('mail-log._rows', ['showRelated' => true])
                        @if ($messages->isEmpty())
                            <tr>
                                <td colspan="6" class="text-muted">{{ $state || $kind ? 'No messages match.' : 'Nothing has been sent yet.' }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if ($messages->hasPages())
                <div class="mt-3">{{ $messages->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
