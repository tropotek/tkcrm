{{--
    The attachments of $owner: the list, then the upload form. A record that cannot change its
    attachments now (a closed task) gets the list alone. Uploading and deleting are done by
    htmx, and each reply is this panel drawn again, with $problems when an upload was refused and
    $typed, the label and notes to put back.
--}}
@php
    $panelId = 'attachments-'.$owner->attachableType().'-'.$owner->getKey();
    $attachments = $owner->attachments()->get();
    $limits = config('tkcrm.attachments');
    $canChange = $owner->canChangeAttachments();
@endphp

<div id="{{ $panelId }}" class="attachments">
    @if ($attachments->isEmpty())
        <p @class(['text-muted', 'mb-0' => ! $canChange])>No files attached.</p>
    @else
        <ul class="list-unstyled mb-3">
            @foreach ($attachments as $attachment)
                <li class="d-flex align-items-center gap-3 py-2 border-bottom">
                    <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener" class="flex-shrink-0 text-center" style="width: 56px">
                        @if ($attachment->thumbnail_path)
                            <img src="{{ route('attachments.thumbnail', $attachment) }}" alt="" class="rounded" style="max-width: 56px; max-height: 56px">
                        @else
                            <i class="{{ $attachment->icon() }} font-24 text-muted"></i>
                        @endif
                    </a>
                    <div class="flex-grow-1 overflow-hidden">
                        <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener" class="fw-medium">{{ $attachment->label }}</a>
                        <div class="text-muted small text-truncate">
                            {{ $attachment->file_name }} · {{ $attachment->size() }} · <x-date :value="$attachment->created_at" format="j M Y" />
                        </div>
                        @if ($attachment->notes)
                            <div class="small">{{ $attachment->notes }}</div>
                        @endif
                    </div>
                    <div class="flex-shrink-0 text-nowrap">
                        <a href="{{ route('attachments.show', [$attachment, 'download' => 1]) }}" class="btn btn-sm btn-light" title="Download"><i class="ri-download-line"></i></a>
                        @if ($canChange)
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                hx-delete="{{ route('attachments.destroy', $attachment) }}" hx-target="#{{ $panelId }}" hx-swap="outerHTML"
                                hx-confirm="Delete {{ $attachment->label }}? This cannot be undone.">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canChange)
        @foreach ($problems as $problem)
            <div class="alert alert-danger py-2">{{ $problem }}</div>
        @endforeach

        <form hx-post="{{ route('attachments.store') }}" hx-encoding="multipart/form-data" hx-target="#{{ $panelId }}" hx-swap="outerHTML" class="row g-2 align-items-end">
            <input type="hidden" name="attachable_type" value="{{ $owner->attachableType() }}">
            <input type="hidden" name="attachable_id" value="{{ $owner->getKey() }}">

            <div class="col-md-4">
                <label for="{{ $panelId }}-file" class="form-label">Attach a file</label>
                <input type="file" id="{{ $panelId }}-file" name="file" required class="form-control"
                    accept="{{ collect($limits['extensions'])->map(fn ($extension) => '.'.$extension)->implode(',') }}">
            </div>
            <div class="col-md-3">
                <label for="{{ $panelId }}-label" class="form-label">Label</label>
                <input type="text" id="{{ $panelId }}-label" name="label" value="{{ $typed['label'] ?? '' }}" maxlength="255" class="form-control" placeholder="The file's name">
            </div>
            <div class="col-md-3">
                <label for="{{ $panelId }}-notes" class="form-label">Notes</label>
                <input type="text" id="{{ $panelId }}-notes" name="notes" value="{{ $typed['notes'] ?? '' }}" maxlength="2000" class="form-control">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-light w-100">Attach</button>
            </div>
            <div class="col-12 form-text">Up to {{ $limits['max_kb'] / 1024 }} MB: {{ implode(', ', $limits['extensions']) }}.</div>
        </form>
    @endif
</div>
