<?php

namespace App\Listeners;

use App\Domains\DomainHistory;
use App\Enums\DomainState;
use App\Enums\MailKind;
use App\Events\DomainStateChanged;
use App\Mail\SiteAlertMail;
use App\Models\MailLog;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\Duration;
use App\Support\LocalTime;
use App\Support\NotificationFeed;

/**
 * Tells every login when a watched site goes down or comes back: one notification and one email
 * for the change, since the event is raised once for it and not for each check.
 *
 * Nothing is said for a site with its alerts off, or for a new site's first check finding it up.
 */
class SendSiteAlert
{
    public function handle(DomainStateChanged $event): void
    {
        $domain = $event->domain;

        if (! $domain->alerts) {
            return;
        }

        $host = $domain->host();
        $client = $domain->company->name;
        $link = route('domains.show', $domain);
        $now = LocalTime::of(now());

        if ($event->to === DomainState::Down) {
            $why = $domain->pings()->first()?->error;
            $began = DomainHistory::outageBegan($domain);

            $headline = "{$host} is down";
            $lines = array_values(array_filter([
                "{$client}'s site failed {$domain->failures} checks in a row".($began ? ', the first at '.LocalTime::of($began)->format('g:ia \o\n j M') : '').'.',
                $why ? "The last one: {$why}." : null,
                $domain->last_ok_at ? 'It last answered at '.LocalTime::of($domain->last_ok_at)->format('g:ia \o\n j M').'.' : 'It has never answered.',
            ]));
            $icon = 'ri-error-warning-line';
        } elseif ($event->to === DomainState::Up && $event->from === DomainState::Down) {
            // From the first check that failed, not from the second, which is when it was called down.
            $began = DomainHistory::outageBegan($domain, before: now()->toImmutable()) ?? $event->since;
            $minutes = $began ? max(1, (int) $began->diffInMinutes(now())) : null;

            $headline = "{$host} is back up".($minutes ? ' after '.Duration::format($minutes) : '');
            $lines = array_values(array_filter([
                "{$client}'s site answered at ".$now->format('g:ia \o\n j M').'.',
                $began ? 'It had been down since '.LocalTime::of($began)->format('g:ia \o\n j M').', '.Duration::format($minutes).'.' : null,
            ]));
            $icon = 'ri-checkbox-circle-line';
        } else {
            // A new site's first check finding it up is not news.
            return;
        }

        NotificationFeed::tellEveryone(new Notice($headline, implode(' ', $lines), route('domains.show', $domain, false), $icon));

        $recipients = User::orderBy('id')->pluck('email')->all();

        if ($recipients !== []) {
            MailLog::send(new SiteAlertMail($recipients, $headline, $lines, $link), MailKind::SiteAlert, $domain);
        }
    }
}
