<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_sender_profile')]
#[Description('Creates a sender profile (from name and address, reply-to) for an existing mail account. Mail accounts themselves are set up in the admin interface. Ask the human first.')]
class CreateSenderProfile extends ApiTool
{
    public const ENDPOINT = ['POST', '/sender-profiles'];

    protected string $scope = 'settings:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('name of the profile')->required(),
            'from_name' => $schema->string()->description('name shown as the sender')->required(),
            'from_address' => $schema->string()->description('e-mail address of the sender')->required(),
            'reply_to' => $schema->string()->description('address for replies'),
            'mail_account' => $schema->integer()->description('id of the mail account, see list_sender_profiles')->required(),
        ];
    }
}
