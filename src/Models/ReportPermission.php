<?php

declare(strict_types=1);

namespace ElgiborSolution\AdvancedReports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ElgiborSolution\AdvancedReports\Models\Concerns\ResolvesConfiguredUserModel;

/**
 * Row-level permission grant on a report for a user or role.
 *
 * @property int $report_id
 * @property int|string|null $user_id
 * @property int|string|null $role_id
 * @property string $permission // view|edit|run|export|delete
 */
class ReportPermission extends BaseModel
{
    use ResolvesConfiguredUserModel;

    public const PERMISSION_VIEW = 'view';
    public const PERMISSION_EDIT = 'edit';
    public const PERMISSION_RUN = 'run';
    public const PERMISSION_EXPORT = 'export';
    public const PERMISSION_DELETE = 'delete';

    /** @var array<int,string> */
    public const ALL = [
        self::PERMISSION_VIEW,
        self::PERMISSION_EDIT,
        self::PERMISSION_RUN,
        self::PERMISSION_EXPORT,
        self::PERMISSION_DELETE,
    ];

    protected $guarded = [];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            $this->configuredUserModel(),
            'user_id'
        );
    }

    public function scopeForUser(Builder $query, int|string|null $userId): Builder
    {
        if ($userId === null) {
            return $query->whereNull('user_id');
        }

        return $query->where('user_id', $userId);
    }
}
