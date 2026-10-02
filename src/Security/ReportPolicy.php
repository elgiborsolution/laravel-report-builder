<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Security;

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportPermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Laravel Gate policy for Report model.
 *
 * Rules:
 *  - view:   public reports or reports with explicit user/role permission.
 *  - edit:   creator or explicit permission.
 *  - run:    view + run permission.
 *  - export: creator or view + export permission.
 *  - delete: creator or explicit delete permission.
 */
class ReportPolicy
{
    use HandlesAuthorization;

    public function view(?object $user, Report $report): bool
    {
        if ($report->is_public) {
            return true;
        }

        if ($report->created_by && $user?->getAuthIdentifier() == $report->created_by) {
            return true;
        }

        return $this->hasPermission($user, $report, ReportPermission::PERMISSION_VIEW);
    }

    public function edit(?object $user, Report $report): bool
    {
        if ($report->created_by && $user?->getAuthIdentifier() == $report->created_by) {
            return true;
        }

        return $this->hasPermission($user, $report, ReportPermission::PERMISSION_EDIT);
    }

    public function run(?object $user, Report $report): bool
    {
        if (! $this->view($user, $report)) {
            return false;
        }

        return $this->hasPermission($user, $report, ReportPermission::PERMISSION_RUN)
            || $this->hasPermission($user, $report, ReportPermission::PERMISSION_VIEW);
    }

    public function export(?object $user, Report $report): bool
    {
        if ($report->created_by && $user?->getAuthIdentifier() == $report->created_by) {
            return true;
        }

        if (! $this->view($user, $report)) {
            return false;
        }

        return $this->hasPermission($user, $report, ReportPermission::PERMISSION_EXPORT)
            || $this->hasPermission($user, $report, ReportPermission::PERMISSION_VIEW);
    }

    public function delete(?object $user, Report $report): bool
    {
        if ($report->created_by && $user?->getAuthIdentifier() == $report->created_by) {
            return true;
        }

        return $this->hasPermission($user, $report, ReportPermission::PERMISSION_DELETE);
    }

    protected function hasPermission(?object $user, Report $report, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        $userId = $user->getAuthIdentifier();

        return ReportPermission::query()
            ->where('report_id', $report->id)
            ->where('permission', $permission)
            ->where(fn ($q) => $q->where('user_id', $userId)
                ->orWhereIn('role_id', $this->userRoles($user)))
            ->exists();
    }

    /**
     * Extract role IDs from the user. Supports Spatie roles, Sentinel,
     * or a simple getRoleAttribute convention.
     *
     * @return array<int, int|string>
     */
    protected function userRoles(object $user): array
    {
        // Spatie
        if (method_exists($user, 'roles') && method_exists($user, 'getRoleNames')) {
            return $user->roles()->pluck('id')->all();
        }

        // Generic attribute
        $roles = $user->roles ?? $user->role_ids ?? [];
        if ($roles instanceof \Illuminate\Database\Eloquent\Collection) {
            return $roles->pluck('id')->all();
        }
        if (is_array($roles)) {
            return array_map(fn ($r) => is_object($r) ? $r->id : $r, $roles);
        }

        return [];
    }
}
