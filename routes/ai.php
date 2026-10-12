<?php

use App\Http\Controllers\ApiTokenController;
use App\Mcp\TkcrmServer;
use Laravel\Mcp\Facades\Mcp;

/*
| The MCP server, for an assistant such as Claude. It takes the same personal access token as the
| API (made on the profile page, with the `read` ability). Its tools only read.
*/

Mcp::web('/mcp', TkcrmServer::class)->middleware(['auth:sanctum', 'abilities:'.ApiTokenController::ABILITY, 'throttle:api'])->name('mcp');
