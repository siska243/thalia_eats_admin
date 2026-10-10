<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CommandeProduct;
use Illuminate\Auth\Access\HandlesAuthorization;

class CommandeProductPolicy
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
        return $user->can('view_any_commande::product');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function view(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('view_commande::product');
    }

    /**
     * Determine whether the user can create models.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $user->can('create_commande::product');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function update(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('update_commande::product');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function delete(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('delete_commande::product');
    }

    /**
     * Determine whether the user can bulk delete.
     *
     * @param User $user
     * @return bool
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_commande::product');
    }

    /**
     * Determine whether the user can permanently delete.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function forceDelete(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('force_delete_commande::product');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     *
     * @param User $user
     * @return bool
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_commande::product');
    }

    /**
     * Determine whether the user can restore.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function restore(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('restore_commande::product');
    }

    /**
     * Determine whether the user can bulk restore.
     *
     * @param User $user
     * @return bool
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_commande::product');
    }

    /**
     * Determine whether the user can replicate.
     *
     * @param User $user
     * @param CommandeProduct $commandeProduct
     * @return bool
     */
    public function replicate(User $user, CommandeProduct $commandeProduct): bool
    {
        return $user->can('replicate_commande::product');
    }

    /**
     * Determine whether the user can reorder.
     *
     * @param User $user
     * @return bool
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_commande::product');
    }

}
