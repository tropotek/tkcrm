<?php

namespace App\Attachments;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * For a model that files can be attached to. The model must also be in the morph map
 * (AppServiceProvider), under the name its attachments are stored and uploaded by.
 * Its attachments, and their files, go when it is deleted.
 */
trait HasAttachments
{
    protected static function bootHasAttachments(): void
    {
        static::deleting(function (self $model) {
            // One at a time, so that each attachment's own delete removes its file.
            $model->attachments()->get()->each->delete();
        });
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('created_at')->orderBy('id');
    }

    /**
     * Whether files can be attached to it and its attachments deleted. A model that is read-only
     * at times (a closed task) says so here: the panel then only lists, and the controller refuses.
     */
    public function canChangeAttachments(): bool
    {
        return true;
    }

    /**
     * The model's name in the morph map, which is how the upload form says what it is attaching to.
     */
    public function attachableType(): string
    {
        $alias = array_search(static::class, Relation::morphMap(), true);

        return $alias !== false ? $alias : throw new LogicException(static::class.' is not in the morph map.');
    }
}
