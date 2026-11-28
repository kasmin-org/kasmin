<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_automation')]
#[Description('Creates an own automation (target, conditions, steps such as sending a mail, changing a group or a status, creating an invoice). It is created switched off; switch it on with update_automation after the human has seen it. Without the permission approvals:decide leave out mode, max_items, approvals_needed and approver_roles: the automation then asks the approvers before every run (mode confirm), and other values are refused. A step that creates invoices also needs finance:write.')]
class CreateAutomation extends ApiTool
{
    public const ENDPOINT = ['POST', '/automations'];

    protected string $scope = 'automations:write';

    public function handle(Request $request): Response
    {
        return $this->callEndpoint($this->args($request));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('name')->required(),
            'trigger' => $schema->string()->description('manual, daily, weekly, monthly, once, before_event, after_event, on_place_freed, on_status, on_group_joined, on_group_left, on_person_created or on_person_changed')->required(),
            'trigger_config' => $schema->object()->description('settings of the trigger, e.g. {"days": 60} or {"weekday": 2, "time": "07:15"}'),
            'config' => $schema->object()->description('target {source: subject (for triggers that react to a change), event, group or people; statuses, roles, group_id}, conditions {match: all or any, rules: [{field: age, role, membership, group or events; value, min, max, not}]}, once, and steps: [{type: send_mail (template = id or exact name of a mail template, to), add_group or remove_group (group_id), set_status (status), notify_team (text), webhook (name) or invoice (description, amount)}]')->required(),
            'mode' => $schema->string()->description('automatic, confirm or manual. Needs approvals:decide'),
            'max_items' => $schema->integer()->description('above this number of items the approvers decide also in mode automatic. Needs approvals:decide'),
            'approvals_needed' => $schema->integer()->description('how many approvers have to approve. Needs approvals:decide'),
            'approver_roles' => $schema->array()->items($schema->string())->description('ids or exact names of the approver roles (see list_roles). Needs approvals:decide'),
        ];
    }
}
