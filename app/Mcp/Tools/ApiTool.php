<?php

namespace App\Mcp\Tools;

use App\Api\ApiData;
use App\Api\Page;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that answers what an endpoint of the read-only API does: it checks its arguments, hands
 * them to App\Api\ApiData as the endpoint's query string would have them, and returns the answer
 * whole. A tool names its arguments as the endpoint names its filters.
 *
 * No tool changes a record or sends anything: ApiData only reads.
 */
abstract class ApiTool extends Tool
{
    /** Whether the answer is a page of a list, so `page` and `per_page` are arguments too. */
    protected bool $paged = true;

    /**
     * The tool's own arguments: each with its type (string or integer), what it is for, the rules
     * it must pass, and the values it may take where they are a fixed few.
     *
     * @return array<string, array{type: string, description: string, rules: list<mixed>, enum?: list<string>}>
     */
    abstract protected function arguments(): array;

    /**
     * What the endpoint answers for a query.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    abstract protected function answer(ApiData $data, array $query): array;

    public function handle(Request $request, ApiData $data): ResponseFactory
    {
        $validated = $request->validate(array_map(fn (array $argument) => ['nullable', ...$argument['rules']], $this->everyArgument()));

        return Response::structured($this->answer($data, array_filter($validated, fn (mixed $value) => $value !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = [];

        foreach ($this->everyArgument() as $name => $argument) {
            $property = $argument['type'] === 'integer' ? $schema->integer() : $schema->string();

            if (isset($argument['enum'])) {
                $property = $property->enum($argument['enum']);
            }

            $properties[$name] = $property->description($argument['description']);
        }

        return $properties;
    }

    /**
     * @return array<string, array{type: string, description: string, rules: list<mixed>, enum?: list<string>}>
     */
    private function everyArgument(): array
    {
        return $this->arguments() + ($this->paged ? [
            'page' => ['type' => 'integer', 'description' => 'The page, from 1. "meta" in the answer says how many there are.', 'rules' => ['integer', 'min:1']],
            'per_page' => ['type' => 'integer', 'description' => 'How many to a page: '.Page::PER_PAGE.' unless given, '.Page::MAX_PER_PAGE.' at most.', 'rules' => ['integer', 'min:1', 'max:'.Page::MAX_PER_PAGE]],
        ] : []);
    }

    /**
     * Arguments many tools share, by name.
     *
     * @param  list<string>  $sorts  the columns this list can be ordered by
     * @return array<string, array{type: string, description: string, rules: list<mixed>, enum?: list<string>}>
     */
    protected function common(array $names, array $sorts = []): array
    {
        $all = [
            'q' => ['type' => 'string', 'description' => 'Only those with this text in them.', 'rules' => ['string', 'max:100']],
            'client' => ['type' => 'integer', 'description' => 'Only this client\'s. The id is from the clients tool.', 'rules' => ['integer', 'min:1']],
            'from' => ['type' => 'string', 'description' => 'The first day to include, as 2026-06-30, where the user is.', 'rules' => ['date_format:Y-m-d']],
            'to' => ['type' => 'string', 'description' => 'The last day to include, as 2026-06-30, where the user is.', 'rules' => ['date_format:Y-m-d']],
            'sort' => ['type' => 'string', 'description' => 'What to order by.', 'rules' => ['string', 'in:'.implode(',', $sorts)], 'enum' => $sorts],
            'dir' => ['type' => 'string', 'description' => 'asc or desc.', 'rules' => ['string', 'in:asc,desc'], 'enum' => ['asc', 'desc']],
        ];

        return array_intersect_key($all, array_flip($names));
    }

    /**
     * An argument that takes one of a fixed few values.
     *
     * @param  list<string>  $values
     * @return array{type: string, description: string, rules: list<mixed>, enum: list<string>}
     */
    protected function oneOf(array $values, string $description): array
    {
        return ['type' => 'string', 'description' => $description, 'rules' => ['string', 'in:'.implode(',', $values)], 'enum' => $values];
    }
}
