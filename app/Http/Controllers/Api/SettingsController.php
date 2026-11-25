<?php

namespace App\Http\Controllers\Api;

use App\Support\Api\ApiContext;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings. Scope: settings:write. Bank data, public address, mail test mode, embedding and AI settings are not available here:
 * they could be misused to redirect payments, logins or data.
 */
class SettingsController extends ApiController
{
    private const RULES = [
        'organisation_name' => ['required', 'string', 'max:70'], 'organisation_address' => ['nullable', 'string', 'max:500'], 'organisation_email' => ['nullable', 'email'],
        'organisation_website' => ['nullable', 'url'], 'organisation_legal_footer' => ['nullable', 'string', 'max:1000'],
        'invoice_due_days' => ['required', 'integer', 'min:0', 'max:365'], 'invoice_reminder_days_before' => ['required', 'integer', 'min:0', 'max:365'],
        'invoice_overdue_days' => ['required', 'regex:/^\d+(\s*,\s*\d+)*$/'], 'invoice_attach_paid' => ['required', 'boolean'],
        'max_guardians' => ['required', 'integer', 'min:1', 'max:20'], 'mailing_approval_above' => ['required', 'integer', 'min:0', 'max:100000'],
        'digest_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'approval_days' => ['nullable', 'integer', 'min:1', 'max:60'],
    ];

    /** Settings that decide whether an approval is needed; via MCP they also need approvals:decide. */
    private const APPROVALS = ['mailing_approval_above'];

    /** The settings that can be changed with the API. */
    public function index(): JsonResponse
    {
        $this->scope('settings:write');

        return response()->json(['data' => array_map(fn (string $key) => Settings::get($key), array_combine(array_keys(self::RULES), array_keys(self::RULES)))]);
    }

    /**
     * Changes settings. Via MCP, mailing_approval_above (from how many recipients a mailing needs an approval)
     * also needs the scope approvals:decide.
     *
     * @throws AuthorizationException
     */
    public function update(Request $request): JsonResponse
    {
        $this->scope('settings:write');
        $unknown = array_diff(array_keys($request->all()), array_keys(self::RULES));
        abort_if($unknown !== [], 422, 'Not available: '.implode(', ', $unknown));
        $data = $request->validate(array_map(fn (array $rules) => ['sometimes', ...$rules], self::RULES));
        // An AI assistant must not switch an approval off (X05, MI12; see AutomationInput::protect).
        foreach (self::APPROVALS as $key) {
            if (array_key_exists($key, $data) && (string) $data[$key] !== (string) Settings::get($key) && ApiContext::viaMcp() && ! ApiContext::allows('approvals:decide')) {
                throw new AuthorizationException("Via MCP, changing {$key} needs the scope approvals:decide.");
            }
        }
        foreach ($data as $key => $value) {
            Settings::set($key, is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value));
        }
        Audit::record('settings.updated', null, null, ['section' => 'api', 'keys' => array_keys($data)]);

        return $this->index();
    }
}
