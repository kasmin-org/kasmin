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

#[Name('update_sender_profile')]
#[Description('Changes the sender of the installation. All mails then come from the new sender, and replies go to the new reply-to address: show the human the change and ask first.')]
#[IsDestructive]
class UpdateSenderProfile extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/sender-profiles/{profile}'];

    protected string $scope = 'settings:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->argsWithClear($request, ['reply_to']), ['profile' => 'id']);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('profile id')->required(),
            'name' => $schema->string()->description('name of the profile'),
            'from_name' => $schema->string()->description('name shown as the sender'),
            'from_address' => $schema->string()->description('e-mail address of the sender'),
            'reply_to' => $schema->string()->description('address for replies'),
            'mail_account' => $schema->integer()->description('id of the mail account'),
            'clear' => $schema->array()->items($schema->string())->description('names of fields to empty: reply_to'),
        ];
    }
}
