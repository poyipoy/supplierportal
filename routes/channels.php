<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (bool) $user->is_active && (int) $user->id === (int) $id;
});
