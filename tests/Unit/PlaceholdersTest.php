<?php

namespace Tests\Unit;

use App\Mail\Placeholders;
use PHPUnit\Framework\TestCase;

class PlaceholdersTest extends TestCase
{
    public function test_a_placeholder_is_replaced_by_its_value_wherever_it_appears(): void
    {
        $this->assertSame(
            'Invoice 1042 for $10.00. Quote 1042 when paying.',
            Placeholders::fill('Invoice {invoice_number} for {total}. Quote {invoice_number} when paying.', ['invoice_number' => '1042', 'total' => '$10.00']),
        );
    }

    public function test_one_with_no_value_and_braces_that_are_not_a_placeholder_are_left_as_typed(): void
    {
        $this->assertSame(
            'Hello {nobody}, {} { name } {1st} Sam',
            Placeholders::fill('Hello {nobody}, {} { name } {1st} {name}', ['name' => 'Sam']),
        );
    }

    public function test_a_value_is_not_filled_a_second_time(): void
    {
        $this->assertSame('{b} B', Placeholders::fill('{a} {b}', ['a' => '{b}', 'b' => 'B']));
    }

    public function test_a_subject_stays_on_one_line(): void
    {
        $this->assertSame('Invoice 7 from Acme Books', Placeholders::fillLine(" Invoice {n}\nfrom {owner} ", ['n' => '7', 'owner' => "Acme\r\nBooks"]));
    }

    public function test_placeholders_that_are_not_known_are_found_once_each_as_typed(): void
    {
        $this->assertSame(
            ['{invoice_numbr}', '{company.name}', '{Client_Name}'],
            Placeholders::unknown('{client_name} {invoice_numbr} {company.name} {Client_Name} {invoice_numbr} {} {1}', ['client_name', 'invoice_number']),
        );

        $this->assertSame([], Placeholders::unknown('Hello {client_name}, no others.', ['client_name']));
    }

    public function test_text_is_split_into_paragraphs_at_blank_lines(): void
    {
        $this->assertSame(
            ['Hello Sam,', "Pay by transfer.\nBSB 000-000", 'Thanks.'],
            Placeholders::paragraphs("Hello Sam,\r\n\r\nPay by transfer.\r\nBSB 000-000\n\n\n  \n\nThanks.\n"),
        );

        $this->assertSame([], Placeholders::paragraphs(" \n\n "));
    }
}
