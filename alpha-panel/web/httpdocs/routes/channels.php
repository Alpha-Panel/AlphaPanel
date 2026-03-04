<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('admin', function ($user) {
    return $user->isAdmin();
});

Broadcast::channel('terminal.{sessionId}', function ($user, $sessionId) {
    if (! $user->isAdmin()) {
        return false;
    }

    $session = \Illuminate\Support\Facades\Cache::get('terminal:'.$sessionId);

    return $session && (int) $session['user_id'] === (int) $user->id;
});
