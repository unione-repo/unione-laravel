<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Facades;

use Illuminate\Support\Facades\Facade;
use UniOne\UniOneClient;

/**
 * UniOne API client of the container: UniOne::email()->send(...).
 *
 * @mixin UniOneClient
 */
final class UniOne extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UniOneClient::class;
    }
}
