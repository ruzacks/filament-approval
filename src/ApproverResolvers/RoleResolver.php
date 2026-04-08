<?php

namespace Wezlo\FilamentApproval\ApproverResolvers;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use Wezlo\FilamentApproval\Contracts\ApproverResolver;
use Wezlo\FilamentApproval\FilamentApprovalPlugin;

class RoleResolver implements ApproverResolver
{
    public function resolve(array $config, Model $approvable): array
    {
        $userModel = FilamentApprovalPlugin::resolveUserModel();
        $roleName = $config['role'] ?? null;

        if (! $roleName) {
            return [];
        }

        $query = $userModel::role($roleName);

        if (config('filament-approval.multi_tenancy.enabled', false) && config('filament-approval.multi_tenancy.scope_approvers', true)) {
            $column = config('filament-approval.multi_tenancy.column', 'company_id');

            if (isset($approvable->{$column})) {
                $query->where($column, $approvable->{$column});
            }
        }

        return $query->pluck('id')->all();
    }

    public static function label(): string
    {
        return __('filament-approval::approval.resolvers.role');
    }

    public static function configSchema(): array
    {
        return [
            Select::make('approver_config.role')
                ->label(__('filament-approval::approval.resolver_config.role'))
                ->searchable()
                ->options(fn () => Role::pluck('name', 'name'))
                ->required(),
        ];
    }
}
