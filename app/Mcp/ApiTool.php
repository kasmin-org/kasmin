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
 * Every tool names the endpoint it calls in ENDPOINT, so that a test can show that no endpoint is forgotten (AP01).
 */
abstract class ApiTool extends Tool
{
    /**
     * Method and route of the REST API v1 this tool calls: the URI exactly as in routes/api.php, without "v1".
     *
     * @var array{string, string}
     */
    public const ENDPOINT = ['', ''];

    /** Scope the token needs for this tool. */
    protected string $scope = '';

    /**
     * Scopes the endpoint needs in addition; the tool is only listed with all of them.
     *
     * @var list<string>
     */
    protected array $alsoNeeds = [];

    public function shouldRegister(): bool
    {
        foreach ($this->alsoNeeds as $scope) {
            if (! ApiContext::allows($scope)) {
                return false;
            }
        }

        return $this->scope === '' || ApiContext::allows($this->scope);
    }

    /**
     * Calls the endpoint of the tool. The placeholders of the route are filled from the arguments ($names:
     * placeholder => name of the argument, when they differ); the other arguments are the query or the body.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $names
     */
    protected function callEndpoint(array $args, array $names = []): Response
    {
        [$method, $uri] = static::ENDPOINT;
        $path = (string) preg_replace_callback('/\{(\w+)\}/', function (array $match) use (&$args, $names): string {
            $name = $names[$match[1]] ?? $match[1];
            $value = $args[$name] ?? '';
            unset($args[$name]);

            return rawurlencode(is_scalar($value) ? (string) $value : '');
        }, $uri);

        return $this->call($method, $path, $args);
    }

    /**
     * Records are named by their id or exact name in the argument $name (B8); clients of older versions sent their key
     * in the argument "key", which is still understood.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function withRecord(array $args, string $name): array
    {
        if (! array_key_exists($name, $args) && array_key_exists('key', $args)) {
            $args[$name] = $args['key'];
        }
        unset($args['key']);
        if (is_int($args[$name] ?? null)) {
            $args[$name] = (string) $args[$name];
        }

        return $args;
    }

    /**
     * The arguments, with the optional fields named in "clear" set to null: empty values are left out of the
     * arguments, so a field is emptied by name.
     *
     * @param  list<string>  $clearable
     * @return array<string, mixed>
     */
    protected function argsWithClear(Request $request, array $clearable): array
    {
        $args = $this->args($request);
        unset($args['clear']);
        foreach ((array) ($request->get('clear') ?? []) as $field) {
            if (is_string($field) && in_array($field, $clearable, true) && ! array_key_exists($field, $args)) {
                $args[$field] = null;
            }
        }

        return $args;
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
