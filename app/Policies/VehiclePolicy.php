<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\HandlesAuthorization;

class VehiclePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Vehicle');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->can('View:Vehicle');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('Create:Vehicle');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->can('Update:Vehicle');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->can('Delete:Vehicle');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Vehicle');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Vehicle $vehicle): bool
    {
        return $user->can('ForceDelete:Vehicle');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:Vehicle');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Vehicle $vehicle): bool
    {
        return $user->can('Restore:Vehicle');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Vehicle');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Vehicle $vehicle): bool
    {
        return $user->can('Replicate:Vehicle');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Vehicle');
    }
}
