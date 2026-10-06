<?php

namespace App\Policies;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use App\Models\User;

class ClickerPolicy
{
    public function view(User $user, Clicker $clicker): bool
    {
        return $clicker->user_id === $user->id;
    }

    /** A paid design is frozen, and so is one that is being built. Duplicate it to change it. */
    public function update(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker) && ! $clicker->isUnlocked() && $clicker->status !== ClickerStatus::Building;
    }

    /** A running build never loses its row. */
    public function delete(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker) && $clicker->status !== ClickerStatus::Building;
    }

    /** Only a design that has not been paid for (a draft, or one whose build failed and was refunded). */
    public function unlock(User $user, Clicker $clicker): bool
    {
        return $this->view($user, $clicker)
            && ! $clicker->isUnlocked()
            && in_array($clicker->status, [ClickerStatus::Draft, ClickerStatus::Failed], true);
    }
}
