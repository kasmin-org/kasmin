{{-- API reference rendered on the server from the OpenAPI document – no external scripts or fonts (privacy). --}}
@php
    $doc = is_array($spec) ? $spec : json_decode(json_encode($spec), true);
    $type = function (?array $schema): string {
        if ($schema === null) { return ''; }
        $t = $schema['type'] ?? ($schema['$ref'] ?? '');
        return is_array($t) ? implode('|', $t) : (string) $t;
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Kasmin REST API v1</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        body { font-family: system-ui, sans-serif; color: #14501c; background: #f3faf3; margin: 0; line-height: 1.5; }
        main { max-width: 960px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 1.6rem; margin: .5rem 0; } h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; border-bottom: 1px solid #cfe8d0; }
        .op { background: #fff; border-radius: 10px; padding: 12px 14px; margin: 10px 0; box-shadow: 0 1px 2px rgba(0,0,0,.06); overflow-wrap: anywhere; }
        .m { display: inline-block; min-width: 4.2em; font-weight: 700; font-family: ui-monospace, monospace; }
        .GET { color: #1d6fa5; } .POST { color: #1da029; } .PATCH { color: #b7791f; } .DELETE { color: #c53030; }
        code { font-family: ui-monospace, monospace; font-size: .92em; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; margin-top: 6px; } td, th { text-align: left; padding: 3px 6px; border-top: 1px solid #e6f2e6; vertical-align: top; }
        .muted { color: #4a6b4e; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <img src="/brand/kasmin-logo-horizontal.svg" alt="Kasmin" style="height:36px">
    <h1>{{ $doc['info']['title'] ?? 'Kasmin' }} – REST API v1</h1>
    <p class="muted">Version {{ $doc['info']['version'] ?? '' }} · Base URL <code>{{ url('/api/v1') }}</code> · OpenAPI document: <a href="{{ url('/docs/api.json') }}">/docs/api.json</a></p>
    <p>{{ $doc['info']['description'] ?? '' }}</p>
    @foreach (collect($doc['paths'] ?? [])->groupBy(fn ($ops, $path) => explode('/', trim($path, '/'))[0], true) as $group => $paths)
        <h2>{{ $group }}</h2>
        @foreach ($paths as $path => $operations)
            @foreach ($operations as $method => $op)
                @continue(! is_array($op) || ! isset($op['responses']))
                <div class="op">
                    <span class="m {{ strtoupper($method) }}">{{ strtoupper($method) }}</span> <code>{{ $path }}</code>
                    @if (! empty($op['summary']))<div><strong>{{ $op['summary'] }}</strong></div>@endif
                    @if (! empty($op['description']))<div class="muted">{{ $op['description'] }}</div>@endif
                    @php
                        $params = $op['parameters'] ?? [];
                        $body = $op['requestBody']['content']['application/json']['schema']['properties'] ?? [];
                        $required = $op['requestBody']['content']['application/json']['schema']['required'] ?? [];
                    @endphp
                    @if ($params !== [] || $body !== [])
                        <table>
                            <tr><th>Parameter</th><th>In</th><th>Type</th></tr>
                            @foreach ($params as $p)
                                <tr><td><code>{{ $p['name'] ?? '' }}</code>{{ ! empty($p['required']) ? ' *' : '' }}</td><td>{{ $p['in'] ?? '' }}</td><td>{{ $type($p['schema'] ?? null) }}</td></tr>
                            @endforeach
                            @foreach ($body as $name => $schema)
                                <tr><td><code>{{ $name }}</code>{{ in_array($name, $required, true) ? ' *' : '' }}</td><td>body</td><td>{{ $type($schema) }}</td></tr>
                            @endforeach
                        </table>
                    @endif
                </div>
            @endforeach
        @endforeach
    @endforeach
    <p class="muted">* required · Authentication: <code>Authorization: Bearer kas_…</code> (API token with the scope named in the description).</p>
</main>
</body>
</html>
