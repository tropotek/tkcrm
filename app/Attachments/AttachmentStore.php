<?php

namespace App\Attachments;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts a file on the private disk and attaches it to a record. The one way a file becomes an
 * attachment, whether it was uploaded or brought in by the importer.
 */
class AttachmentStore
{
    /**
     * @param  Model  $owner  a model that uses HasAttachments
     * @param  string  $name  the file's own name, as it was uploaded or found
     * @param  ?string  $label  what to call it; its name without the extension when left out
     */
    public function store(Model $owner, File|UploadedFile $file, string $name, ?string $label = null, ?string $notes = null): Attachment
    {
        $disk = Storage::disk(Attachment::DISK);
        $directory = "attachments/{$owner->attachableType()}/{$owner->getKey()}";

        // The name on disk is random, with an extension from the file's contents, never from what was typed.
        $random = Str::random(40);
        $path = $disk->putFileAs($directory, $file, $random.'.'.$file->extension());
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $thumbnailPath = null;

        if (str_starts_with($mime, 'image/')) {
            $thumbnail = Thumbnail::make($disk->get($path), config('tkcrm.attachments.thumbnail_px'));

            if ($thumbnail !== null) {
                $thumbnailPath = "{$directory}/{$random}_thumb.jpg";
                $disk->put($thumbnailPath, $thumbnail);
            }
        }

        return $owner->attachments()->create([
            'label' => filled($label) ? $label : pathinfo($name, PATHINFO_FILENAME),
            'file_name' => Str::limit(basename($name), 250, ''),
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'bytes' => $file->getSize(),
            'mime_type' => $mime,
            'notes' => $notes,
        ]);
    }

    /**
     * Delete the files under attachments/ that no attachment row names any more: what a wholesale
     * delete of rows, or a run that was rolled back, left on the disk.
     *
     * @return int how many files were deleted
     */
    public function sweep(): int
    {
        $disk = Storage::disk(Attachment::DISK);
        $kept = Attachment::query()->pluck('path')->merge(Attachment::query()->whereNotNull('thumbnail_path')->pluck('thumbnail_path'))->flip();
        $orphans = array_values(array_filter($disk->allFiles('attachments'), fn (string $path) => ! $kept->has($path)));

        $disk->delete($orphans);

        return count($orphans);
    }
}
