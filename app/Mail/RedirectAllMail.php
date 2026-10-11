<?php

namespace App\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * With MAIL_REDIRECT_TO set, every message goes to that one address instead of whoever it was
 * for, and its subject says who that was. It is for trial runs against real client data: nothing
 * can reach a client by mistake. It covers every message the app sends, whatever sends it.
 */
class RedirectAllMail
{
    public function handle(MessageSending $event): void
    {
        $to = config('tkcrm.mail.redirect_to');

        if (! $to) {
            return;
        }

        $message = $event->message;
        $addresses = fn (array $list) => implode(', ', array_map(fn (Address $address) => $address->getAddress(), $list));

        $meant = array_filter([
            $addresses($message->getTo()),
            $message->getCc() ? 'cc '.$addresses($message->getCc()) : null,
            $message->getBcc() ? 'bcc '.$addresses($message->getBcc()) : null,
        ]);

        $message->subject('[Redirected, was for '.implode('; ', $meant).'] '.$message->getSubject());
        $message->to($to);
        $message->getHeaders()->remove('Cc');
        $message->getHeaders()->remove('Bcc');
    }
}
