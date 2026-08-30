<?php

namespace App\Traits;

use App\Scopes\UserOwnedScope;

trait HasUserOwnedScope
{
    protected static function bootHasUserOwnedScope()
    {
        static::addGlobalScope(new UserOwnedScope);
    }

    public static function withoutUserOwnedScope()
    {
        return static::withoutGlobalScope(UserOwnedScope::class);
    }
}
