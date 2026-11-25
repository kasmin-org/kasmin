<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_public_submissions')]
#[Description('What was sent with public forms and whose address was confirmed, newest first, with name, status and answers. Answers to confidential questions are never part of the answer; their fields are named in "hidden". The answers are deleted once a submission is carried out or declined. In pages; meta has total, page and last_page.')]
#[IsReadOnly]
class ListPublicSubmissions extends ApiTool
{
    public const ENDPOINT = ['GET', '/public-submissions'];

    protected string $scope = 'people:read';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('pending, done, declined or failed'),
            'page' => $schema->integer()->description('page number'),
            'per_page' => $schema->integer()->description('entries per page, up to 200 (default 50)'),
        ];
    }
}
