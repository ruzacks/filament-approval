<?php

namespace Wezlo\FilamentApproval\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalFlow extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('order');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function scopeForModel(Builder $query, Model $model): Builder
    {
        $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($model) {
                $q->where('approvable_type', $model->getMorphClass())
                    ->orWhereNull('approvable_type');
            });

        if (config('filament-approval.multi_tenancy.enabled', false)) {
            $column = config('filament-approval.multi_tenancy.column', 'company_id');
            $query->when($model->{$column} ?? null, fn ($q, $id) => $q->where($column, $id));
        }

        return $query;
    }
}
