<?php

declare(strict_types=1);

namespace MaikSchneider\ApiKey\Key;

/**
 * Whom a key is bound to. The values are the names of the user columns.
 */
enum Identity: string
{
    case Service = 'service';
    case FrontendUser = 'fe_user';
    case BackendUser = 'be_user';
}
