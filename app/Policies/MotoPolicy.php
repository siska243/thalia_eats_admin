<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Moto;
use Illuminate\Auth\Access\HandlesAuthorization;

class MotoPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_moto');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function view(User $user, Moto $moto): bool
    {
        return $user->can('view_moto');
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->can('create_moto');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function update(User $user, Moto $moto): bool
    {
        return $user->can('update_moto');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function delete(User $user, Moto $moto): bool
    {
        return $user->can('delete_moto');
    }

    /**
     * Determine whether the user can bulk delete.
     *
     * @param User $user
     * @return bool
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_moto');
    }

    /**
     * Determine whether the user can permanently delete.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function forceDelete(User $user, Moto $moto): bool
    {
        return $user->can('force_delete_moto');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     *
     * @param User $user
     * @return bool
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_moto');
    }

    /**
     * Determine whether the user can restore.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function restore(User $user, Moto $moto): bool
    {
        return $user->can('restore_moto');
    }

    /**
     * Determine whether the user can bulk restore.
     *
     * @param User $user
     * @return bool
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_moto');
    }

    /**
     * Determine whether the user can replicate.
     *
     * @param User $user
     * @param Moto $moto
     * @return bool
     */
    public function replicate(User $user, Moto $moto): bool
    {
        return $user->can('replicate_moto');
    }

    /**
     * Determine whether the user can reorder.
     *
     * @param User $user
     * @return bool
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_moto');
    }

}
