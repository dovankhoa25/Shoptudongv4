<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class ReceiverOwnedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check() || ! request()->is('admin', 'admin/*')) {
            return;
        }

        $user = Auth::user();

        if ($user->canViewAllAdminData()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('receiver_id'),
            $user->getAuthIdentifier(),
        );
    }
}
