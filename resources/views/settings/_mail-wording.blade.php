{{--
    The wording of each email to a client (App\Enums\MailTemplate) and the signature they share,
    inside the settings form. A field saved empty goes back to the default wording.

    $settings: every setting, as the form shows it.
--}}
@php
    use App\Enums\MailTemplate;

    $templates = MailTemplate::cases();

    // Open on the first message with something wrong in it.
    $open = collect($templates)->first(fn (MailTemplate $template) => $errors->hasAny([$template->subjectKey(), $template->bodyKey()]))?->value
        ?? ($errors->has(MailTemplate::SIGNATURE_KEY) ? 'signature' : $templates[0]->value);
@endphp

<div class="card" x-data="{ message: '{{ $open }}' }">
    <div class="card-body">
        <h4 class="header-title">Email wording</h4>
        <p class="text-muted mb-3">
            What each email to a client says. A name in braces, such as <code>{client_name}</code>, is replaced when the email is made.
            Save a field empty to go back to its default wording.
        </p>

        <ul class="nav nav-tabs nav-bordered mb-3">
            @foreach ($templates as $template)
                <li class="nav-item">
                    <a href="#" class="nav-link" :class="{ active: message === '{{ $template->value }}' }" @click.prevent="message = '{{ $template->value }}'">
                        {{ $template->label() }}
                        @if ($errors->hasAny([$template->subjectKey(), $template->bodyKey()]))
                            <i class="ri-error-warning-line text-danger"></i>
                        @endif
                    </a>
                </li>
            @endforeach
            <li class="nav-item">
                <a href="#" class="nav-link" :class="{ active: message === 'signature' }" @click.prevent="message = 'signature'">
                    Signature
                    @error(MailTemplate::SIGNATURE_KEY)
                        <i class="ri-error-warning-line text-danger"></i>
                    @enderror
                </a>
            </li>
        </ul>

        @foreach ($templates as $template)
            @php
                $subject = $template->subjectKey();
                $body = $template->bodyKey();
            @endphp
            <div class="row" x-show="message === '{{ $template->value }}'" @if ($open !== $template->value) x-cloak @endif>
                <div class="col-lg-8">
                    <p class="text-muted">{{ $template->description() }}</p>

                    <div class="mb-3">
                        <label for="{{ $subject }}" class="form-label">Subject</label>
                        <input type="text" id="{{ $subject }}" name="{{ $subject }}" value="{{ old($subject, $settings[$subject]) }}" maxlength="200"
                            @class(['form-control', 'is-invalid' => $errors->has($subject)])>
                        @error($subject)
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="{{ $body }}" class="form-label">Body</label>
                        <textarea id="{{ $body }}" name="{{ $body }}" rows="12"
                            @class(['form-control', 'is-invalid' => $errors->has($body)])>{{ old($body, $settings[$body]) }}</textarea>
                        @error($body)
                            <div class="invalid-feedback">{{ $message }}</div>
                        @else
                            <div class="form-text">Plain text. Leave a line blank between paragraphs. The signature follows it.</div>
                        @enderror
                    </div>

                    {{-- Only the wording is posted: the form's own fields would make this a PUT. --}}
                    <button type="button" class="btn btn-light mb-3" hx-post="{{ route('settings.mail-preview', $template) }}" hx-target="#app-modal-content"
                        hx-params="{{ $subject }},{{ $body }},{{ MailTemplate::SIGNATURE_KEY }}">
                        <i class="ri-eye-line me-1"></i>Preview
                    </button>
                </div>

                <div class="col-lg-4">
                    @include('settings._placeholders', ['placeholders' => $template->placeholders()])
                </div>
            </div>
        @endforeach

        <div class="row" x-show="message === 'signature'" @if ($open !== 'signature') x-cloak @endif>
            <div class="col-lg-8">
                <p class="text-muted">Ends every email to a client.</p>

                <div class="mb-3">
                    <label for="{{ MailTemplate::SIGNATURE_KEY }}" class="form-label">Signature</label>
                    <textarea id="{{ MailTemplate::SIGNATURE_KEY }}" name="{{ MailTemplate::SIGNATURE_KEY }}" rows="5"
                        @class(['form-control', 'is-invalid' => $errors->has(MailTemplate::SIGNATURE_KEY)])>{{ old(MailTemplate::SIGNATURE_KEY, $settings[MailTemplate::SIGNATURE_KEY]) }}</textarea>
                    @error(MailTemplate::SIGNATURE_KEY)
                        <div class="invalid-feedback">{{ $message }}</div>
                    @else
                        <div class="form-text">Plain text. It shows in each message's preview.</div>
                    @enderror
                </div>
            </div>

            <div class="col-lg-4">
                @include('settings._placeholders', ['placeholders' => MailTemplate::signaturePlaceholders()])
            </div>
        </div>
    </div>
</div>
