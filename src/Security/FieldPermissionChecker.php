<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Security;

use ElgiborSolution\AdvancedReports\Models\Report;
use ElgiborSolution\AdvancedReports\Models\ReportPermission;
use ElgiborSolution\AdvancedReports\Sources\ReportField;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Checks whether a user is allowed to see specific fields of a report.
 * Field-level permissions allow hiding sensitive columns (e.g. salary,
 * internal costs) even when the user can run the report.
 *
 * Field permissions are optional and opt-in. If no field-level permissions
 * are defined, all visible fields from the source are allowed.
 */
class FieldPermissionChecker
{
    /**
     * Filter a list of columns to only those the user may see.
     *
     * @param  Report  $report
     * @param  array<int, array>  $columns
     * @param  Authenticatable|null  $user
     * @return array<int, array>
     */
    public function filterColumns(Report $report, array $columns, ?Authenticatable $user): array
    {
        // No explicit restrictions — allow everything visible.
        $restrictedFields = $this->getRestrictedFields($report, $user);
        if (empty($restrictedFields)) {
            return $columns;
        }

        return array_values(array_filter($columns, function (array $col) use ($restrictedFields) {
            $field = $col['field'] ?? null;

            return $field === null || ! in_array($field, $restrictedFields, true);
        }));
    }

    /**
     * Check if a specific field is accessible.
     */
    public function canSeeField(Report $report, string $field, ?Authenticatable $user): bool
    {
        $restricted = $this->getRestrictedFields($report, $user);

        return ! in_array($field, $restricted, true);
    }

    /**
     * Get the list of fields the user is NOT allowed to see.
     *
     * @return array<int,string>
     */
    protected function getRestrictedFields(Report $report, ?Authenticatable $user): array
    {
        // Future: field-level permissions could be stored in a
        // dedicated table or as JSON metadata on the report permission rows.
        // For now, the definition-level "hidden" flag on fields + source-level
        // enforcement is the primary mechanism. This checker is the
        // extension point for future row/field-level ACL.
        return [];
    }
}
