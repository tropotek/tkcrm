<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Jobs\SendMail;
use App\Mail\TestMessage;
use App\Models\Company;
use App\Models\User;
use App\Support\Owner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailSendingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The messages the array mailer has "sent".
     *
     * @return list<Email>
     */
    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->all();
    }

    /**
     * @return list<string>
     */
    private function addresses(array $list): array
    {
        return array_map(fn ($address) => $address->getAddress(), $list);
    }

    private function clientMessage(): Mailable
    {
        return new ClientMessage;
    }

    public function test_with_no_mail_settings_in_the_environment_mail_is_written_to_the_log(): void
    {
        // The suite forces its own mailer, so this reads what the config falls back to.
        $source = file_get_contents(config_path('mail.php'));

        $this->assertStringContainsString("'default' => env('MAIL_MAILER', 'log'),", $source);
        $this->assertSame('log', config('mail.mailers.log.transport'));

        // With them set, the server, port and login come from the environment alone.
        $this->assertSame('smtp', config('mail.mailers.smtp.transport'));

        foreach (["'host' => env('MAIL_HOST'", "'port' => env('MAIL_PORT'", "'username' => env('MAIL_USERNAME')", "'password' => env('MAIL_PASSWORD')"] as $read) {
            $this->assertStringContainsString($read, $source);
        }
    }

    public function test_a_message_is_queued_and_sent_by_the_job(): void
    {
        Queue::fake();

        SendMail::dispatch(new TestMessage('you@example.com'));

        Queue::assertPushed(SendMail::class, 1);
        $this->assertSame([], $this->sent());

        Queue::swap(app('queue'));
    }

    public function test_the_job_sends_the_message_from_the_owner_company_when_one_is_chosen(): void
    {
        SendMail::dispatchSync(new TestMessage('you@example.com'));

        $owner = Company::factory()->create(['name' => 'Kurrajong Web Services', 'email' => 'accounts@kurrajong.example.com']);
        Settings::set('owner_company_id', $owner->id);
        Settings::set('site_name', 'Kurrajong CRM');
        $this->app->forgetScopedInstances();

        SendMail::dispatchSync(new TestMessage('you@example.com'));

        [$before, $after] = $this->sent();

        $this->assertSame([config('mail.from.address')], $this->addresses($before->getFrom()));
        $this->assertSame(['accounts@kurrajong.example.com'], $this->addresses($after->getFrom()));
        $this->assertSame('Kurrajong Web Services', $after->getFrom()[0]->getName());
        $this->assertSame(['you@example.com'], $this->addresses($after->getTo()));
        $this->assertSame('A test email from Kurrajong CRM', $after->getSubject());
        $this->assertStringContainsString('Kurrajong CRM can send mail', $after->getTextBody());
    }

    public function test_a_failed_send_is_tried_three_more_times_with_a_longer_wait_each_time(): void
    {
        $job = new SendMail(new TestMessage('you@example.com'));

        $this->assertSame(4, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertCount($job->tries - 1, $job->backoff());
    }

    public function test_a_send_that_throws_leaves_the_job_to_be_tried_again(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Connection refused'));

        $this->expectExceptionMessage('Connection refused');

        (new SendMail(new TestMessage('you@example.com')))->handle(app(Owner::class));
    }

    public function test_mail_goes_where_it_is_addressed_when_no_redirect_is_set(): void
    {
        $this->assertNull(config('tkcrm.mail.redirect_to'));

        SendMail::dispatchSync($this->clientMessage());

        [$message] = $this->sent();
        $this->assertSame(['client@example.com'], $this->addresses($message->getTo()));
        $this->assertSame(['accounts@example.com'], $this->addresses($message->getCc()));
        $this->assertSame(['owner@example.com'], $this->addresses($message->getBcc()));
        $this->assertSame('Invoice 1042', $message->getSubject());
    }

    public function test_a_redirect_sends_every_message_to_one_address_and_says_so_in_the_subject(): void
    {
        config(['tkcrm.mail.redirect_to' => 'trial@example.com']);

        SendMail::dispatchSync($this->clientMessage());
        SendMail::dispatchSync(new TestMessage('you@example.com'));

        [$client, $test] = $this->sent();

        $this->assertSame(['trial@example.com'], $this->addresses($client->getTo()));
        $this->assertSame([], $client->getCc());
        $this->assertSame([], $client->getBcc());
        $this->assertSame('[Redirected, was for client@example.com; cc accounts@example.com; bcc owner@example.com] Invoice 1042', $client->getSubject());

        $this->assertSame(['trial@example.com'], $this->addresses($test->getTo()));
        $this->assertStringStartsWith('[Redirected, was for you@example.com] A test email from', $test->getSubject());
    }

    public function test_a_redirect_also_catches_mail_the_job_did_not_send(): void
    {
        config(['tkcrm.mail.redirect_to' => 'trial@example.com']);

        Mail::raw('Reset your password', fn ($message) => $message->to('someone@example.com')->subject('Reset'));

        [$message] = $this->sent();
        $this->assertSame(['trial@example.com'], $this->addresses($message->getTo()));
        $this->assertSame('[Redirected, was for someone@example.com] Reset', $message->getSubject());
    }

    public function test_the_settings_page_shows_how_mail_leaves_and_queues_a_test_message(): void
    {
        $user = User::factory()->create(['email' => 'you@example.com']);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example.com', 'mail.mailers.smtp.port' => 587]);

        $this->actingAs($user)
            ->get('/settings')
            ->assertSeeInOrder(['Mail', 'The mail server at mail.example.com, port 587', 'Sent from', config('mail.from.address'), 'Send a test email to you@example.com'])
            ->assertDontSee('Redirected');

        config(['mail.default' => 'log', 'tkcrm.mail.redirect_to' => 'trial@example.com']);
        $this->actingAs($user)
            ->get('/settings')
            ->assertSee('Nothing is sent: messages are written to the log')
            ->assertSee('Every message goes to trial@example.com');

        config(['mail.default' => 'array', 'tkcrm.mail.redirect_to' => null]);
        Queue::fake();

        $this->actingAs($user)
            ->post('/settings/test-mail')
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('success', 'A test email to you@example.com is queued. It is sent when the worker next runs.');

        Queue::assertPushed(SendMail::class, fn (SendMail $job) => $job->message instanceof TestMessage && $job->message->recipient === 'you@example.com');
    }

    public function test_a_guest_cannot_send_a_test_message(): void
    {
        Queue::fake();

        $this->post('/settings/test-mail')->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }
}

/**
 * A message to a client, with a copy and a blind copy, as the invoice email will be.
 */
class ClientMessage extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(to: ['client@example.com'], cc: ['accounts@example.com'], bcc: ['owner@example.com'], subject: 'Invoice 1042');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your invoice is attached.</p>');
    }
}
