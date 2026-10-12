<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentStore;
use App\Attachments\HasAttachments;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to a record. The <x-attachments> component posts here by htmx, and every reply
 * that changes the list is the component's panel, drawn again.
 */
class AttachmentController extends Controller
{
    /**
     * Attach an uploaded file to the record named by `attachable_type` (its morph map name) and
     * `attachable_id`. A label left blank is the file's name.
     */
    public function store(Request $request): Response
    {
        $owner = $this->owner($request);

        abort_unless($owner->canChangeAttachments(), 403);

        $validator = Validator::make($request->all(), [
            'file' => [
                'required', 'file',
                'max:'.config('tkcrm.attachments.max_kb'),
                'mimes:'.implode(',', config('tkcrm.attachments.extensions')),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'file.max' => 'The file may not be larger than '.(config('tkcrm.attachments.max_kb') / 1024).' MB.',
            'file.mimes' => 'That kind of file cannot be attached. Allowed: '.implode(', ', config('tkcrm.attachments.extensions')).'.',
            'file.uploaded' => 'The file did not arrive. It may be larger than the server accepts.',
        ]);

        if ($validator->fails()) {
            return $this->panel($owner, 422, $validator->errors()->all(), $request->only('label', 'notes'));
        }

        $file = $request->file('file');

        app(AttachmentStore::class)->store($owner, $file, $file->getClientOriginalName(), $validator->validated()['label'] ?? null, $validator->validated()['notes'] ?? null);

        return $this->panel($owner);
    }

    /**
     * The file. An image or a PDF opens in the browser; anything else is a download, and
     * `?download=1` makes it one either way.
     */
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk(Attachment::DISK);

        abort_unless($disk->exists($attachment->path), 404);

        $headers = ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600'];

        return $attachment->showsInline() && ! $request->boolean('download')
            ? $disk->response($attachment->path, $attachment->file_name, $headers)
            : $disk->download($attachment->path, $attachment->file_name, $headers);
    }

    public function thumbnail(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk(Attachment::DISK);

        abort_if($attachment->thumbnail_path === null || ! $disk->exists($attachment->thumbnail_path), 404);

        return $disk->response($attachment->thumbnail_path, headers: ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function destroy(Attachment $attachment): Response
    {
        $owner = $attachment->attachable;

        abort_unless($owner->canChangeAttachments(), 403);

        $attachment->delete();

        return $this->panel($owner);
    }

    /**
     * The record being attached to. It must be a model in the morph map that takes attachments.
     */
    private function owner(Request $request): Model
    {
        $class = Relation::getMorphedModel((string) $request->input('attachable_type'));

        abort_if($class === null || ! in_array(HasAttachments::class, class_uses_recursive($class), true), 404);

        return $class::findOrFail((int) $request->input('attachable_id'));
    }

    /**
     * @param  list<string>  $problems
     * @param  array<string, mixed>  $typed
     */
    private function panel(Model $owner, int $status = 200, array $problems = [], array $typed = []): Response
    {
        return response(view('attachments.panel', ['owner' => $owner, 'problems' => $problems, 'typed' => $typed])->render(), $status);
    }
}
