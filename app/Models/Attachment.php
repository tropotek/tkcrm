<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * A file uploaded against a record. The file itself is on the private disk and is only ever sent
 * by AttachmentController, behind the login. Deleting the row deletes the file and its thumbnail.
 */
#[Fillable(['label', 'file_name', 'path', 'thumbnail_path', 'bytes', 'mime_type', 'notes'])]
class Attachment extends Model
{
    /** The disk attachments are kept on. */
    public const DISK = 'local';

    /** Kinds of file a browser may show in place. Anything else is sent as a download. */
    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    protected static function booted(): void
    {
        static::deleted(function (self $attachment) {
            Storage::disk(self::DISK)->delete(array_filter([$attachment->path, $attachment->thumbnail_path]));
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function showsInline(): bool
    {
        return in_array($this->mime_type, self::INLINE, true);
    }

    /**
     * "1.2 MB", "340 KB".
     */
    public function size(): string
    {
        return Number::fileSize($this->bytes, $this->bytes >= 1024 * 1024 ? 1 : 0);
    }

    /**
     * A Remix icon class for the kind of file, shown where there is no thumbnail.
     */
    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'ri-image-line',
            $this->mime_type === 'application/pdf' => 'ri-file-pdf-line',
            str_contains($this->mime_type, 'sheet') || str_contains($this->mime_type, 'excel') || $this->mime_type === 'text/csv' => 'ri-file-excel-line',
            str_contains($this->mime_type, 'word') || str_contains($this->mime_type, 'opendocument.text') => 'ri-file-word-line',
            str_contains($this->mime_type, 'zip') => 'ri-file-zip-line',
            default => 'ri-file-text-line',
        };
    }
}
