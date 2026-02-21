<?php

namespace App\Enums;

enum WidgetDenyReason: string
{
    case ORIGIN_MISMATCH = 'origin_mismatch';
    case ORIGIN_MISSING_BOOTSTRAP = 'origin_missing_bootstrap';
    case STALE_SESSION_NO_ORIGIN = 'stale_session_no_origin';
    case SSE_SESSION_LIMIT = 'sse_session_limit';
    case CURSOR_TOO_OLD = 'cursor_too_old';
    case CURSOR_AHEAD_OF_LATEST = 'cursor_ahead_of_latest';
    case REPLAY_TOO_LARGE = 'replay_too_large';
}
