<?php

namespace Jurager\Teams\Contracts;

interface InvitesTeamMembers
{
    /**
     * Invite a new team member to the given team.
     */
    public function invite(object $user, object $team, string $email, ?string $role = null): void;
}
