<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\AnswersInDialog;
use App\Models\Invoice;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment against an unpaid invoice. The form is only ever in the shared dialog.
 */
class PaymentRequest extends FormRequest
{
    use AnswersInDialog;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', new MoneyAmount],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            // The day it was received, where the user is. Not a day still to come.
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.LocalTime::of(now())->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The amount against what is still owing: above zero, and no overpayment.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                if ($problem = $this->invoice()->paymentProblem($this->amount())) {
                    $validator->errors()->add('amount', $problem);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['received_on' => 'date received'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['received_on.before_or_equal' => 'The date received cannot be after today.'];
    }

    public function amount(): Money
    {
        return Money::parse((string) $this->input('amount'));
    }

    /**
     * The start of the day it was received, where the user is, as a moment.
     */
    public function receivedAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('received_on'), $this->user()->timezone ?? User::DEFAULT_TIMEZONE)->utc();
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->fromDialog()) {
            $this->failInDialog($validator, 'invoices.payment-dialog', ['invoice' => $this->invoice()]);
        }

        parent::failedValidation($validator);
    }

    private function invoice(): Invoice
    {
        return $this->route('invoice');
    }
}
