<?php

namespace App\Statements;

use App\Support\Owner;
use App\Support\Pdf;

/**
 * A statement as a document: its HTML, and the same HTML made into a PDF (App\Support\Pdf).
 * It prints the figures the Statement holds and works nothing out.
 */
class StatementPdf
{
    public function __construct(private Owner $owner) {}

    public function pdf(Statement $statement): string
    {
        return Pdf::render($this->html($statement), "{$this->owner->name()} · {$this->title($statement)} · Page {PAGE_NUM} of {PAGE_COUNT}");
    }

    /**
     * The document as dompdf will see it.
     */
    public function html(Statement $statement): string
    {
        return view('statements.document', [
            'statement' => $statement,
            'owner' => $this->owner,
            'title' => $this->title($statement),
        ])->render();
    }

    public function title(Statement $statement): string
    {
        return "Statement {$statement->from->format('j M Y')} to {$statement->to->format('j M Y')}";
    }

    public function filename(Statement $statement): string
    {
        return "statement-{$statement->company->account_number}-{$statement->from->toDateString()}-to-{$statement->to->toDateString()}.pdf";
    }
}
