<?php

namespace App\Policies;

use App\Models\Stylization;
use App\Models\User;

class StylizationPolicy
{
    public function view(User $user, Stylization $stylization): bool
    {
        return $stylization->user_id === $user->id;
    }

    public function approve(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }

    public function retry(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }

    public function discard(User $user, Stylization $stylization): bool
    {
        return $this->view($user, $stylization);
    }
}
