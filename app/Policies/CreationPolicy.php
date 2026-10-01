<?php

namespace App\Policies;

use App\Enums\CreationStatus;
use App\Models\Creation;
use App\Models\User;

class CreationPolicy
{
    public function view(User $user, Creation $creation): bool
    {
        return $creation->user_id === $user->id;
    }

    /** Only finished creations can be deleted, so a running job never loses its row. */
    public function delete(User $user, Creation $creation): bool
    {
        return $this->view($user, $creation) && $creation->status->isFinished();
    }

    /** Only a failed creation can be re-run from its stored image. */
    public function retry(User $user, Creation $creation): bool
    {
        return $this->view($user, $creation) && $creation->status === CreationStatus::Failed;
    }
}
