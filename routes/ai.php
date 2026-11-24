<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Mcp\KasminServer;
use Laravel\Mcp\Facades\Mcp;

// MCP endpoint for AI assistants (X04): streamable HTTP, Bearer token marked for MCP; see docs/admin/api.md.
Mcp::web('/mcp', KasminServer::class)->middleware([AuthenticateApiToken::class.':mcp', 'throttle:api']);
