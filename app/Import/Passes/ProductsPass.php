<?php

namespace App\Import\Passes;

use App\Enums\Cycle;
use App\Import\ImportContext;
use App\Import\Pass;
use Illuminate\Support\Facades\DB;

/**
 * tktask's product categories and products, with their ids. A product's price is already in
 * cents and is kept as it is; what has been billed copied its price at the time and is untouched.
 */
class ProductsPass implements Pass
{
    public function name(): string
    {
        return 'Products';
    }

    /**
     * Products only: their categories come with them and are not counted.
     */
    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('product')->count();
    }

    public function run(ImportContext $context): int
    {
        $source = $context->source->db();
        $categories = [];

        foreach ($source->table('product_category')->orderBy('product_category_id')->get() as $row) {
            DB::table('product_categories')->insert([
                'id' => $row->product_category_id,
                'name' => trim((string) $row->name),
                'description' => CompaniesPass::text($row->description),
                'active' => (bool) $row->active,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $categories[] = (int) $row->product_category_id;
        }

        $codes = [];
        $imported = 0;

        foreach ($source->table('product')->orderBy('product_id')->get() as $row) {
            $cycle = Cycle::tryFrom(trim((string) $row->cycle));

            if ($cycle === null) {
                $context->warn("Product {$row->product_id} has the cycle \"{$row->cycle}\", which tkcrm does not have. It was imported as a one-off.");
            }

            // A code is unique in tkcrm and is printed on invoice lines, so it is never left empty or shared.
            $code = trim((string) $row->code);

            if ($code === '' || isset($codes[mb_strtolower($code)])) {
                $was = $code === '' ? 'no code' : "the code {$code}, which product {$codes[mb_strtolower($code)]} already has";
                $code = ($code === '' ? 'PRODUCT' : $code).'-'.$row->product_id;
                $context->warn("Product {$row->product_id} has {$was}. It was given {$code}.");
            }

            $codes[mb_strtolower($code)] = $row->product_id;

            DB::table('products')->insert([
                'id' => $row->product_id,
                'product_category_id' => $this->category($context, $row, $categories),
                'name' => trim((string) $row->name),
                'code' => $code,
                'price' => (int) $row->price,
                'cycle' => ($cycle ?? Cycle::Each)->value,
                'description' => CompaniesPass::text($row->description),
                'notes' => CompaniesPass::text($row->notes),
                'active' => (bool) $row->active,
                'created_at' => $row->created,
                'updated_at' => $row->modified ?? $row->created,
            ]);

            $imported++;
        }

        return $imported;
    }

    /**
     * The product's category. tktask let a product have none; tkcrm does not, so such a product
     * goes into one made for the purpose.
     *
     * @param  list<int>  $categories  the ids that were imported; the made one is added to it
     */
    private function category(ImportContext $context, object $row, array &$categories): int
    {
        if (in_array((int) $row->product_category_id, $categories, true)) {
            return (int) $row->product_category_id;
        }

        $id = DB::table('product_categories')->where('name', 'Uncategorised')->value('id')
            ?? DB::table('product_categories')->insertGetId(['name' => 'Uncategorised', 'active' => true, 'created_at' => $row->created, 'updated_at' => $row->created]);

        $context->warn("Product {$row->product_id} has no category in tktask. It was put in Uncategorised.");

        return (int) $id;
    }
}
