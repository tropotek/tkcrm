<?php

namespace App\Mail;

use App\Facades\Settings;
use App\Models\Company;
use App\Support\Owner;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Who an email to a client goes to: the company's email, with a copy to its accounts email, and a
 * blind copy to the owner while the `bcc_owner` setting is on. No address gets it twice.
 *
 * The company must have an email: a message checks that before it is made.
 */
class ClientEnvelope
{
    public static function for(Company $company, string $subject): Envelope
    {
        $to = strtolower((string) $company->email);
        $cc = strtolower((string) $company->accounts_email);
        $bcc = Settings::get('bcc_owner') ? strtolower((string) app(Owner::class)->email()) : '';

        return new Envelope(
            to: [new Address($to, $company->name)],
            cc: $cc !== '' && $cc !== $to ? [new Address($cc)] : [],
            bcc: $bcc !== '' && ! in_array($bcc, [$to, $cc], true) ? [new Address($bcc)] : [],
            subject: $subject,
        );
    }
}
