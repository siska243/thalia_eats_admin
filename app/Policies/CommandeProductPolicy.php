<?php

namespace App\Policies;

use App\Models\CommandeProduct;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CommandeProductPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_commande::product');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('view_commande::product');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_commande::product');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('update_commande::product');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('delete_commande::product');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_commande::product');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('force_delete_commande::product');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_commande::product');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('restore_commande::product');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_commande::product');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('replicate_commande::product');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_commande::product');
    }
}
