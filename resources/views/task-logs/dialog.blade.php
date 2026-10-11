{{--
    The add and edit form for a time log, in the shared dialog.
    $task: the task the time is for, or null when the dialog was opened from the list of all logs,
    which then asks for one of the open tasks.
    Its fields are filled from the request when it comes back with errors, and from the log otherwise.
    A new log starts now, on the default labour product, and billable if the setting says so.
--}}
@php
    use App\Facades\Settings;
    use App\Support\LocalTime;

    $posted = request()->isMethod('GET') ? null : request();

    $taskId = $posted ? $posted->input('task_id') : null;
    $productId = $posted ? $posted->input('product_id') : ($log->product_id ?? Settings::get('default_labour_product_id'));
    $started = $posted ? $posted->input('started_at') : LocalTime::of($log->started_at ?? now())->format('Y-m-d\TH:i');
    $billable = $posted ? $posted->boolean('billable') : ($log->exists ? $log->billable : Settings::get('time_logs_billable'));
    $comment = $posted ? $posted->input('comment') : $log->comment;

    $products = App\Models\Product::query()
        ->where(fn ($query) => $query->where('active', true)->orWhere('id', $log->product_id))
        ->orderByRaw('name COLLATE NOCASE')
        ->get(['id', 'name', 'price']);

    $tasks = $task ? collect() : App\Models\Task::query()
        ->select('tasks.*')
        ->join('companies', 'companies.id', '=', 'tasks.company_id')
        ->withStatus(App\Enums\TaskStatus::Open)
        ->with('company')
        ->orderByRaw('companies.name COLLATE NOCASE')
        ->orderByRaw('tasks.subject COLLATE NOCASE')
        ->get();
@endphp

<x-modal :title="$log->exists ? 'Edit time log' : 'Log time'"
    :action="$log->exists ? route('task-logs.update', $log) : route('task-logs.store')"
    :method="$log->exists ? 'put' : 'post'">
    @if ($task)
        <p class="mb-3"><span class="text-muted">{{ $task->company->name }}</span><br><span class="fw-medium">{{ $task->subject }}</span></p>
        @unless ($log->exists)
            <input type="hidden" name="task_id" value="{{ $task->id }}">
            <input type="hidden" name="task_fixed" value="1">
        @endunless
    @else
        <div class="mb-3">
            <label for="log_task_id" class="form-label">Task</label>
            <select id="log_task_id" name="task_id" required autofocus @class(['form-select', 'is-invalid' => $errors->has('task_id')])>
                <option value="">Choose…</option>
                @foreach ($tasks->groupBy('company.name') as $client => $clientTasks)
                    <optgroup label="{{ $client }}">
                        @foreach ($clientTasks as $choice)
                            <option value="{{ $choice->id }}" @selected((string) $choice->id === (string) $taskId)>{{ $choice->subject }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('task_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @else
                @if ($tasks->isEmpty())
                    <div class="form-text">There are no open tasks to log time on.</div>
                @endif
            @enderror
        </div>
    @endif

    <div class="row">
        <div class="col-sm-6">
            <div class="mb-3">
                <label for="log_started_at" class="form-label">Start</label>
                <input type="datetime-local" id="log_started_at" name="started_at" value="{{ $started }}" required
                    @class(['form-control', 'is-invalid' => $errors->has('started_at')])>
                @error('started_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
        <div class="col-sm-6">
            <x-duration name="duration" label="Duration" :minutes="$log->minutes" :autofocus="(bool) $task" />
        </div>
    </div>

    <div class="mb-3">
        <label for="log_product_id" class="form-label">Product</label>
        <select id="log_product_id" name="product_id" required @class(['form-select', 'is-invalid' => $errors->has('product_id')])>
            <option value="">Choose…</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((string) $product->id === (string) $productId)>{{ $product->name }} ({{ $product->price->format() }} an hour)</option>
            @endforeach
        </select>
        @error('product_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @else
            <div class="form-text">
                @if ($log->exists)
                    Logged at {{ $log->rate->format() }} an hour. It keeps that rate unless the product is changed.
                @else
                    The time is charged at this product's price for an hour, as it is now.
                @endif
            </div>
        @enderror
    </div>

    <div class="mb-3">
        <label for="log_comment" class="form-label">Comment</label>
        <textarea id="log_comment" name="comment" rows="3" @class(['form-control', 'is-invalid' => $errors->has('comment')])>{{ $comment }}</textarea>
        @error('comment')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="form-check">
        <input type="checkbox" id="log_billable" name="billable" value="1" class="form-check-input" @checked($billable)>
        <label for="log_billable" class="form-check-label">Billable</label>
    </div>

    <x-slot:footer>
        <button type="submit" class="btn btn-primary">Save</button>
    </x-slot:footer>
</x-modal>
