<?php

namespace Zain\WebhookLedger\Exceptions;

use InvalidArgumentException;

class UnknownProviderException extends InvalidArgumentException
{
    public function __construct(string $provider)
    {
        parent::__construct(
            "No webhook-ledger configuration for provider [{$provider}]. ".
            'Add it under the "providers" key in config/webhook-ledger.php.'
        );
    }
}
