<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('reverse_booking')]
#[Description('Reverses a booking with a counter-booking on the opposite sides, dated on the original date while its period is open, else today. A booking is reversed only once; allocations and closings are removed by reopening the period instead. Ask the human first.')]
#[IsDestructive]
class ReverseBooking extends ApiTool
{
    public const ENDPOINT = ['POST', '/accounting/bookings/{booking}/reverse'];

    protected string $scope = 'finance:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request), ['booking' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('booking id from list_bookings (not the booking number)')->required(),
            'text' => $schema->string()->description('text of the reversal (default "Reversal of no. …")'),
            'date' => $schema->string()->description('date of the reversal (YYYY-MM-DD)'),
        ];
    }
}
