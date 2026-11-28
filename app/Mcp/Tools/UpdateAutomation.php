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

#[Name('update_automation')]
#[Description('Changes an automation: active, name, trigger, trigger_config and config (each of the two objects replaces the stored one; read get_automation first). Changing who approves and when (mode, max_items, approvals_needed, approver_roles), or the trigger and settings of an automation that runs without approval, also needs the permission approvals:decide. Show the human the change and ask first; never weaken an approval on your own.')]
#[IsDestructive]
class UpdateAutomation extends ApiTool
{
    public const ENDPOINT = ['PATCH', '/automations/{automation}'];

    protected string $scope = 'automations:write';

    public function handle(Request $request): Response
    {
        $args = $this->withRecord($this->args($request), 'automation');
        $path = '/automations/'.rawurlencode((string) ($args['automation'] ?? ''));
        unset($args['automation']);

        return $this->call('PATCH', $path, $args);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation' => $schema->string()->description('id or exact name of the automation, see list_automations')->required(),
            'active' => $schema->boolean()->description('switched on or off'),
            'name' => $schema->string()->description('name'),
            'trigger' => $schema->string()->description('manual, daily, weekly, monthly, once, before_event, after_event, on_place_freed, on_status, on_group_joined, on_group_left, on_person_created or on_person_changed'),
            'trigger_config' => $schema->object()->description('settings of the trigger, e.g. {"days": 60} or {"weekday": 2, "time": "07:15"}'),
            'config' => $schema->object()->description('settings of the action, as returned by get_automation: mail templates and forms by id or exact name; installment of an invoice run = position in the payment plan of the event: pos:1, pos:2, pos:3 … or pos:last'),
            'mode' => $schema->string()->description('automatic (runs without approval up to max_items), confirm (approvers decide) or manual (only started by a person). Needs approvals:decide'),
            'max_items' => $schema->integer()->description('above this number of items the approvers decide also in mode automatic. Needs approvals:decide'),
            'approvals_needed' => $schema->integer()->description('how many approvers have to approve. Needs approvals:decide'),
            'approver_roles' => $schema->array()->items($schema->string())->description('ids or exact names of the approver roles (see list_roles); an empty list means the default roles. Needs approvals:decide'),
        ];
    }
}
