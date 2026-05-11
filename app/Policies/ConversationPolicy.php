<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Hanya member conversation yang boleh melihat.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->isMember($user->id);
    }

    /**
     * Hanya purchasing yang boleh memulai conversation baru.
     */
    public function create(User $user): bool
    {
        return $user->role === 'purchasing';
    }

    /**
     * Hanya member conversation yang boleh mengirim pesan.
     */
    public function message(User $user, Conversation $conversation): bool
    {
        return $conversation->isMember($user->id);
    }
}
