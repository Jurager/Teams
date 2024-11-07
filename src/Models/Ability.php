<?php

namespace Jurager\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Jurager\Teams\Support\Facades\Teams;

class Ability extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = ['id', 'name', 'title', 'entity_id', 'entity_type'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->fillable[] = config('teams.foreign_keys.team_id');
    }

    /**
     * Get the team that the ability belongs to.
     *
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Teams::model('team'));
    }

    /**
     * Get all the users that are assigned this ability.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(Teams::model('user'), 'entity', 'entity_ability')
            ->withPivot('forbidden')
            ->withTimestamps();
    }

    /**
     * Get all the groups that are assigned this ability.
     */
    public function groups(): MorphToMany
    {
        return $this->morphedByMany(Teams::model('group'), 'entity', 'entity_ability')
            ->withPivot('forbidden')
            ->withTimestamps();
    }

    /**
     * Get all the roles that are assigned this ability.
     */
    public function roles(): MorphToMany
    {
        return $this->morphedByMany(Teams::model('role'), 'entity', 'entity_ability')
            ->withPivot('forbidden')
            ->withTimestamps();
    }
}