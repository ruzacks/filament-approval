<?php

namespace Wezlo\FilamentApproval\Resources;

use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Wezlo\FilamentApproval\Concerns\HasApprovals;
use Wezlo\FilamentApproval\Enums\EscalationAction;
use Wezlo\FilamentApproval\Enums\StepType;
use Wezlo\FilamentApproval\FilamentApprovalPlugin;
use Wezlo\FilamentApproval\Models\ApprovalFlow;
use Wezlo\FilamentApproval\Resources\ApprovalFlowResource\Pages\CreateApprovalFlow;
use Wezlo\FilamentApproval\Resources\ApprovalFlowResource\Pages\EditApprovalFlow;
use Wezlo\FilamentApproval\Resources\ApprovalFlowResource\Pages\ListApprovalFlows;

class ApprovalFlowResource extends Resource
{
    protected static ?string $model = ApprovalFlow::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    public static function getModelLabel(): string
    {
        return __('filament-approval::approval.flow_resource_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-approval::approval.flow_resource_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return FilamentApprovalPlugin::resolveNavigationGroup();
    }

    public static function form(Schema $schema): Schema
    {
        $resolvers = FilamentApprovalPlugin::resolveApproverResolvers();

        return $schema->components([
            Section::make(__('filament-approval::approval.flow.flow_details'))->schema([
                TextInput::make('name')
                    ->label(__('filament-approval::approval.flow.name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label(__('filament-approval::approval.flow.description'))
                    ->rows(2),
                Select::make('approvable_type')
                    ->label(__('filament-approval::approval.flow.applies_to'))
                    ->options(fn () => static::getApprovableModels())
                    ->placeholder(__('filament-approval::approval.flow.any_model'))
                    ->searchable()
                    ->helperText(__('filament-approval::approval.flow.applies_to_helper')),
                Toggle::make('is_active')
                    ->label(__('filament-approval::approval.flow.is_active'))
                    ->default(true),
            ])->columns(2),

            Section::make(__('filament-approval::approval.flow.approval_steps'))->schema([
                Repeater::make('steps')
                    ->label(__('filament-approval::approval.flow_table.steps'))
                    ->relationship()
                    ->orderColumn('order')
                    ->schema(fn () => [
                        TextInput::make('name')
                            ->label(__('filament-approval::approval.flow.step_name'))
                            ->required()
                            ->columnSpan(2),
                        Select::make('type')
                            ->label(__('filament-approval::approval.flow.type'))
                            ->options(StepType::class)
                            ->default('single')
                            ->required()
                            ->live(),
                        Select::make('approver_resolver')
                            ->label(__('filament-approval::approval.flow.approver_type'))
                            ->options(collect($resolvers)->mapWithKeys(
                                fn (string $class) => [$class => $class::label()]
                            ))
                            ->required()
                            ->live(),

                        // Dynamic config fields from the selected resolver
                        ...static::buildResolverConfigFields($resolvers),

                        TextInput::make('required_approvals')
                            ->label(__('filament-approval::approval.flow.required_approvals'))
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('type') === 'parallel')
                            ->helperText(function (Get $get): ?string {
                                $config = [];

                                foreach (['user_ids', 'admin_ids', 'role', 'callback'] as $key) {
                                    $value = $get('approver_config.'.$key);

                                    if ($value !== null) {
                                        $config[$key] = $value;
                                    }
                                }

                                $count = null;

                                if (! empty($config)) {
                                    foreach ($config as $value) {
                                        if (is_array($value)) {
                                            $count = count($value);
                                            break;
                                        }
                                    }
                                }

                                if ($count) {
                                    $required = $get('required_approvals') ?: 1;

                                    return __('filament-approval::approval.flow.required_approvals_hint', ['required' => $required, 'total' => $count]);
                                }

                                return __('filament-approval::approval.flow.required_approvals_helper');
                            })
                            ->live(),
                        // TextInput::make('sla_hours')
                        //     ->numeric()
                        //     ->label(__('filament-approval::approval.flow.sla_hours'))
                        //     ->helperText(__('filament-approval::approval.flow.sla_helper'))
                        //     ->live(),
                        // Select::make('escalation_action')
                        //     ->label(__('filament-approval::approval.flow.escalation_action'))
                        //     ->options(EscalationAction::class)
                        //     ->visible(fn (Get $get): bool => filled($get('sla_hours'))),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->collapsible()
                    ->defaultItems(1)
                    ->addActionLabel(__('filament-approval::approval.flow.add_step'))
                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                        return static::normalizeStepData($data);
                    })
                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                        return static::normalizeStepData($data);
                    }),
            ]),
        ]);
    }

    /**
     * Build a Group per resolver, each visible only when that resolver is selected.
     * The fields inside use `approver_config.xxx` dot notation to write into the JSON column.
     *
     * @param  array<class-string>  $resolvers
     * @return array<Group>
     */
    protected static function buildResolverConfigFields(array $resolvers): array
    {
        $groups = [];

        foreach ($resolvers as $resolverClass) {
            $fields = $resolverClass::configSchema();

            if (empty($fields)) {
                continue;
            }

            $groups[] = Group::make()
                ->schema($fields)
                ->visible(fn (Get $get): bool => $get('approver_resolver') === $resolverClass)
                ->columnSpan(2);
        }

        return $groups;
    }

    /**
     * Ensure approver_config is properly structured as an array.
     * Handles both dot-notation expansion and missing values.
     */
    protected static function normalizeStepData(array $data): array
    {
        // If approver_config is already a proper array with content, keep it
        if (is_array($data['approver_config'] ?? null) && ! empty($data['approver_config'])) {
            return $data;
        }

        // Build approver_config from dot-notation keys (approver_config.user_ids etc.)
        $config = [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'approver_config.')) {
                $configKey = str_replace('approver_config.', '', $key);
                $config[$configKey] = $value;
                unset($data[$key]);
            }
        }

        $data['approver_config'] = $config ?: [];

        return $data;
    }

    /**
     * Get approvable models from resources registered in the current panel.
     *
     * @return array<string, string> FQCN => human label
     */
    protected static function getApprovableModels(): array
    {
        $models = [];

        $translations = [
            'FundRequest' => 'Permintaan Dana',
            'GeneratedDocument' => 'Dokumen Pengajuan',
            'Disbursement' => 'Pengeluaran Kas',
        ];

        foreach (Filament::getCurrentOrDefaultPanel()->getResources() as $resource) {
            $modelClass = $resource::getModel();

            if (in_array(HasApprovals::class, class_uses_recursive($modelClass))) {

                $label = $resource::getModelLabel();

                $models[$modelClass] = $translations[$label] ?? $label;
            }
        }

        return $models;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-approval::approval.flow_table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('approvable_type')
                    ->label(__('filament-approval::approval.flow_table.model'))
                    ->placeholder(__('filament-approval::approval.flow_table.any'))
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : __('filament-approval::approval.flow_table.any')),
                TextColumn::make('steps_count')
                    ->counts('steps')
                    ->label(__('filament-approval::approval.flow_table.steps')),
                IconColumn::make('is_active')
                    ->label(__('filament-approval::approval.flow_table.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('filament-approval::approval.flow_table.created_at'))
                    ->dateTime()
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalFlows::route('/'),
            'create' => CreateApprovalFlow::route('/create'),
            'edit' => EditApprovalFlow::route('/{record}/edit'),
        ];
    }
}
