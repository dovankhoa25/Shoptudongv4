<?php

namespace App\Traits;

use App\Scopes\ReceiverOwnedScope;

trait HasReceiverOwnedScope
{
    protected static function bootHasReceiverOwnedScope()
    {
        static::addGlobalScope(new ReceiverOwnedScope);
    }

    public static function withoutReceiverOwnedScope()
    {
        return static::withoutGlobalScope(ReceiverOwnedScope::class);
    }
}
