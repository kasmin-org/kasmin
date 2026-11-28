<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('book_documents')]
#[Description('Books every invoice, credit note, payment and refund from the start of the first period on that has no booking yet, e.g. after a booking failed. Bookings that exist stay. Answers how many were made and what could not be booked.')]
class BookDocuments extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/book-documents'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
