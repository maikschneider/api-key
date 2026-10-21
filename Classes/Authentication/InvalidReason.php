<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Authentication;

/**
 * Why a presented key was rejected. Meant for logging and debugging; clients
 * should get the same 401 response for every reason.
 */
enum InvalidReason: string
{
    case Malformed = 'malformed';
    case Ambiguous = 'ambiguous';
    case KeyInQueryString = 'key_in_query_string';
    case Unknown = 'unknown';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case IpNotAllowed = 'ip_not_allowed';
    case SiteNotAllowed = 'site_not_allowed';
    case UserUnavailable = 'user_unavailable';
    case Misconfigured = 'misconfigured';
}
