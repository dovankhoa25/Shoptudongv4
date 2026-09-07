<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;

class ChatConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        if ($user->isLocked()) {
            return false;
        }

        if ((int) $conversation->customer_id === (int) $user->getKey()) {
            return true;
        }

        if ($user->status !== User::STATUS_ACTIVE
            || ! $user->isChatAgent()
            || ! $user->can('chats.view')) {
            return false;
        }

        return $user->canViewAllAdminData()
            || $conversation->isVisibleTo($user);
    }

    public function send(User $user, ChatConversation $conversation): bool
    {
        if ($user->isLocked() || $conversation->status === ChatConversation::STATUS_CLOSED) {
            return false;
        }

        if ((int) $conversation->customer_id === (int) $user->getKey()) {
            return true;
        }

        if ($user->status !== User::STATUS_ACTIVE
            || ! $user->isChatAgent()
            || ! $user->can('chats.reply')) {
            return false;
        }

        if ($user->canViewAllAdminData()) {
            return $user->can('chats.view');
        }

        return $conversation->isVisibleTo($user);
    }

    public function manage(User $user, ChatConversation $conversation): bool
    {
        if ($user->status !== User::STATUS_ACTIVE
            || ! $user->isChatAgent()
            || (! $user->can('chats.manage')
                && (! $user->can('chats.reply')
                    || (int) $conversation->assigned_to_id !== (int) $user->getKey()))) {
            return false;
        }

        if ($user->canViewAllAdminData()) {
            return $user->can('chats.view');
        }

        return $conversation->isVisibleTo($user);
    }

    public function assign(User $user, ChatConversation $conversation): bool
    {
        return $user->status === User::STATUS_ACTIVE
            && $user->canViewAllAdminData()
            && $user->can('chats.view')
            && $user->can('chats.assign');
    }
}
