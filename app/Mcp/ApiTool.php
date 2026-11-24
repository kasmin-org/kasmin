<?php

namespace App\Mcp;

use App\Support\Api\ApiContext;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * An MCP tool that calls the REST API v1 internally with the same token (X04): same scopes, validation and audit
 * log. The internal call is marked as MCP (request attribute, cannot be set by clients), so the audit log shows
 * "mcp" and the setting "AI processing allowed" applies (MI12). A tool is only listed if the token has its scope.
 */
abstract class ApiTool extends Tool
{
    /** Scope the token needs for this tool. */
    protected string $scope = '';

    public function shouldRegister(): bool
    {
        return $this->scope === '' || ApiContext::allows($this->scope);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function call(string $method, string $path, array $data = []): Response
    {
        $outer = request();
        $sub = HttpRequest::create('/api/v1'.$path, $method, $method === 'GET' ? $data : [], [], [], [
            'HTTP_AUTHORIZATION' => (string) $outer->header('Authorization'),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $outer->ip(),
        ], $method === 'GET' ? null : (string) json_encode($data));
        $sub->attributes->set('kasmin_channel', 'mcp');
        $token = ApiContext::token();
        $viaMcp = ApiContext::viaMcp();
        try {
            $response = app()->handle($sub);
        } finally {
            app()->instance('request', $outer);
            ApiContext::set($token, $viaMcp);
        }
        $body = (string) $response->getContent();
        if ($response->getStatusCode() >= 400) {
            $decoded = json_decode($body, true);

            return Response::error('HTTP '.$response->getStatusCode().': '.(is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : substr($body, 0, 500)));
        }
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'application/pdf')) {
            return Response::blob($body);
        }

        return Response::text($body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function args(Request $request): array
    {
        return array_filter($request->all(), fn ($v) => $v !== null && $v !== '');
    }
}
