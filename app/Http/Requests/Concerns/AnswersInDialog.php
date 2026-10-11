<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * For a form request whose form can be posted from the shared dialog. Such a form comes back into
 * the dialog with its errors, as a 422 that htmx swaps in. The dialog's view fills its fields from
 * the request, so nothing is flashed to the session.
 */
trait AnswersInDialog
{
    public function fromDialog(): bool
    {
        return $this->header('HX-Target') === 'app-modal-content';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function failInDialog(Validator $validator, string $view, array $data = []): never
    {
        throw new HttpResponseException(response(view($view, $data)->withErrors($validator)->render(), 422));
    }
}
