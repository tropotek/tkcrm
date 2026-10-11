<?php

namespace App\Http\Controllers;

use App\Enums\MailKind;
use App\Mail\StatementMail;
use App\Models\Company;
use App\Models\MailLog;
use App\Statements\Statement;
use App\Statements\StatementPdf;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * A client's statement, from the company's page: the dialog chooses the days it covers, opens it
 * as a PDF, and previews and sends the email that carries it. The range begins as the current
 * financial year. Nothing goes without the Send button.
 */
class CompanyStatementController extends Controller
{
    /**
     * The dialog. It asks for itself again as the range or the note changes.
     */
    public function create(Request $request, Company $company): View
    {
        abort_unless($company->isClient(), 404);

        return $this->dialog($request, $company);
    }

    /**
     * The statement as a PDF, shown in the browser, or downloaded with `?download=1`.
     */
    public function pdf(Request $request, Company $company, StatementPdf $document): Response
    {
        abort_unless($company->isClient(), 404);

        [$from, $to, $problem] = $this->range($request);

        abort_if($problem !== null, 422, (string) $problem);

        $statement = Statement::for($company, $from, $to);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($document->pdf($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$document->filename($statement)}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Queue the email. The page loads again to say so.
     */
    public function store(Request $request, Company $company): Response
    {
        abort_unless($company->isClient(), 404);

        [$from, $to, $problem] = $this->range($request);
        $note = $this->note($request);
        $validator = Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']]);

        // Back in the dialog, with what is wrong.
        if ($validator->fails() || $problem || $this->emailProblem($company)) {
            return response($this->dialog($request, $company)->withErrors($validator)->render(), 422);
        }

        $log = MailLog::send(new StatementMail($company, $from, $to, $note), MailKind::Statement, $company);

        $request->session()->flash('success', 'The statement is queued to go to '.implode(', ', [...$log->to, ...$log->cc]).'. It is sent when the worker next runs.');

        return response()->noContent()->header('HX-Refresh', 'true');
    }

    private function dialog(Request $request, Company $company): View
    {
        [$from, $to, $rangeProblem] = $this->range($request);
        $note = $this->note($request);
        $problem = $rangeProblem ?? $this->emailProblem($company);

        $statement = $rangeProblem ? null : Statement::for($company, $from, $to);
        $mail = $problem ? null : new StatementMail($company, $from, $to, $note);

        // The message's parts, not the message: a view renders a Mailable it is given into a string.
        return view('companies.statement-dialog', [
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'statement' => $statement,
            'problem' => $problem,
            'needsEmail' => $rangeProblem === null && $problem !== null,
            'note' => $note,
            'envelope' => $mail?->envelope(),
            'documents' => $mail?->documents() ?? [],
            'html' => $mail?->filled()->html(),
            // The last time a statement was emailed, if one has been.
            'last' => MailLog::where('kind', MailKind::Statement)->whereMorphedTo('related', $company)->latest('id')->first(),
            'redirectTo' => config('tkcrm.mail.redirect_to'),
        ]);
    }

    /**
     * The first and last day asked for, each the current financial year's where none is given,
     * and what is wrong with them, or null.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function range(Request $request): array
    {
        $year = FinancialYear::current();
        $from = $request->string('from')->trim()->value() ?: $year->start()->toDateString();
        $to = $request->string('to')->trim()->value() ?: $year->end()->toDateString();

        $validator = Validator::make(['from' => $from, 'to' => $to], [
            'from' => ['date_format:Y-m-d'],
            'to' => ['date_format:Y-m-d', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'The statement cannot end before it starts.', 'date_format' => 'Give both days as dates.']);

        return [$from, $to, $validator->errors()->first() ?: null];
    }

    private function note(Request $request): ?string
    {
        return $request->string('note')->trim()->value() ?: null;
    }

    private function emailProblem(Company $company): ?string
    {
        return blank($company->email) ? "{$company->name} has no email address, so the statement cannot be emailed. It can still be opened as a PDF." : null;
    }
}
