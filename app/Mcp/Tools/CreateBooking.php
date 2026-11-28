<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_booking')]
#[Description('Books a typed-in booking with any number of lines: debit and credit must add up to the same amount, the date must be in an open period. Income and expense lines get a cost centre (else the standard one), lines on the equity of the funds a fund (else the general fund). Bookings cannot be changed, only reversed: show the lines to the human first.')]
class CreateBooking extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/bookings'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('date (YYYY-MM-DD) in an open period')->required(),
            'text' => $schema->string()->description('text of the booking')->required(),
            'receipt' => $schema->string()->description('receipt number'),
            'lines' => $schema->array()->items($schema->object())->description('at least two lines: objects with account (id or exact name or account number), debit or credit (amount in the currency of the installation), cost_center and fund (id or exact name, optional), text (optional)')->required(),
        ];
    }
}
