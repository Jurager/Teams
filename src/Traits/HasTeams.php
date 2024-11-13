<?php

namespace Jurager\Teams\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Teams\Support\Facades\Teams;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Jurager\Teams\Models\Owner;

trait HasTeams
{
    /**
     * Check if the user owns the given team.
     *
     * @param object $team
     * @return bool
     */
    public function ownsTeam(object $team): bool
    {
        return $this->id === $team->{$this->getForeignKey()};
    }

    /**
     * Retrieve all teams the user owns or belongs to.
     *
     * @return Collection
     */
    public function allTeams(): Collection
    {
        return $this->ownedTeams->merge($this->teams)->sortBy('name');
    }

    /**
     * Retrieve all teams the user owns.
     *
     * @return HasMany
     */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Teams::model('team'))->withoutGlobalScopes();
    }


    /**
     * Retrieve all teams the user belongs to.
     *
     * @return BelongsToMany
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Teams::model('team'), Teams::model('membership'), 'user_id', config('teams.foreign_keys.team_id'))
            ->withoutGlobalScopes()
            ->withPivot('role_id')
            ->withTimestamps()
            ->as('membership');
    }

    /**
     * Retrieve abilities related to the user.
     *
     * @return MorphToMany
     */
    public function abilities(): MorphToMany
    {
        return $this->morphToMany(Teams::model('ability'), 'entity', 'entity_ability')
            ->withPivot('forbidden')
            ->withTimestamps();
    }

    /**
     * Retrieve all groups the user belongs to.
     *
     * @return BelongsToMany
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Teams::model('group'), 'group_user', 'user_id', 'group_id');
    }

    /**
     * Check if the user belongs to the specified team.
     *
     * @param object $team
     * @return bool
     */
    public function belongsToTeam(object $team): bool
    {
        return $this->ownsTeam($team) || $this->teams()->where(config('teams.foreign_keys.team_id', 'team_id'), $team->id)->exists();
    }

    /**
     * Retrieve the user's role in a team.
     *
     * @param object $team
     * @return mixed
     */
    public function teamRole(object $team): mixed
    {
        if ($this->ownsTeam($team)) {
            return new Owner();
        }

        return $this->belongsToTeam($team)
            ? $team->getRole($this->teams()->find($team->id)?->membership?->role_id)
            : null;
    }


    /**
     * Check if the user has the specified role on the team.
     *
     * @param object $team
     * @param string|array $roles
     * @param bool $require
     * @return bool
     */
    public function hasTeamRole(object $team, string|array $roles, bool $require = false): bool
    {
        if ($this->ownsTeam($team)) {
            return true;
        }

        $userRole = $this->teamRole($team)?->code;

        $roles = (array) $roles;

        return $require
            ? !array_diff($roles, [$userRole])
            : in_array($userRole, $roles, true);
    }

    /**
     * Get the user's permissions for the given team.
     *
     * @param object $team
     * @param string|null $scope Scope of permissions to get (ex. 'role', 'group'), by default getting all permissions
     * @return array|string[]
     */
    public function teamPermissions(object $team, string|null $scope = null): array
    {
        if ($this->ownsTeam($team)) {
            return ['*'];
        }

        $permissions = [];

        if (!$scope || $scope === 'role') {
            $permissions = array_merge($permissions, $this->teamRole($team)?->permissions?->pluck('code')?->toArray() ?? []);
        }

        if (!$scope || $scope === 'group') {
            $groupPermissions = $this->groups()->where(config('teams.foreign_keys.team_id', 'team_id'), $team->id)
                ->with('permissions')
                ->get()
                ->flatMap(fn($group) => $group->permissions->pluck('code'))
                ->toArray();
            $permissions = array_merge($permissions, $groupPermissions);
        }

        return array_unique($permissions);
    }

    /**
     * Determine if the user has the given permission on the given team.
     *
     * @param object $team
     * @param string|array $permissions
     * @param bool $require
     * @param string|null $scope Scope of permissions to check (ex. 'role', 'group'), by default checking all permissions
     * @return bool
     */
    public function hasTeamPermission(object $team, string|array $permissions, bool $require = false, string|null $scope = null): bool
    {
        //$require = true  (all permissions in the array are required)
        //$require = false  (only one or more permission in the array are required or $permissions is empty)

        if ($this->ownsTeam($team)) {
            return true;
        }

        $permissions = (array) $permissions;

        if (empty($permissions)) {
            return false;
        }

        $userPermissions = $this->teamPermissions($team, $scope);

        foreach ($permissions as $permission) {

            $hasPermission = $this->checkPermissionWildcard($userPermissions, $permission);

            if ($hasPermission && !$require) {
                return true;
            }

            if (!$hasPermission && $require) {
                return false;
            }
        }

        return $require;
    }

    /**
     * Get all ability that specific entity within team
     *
     * @param object $team
     * @param object $entity
     * @param bool $forbidden
     * @return mixed
     */
    public function teamAbilities(object $team, object $entity, bool $forbidden = false): mixed
    {
        // Start building the query to retrieve abilities
        $abilities = $this->abilities()->where([
            config('teams.foreign_keys.team_id', 'team_id') => $team->id,
            'abilities.entity_id' => $entity->id,
            'abilities.entity_type' => $entity::class
        ]);

        // If filtering by forbidden abilities, add the condition
        if ($forbidden) {
            $abilities->wherePivot('forbidden', true);
        }

        // Retrieve the abilities
        return $abilities->get();
    }

    /**
     * Determinate if user has global groups permissions
     *
     * This function is to verify permissions within a universal group.
     * Especially in cases where a team requires a group enabling user additions
     * and removals without direct affiliation with the team
     *
     * Example: Each team should have a global group of moderators.
     *
     * @param string $ability
     * @return bool
     */
    private function hasGlobalGroupPermissions(string $ability): bool
    {
        $permissions = $this->groups->whereNull(config('teams.foreign_keys.team_id', 'team_id'))
            ->load('permissions')
            ->flatMap(fn ($group) => $group->permissions->pluck('code'))
            ->toArray();

        return $this->checkPermissionWildcard($permissions, $ability);
    }

    /**
     * Determinate if user can perform an action
     *
     * @param object $team
     * @param string $permission
     * @param object $action_entity
     * @return bool
     */
    public function hasTeamAbility(object $team, string $permission, object $action_entity): bool
    {
        if ($this->ownsTeam($team) || (method_exists($action_entity, 'isOwner') && $action_entity->isOwner($this))) {
            return true;
        }

        $DEFAULT = 0;
        $FORBIDDEN = 1;
        $ROLE_ALLOWED = 2;
        $ROLE_FORBIDDEN = 3;
        $GROUP_ALLOWED = 4;
        $GROUP_FORBIDDEN = 5;
        $USER_ALLOWED = 5;
        $USER_FORBIDDEN = 6;
        $GLOBAL_ALLOWED = 6;

        $allowed = $DEFAULT;
        $forbidden = $FORBIDDEN;

        if ($this->hasTeamPermission($team, $permission, scope: 'role')) {
            $allowed = max($allowed, $ROLE_ALLOWED);
        }

        if ($this->hasTeamPermission($team, $permission, scope: 'group')) {
            $allowed = max($allowed, $GROUP_ALLOWED);
        }

        if ($this->hasGlobalGroupPermissions($permission)) {
            $allowed = max($allowed, $GLOBAL_ALLOWED);
        }

        $wildcards = collect(explode('.', $permission))
            ->reduce(function ($carry, $segment) {
                return array_merge($carry, [($carry ? implode('.', $carry) . '.' : '') . $segment . '.*']);
            }, []);

        $permission_ids = Teams::model('permission')::query()
            ->where(config('teams.foreign_keys.team_id', 'team_id'), $this->id)
            ->whereIn('code', [...$wildcards, $permission])
            ->pluck('id')
            ->all();

        $role = $this->teamRole($team)->load(['abilities' => function ($query) use ($action_entity, $permission_ids) {
            $query->where([
                'abilities.entity_id' => $action_entity->id,
                'abilities.entity_type' => get_class($action_entity),
            ])->whereIn('permission_id', $permission_ids);
        }]);

        $groups = $this->groups->where(config('teams.foreign_keys.team_id', 'team_id'), $team->id)->load(['abilities' => function ($query) use ($action_entity, $permission_ids) {
            $query->where([
                'abilities.entity_id' => $action_entity->id,
                'abilities.entity_type' => get_class($action_entity),
            ])->whereIn('permission_id', $permission_ids);
        }]);

        $this->load(['abilities' => function ($query) use ($action_entity, $permission_ids) {
            $query->where([
                'abilities.entity_id' => $action_entity->id,
                'abilities.entity_type' => get_class($action_entity),
            ])->whereIn('permission_id', $permission_ids);
        }]);

        foreach ([$role, ...$groups, $this] as $entity) {

            foreach ($entity->abilities as $ability) {

                if ($ability->pivot->forbidden) {
                    $forbidden = max($forbidden,$entity::class === Teams::model('role') ? $ROLE_FORBIDDEN : ($entity::class === Teams::model('group') ? $GROUP_FORBIDDEN : $USER_FORBIDDEN));
                } else {
                    $allowed = max($allowed,$entity::class === Teams::model('role') ? $ROLE_ALLOWED : ($entity::class === Teams::model('group') ? $GROUP_ALLOWED : $USER_ALLOWED));
                }
            }
        }

        return $allowed >= $forbidden;
    }


    /**
     * Allow user to perform an ability on entity
     *
     * @param object $team
     * @param string $permission
     * @param object $action_entity
     * @param object|null $target_entity
     * @return void
     */
    public function allowTeamAbility(object $team, string $permission, object $action_entity, object|null $target_entity = null): void
    {
        $this->updateAbilityOnEntity($team, 'syncWithoutDetaching', $permission, $action_entity, $target_entity);
    }

    /**
     * Forbid user to perform an ability on entity
     *
     * @param object $team
     * @param string $permission
     * @param object $action_entity
     * @param object|null $target_entity
     * @return void
     */
    public function forbidTeamAbility(object $team, string $permission, object $action_entity, object|null $target_entity = null): void
    {
        $this->updateAbilityOnEntity($team, 'syncWithoutDetaching', $permission, $action_entity, $target_entity, true);
    }

    /**
     * Delete user ability on entity
     *
     * @param object $team
     * @param string $permission
     * @param object $action_entity
     * @param object|null $target_entity
     * @return void
     */
    public function deleteTeamAbility(object $team, string $permission, object $action_entity, object|null $target_entity = null): void
    {
        $this->updateAbilityOnEntity($team, 'detach', $permission, $action_entity, $target_entity);
    }

    /**
     * Helper method for attaching or detaching ability to entity
     *
     * @param object $team
     * @param string $method
     * @param string $permission
     * @param object $action_entity
     * @param object|null $target_entity
     * @param bool $forbidden
     * @return void
     */
    private function updateAbilityOnEntity(object $team, string $method, string $permission, object $action_entity, object|null $target_entity = null, bool $forbidden = false): void
    {
        $abilityModel = Teams::instance('ability')->firstOrCreate([
            config('teams.foreign_keys.team_id', 'team_id') => $team->id,
            'entity_id' => $action_entity->id,
            'entity_type' => $action_entity::class,
            'permission_id' => $team->getPermissionIds([$permission])[0]
        ]);

        // Ensure the ability model is successfully retrieved or created
        if (! $abilityModel) {
            throw new ModelNotFoundException("Ability with permission '$permission' not found.");
        }

        // Target for ability defaults to user
        $targetEntity = $target_entity ?? $this;

        // Get relation name for ability
        $relation = $this->getRelationName($targetEntity);

        if (! method_exists($abilityModel, $relation)) {
            throw new ModelNotFoundException("Relation '$relation' not found on ability model.");
        }

        $abilityModel->{$relation}()->{$method}([$targetEntity->id => [
            'forbidden' => $forbidden,
        ]]);
    }

    /**
     * Get relation name for ability
     *
     * @param object|string $classname
     * @return string
     */
    private function getRelationName(object|string $classname): string
    {
        return  Str::plural(strtolower(class_basename( is_object($classname) ? $classname::class : $classname )));
    }

    /**
     * Check for wildcard permissions.
     */
    private function checkPermissionWildcard(array $userPermissions, string $permission): bool
    {
        // Generate all possible wildcards from the permission segments
        $wildcards = collect(explode('.', $permission))
            ->reduce(function ($carry, $segment) {
                return array_merge($carry, [($carry ? implode('.', $carry) . '.' : '') . $segment . '.*']);
            }, []);

        return !empty(array_intersect([...$wildcards, $permission], $userPermissions));
    }
}