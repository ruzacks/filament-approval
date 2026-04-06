# Filament Approval Workflow Engine

A configurable approval workflow package for Filament v5. Attach approval chains to any Eloquent model with single, sequential, or parallel approvers, SLA timers, escalation rules, delegation, and a full audit trail.

## Requirements

- PHP 8.4+
- Laravel 13+
- Filament 5+

## Features

- Polymorphic design -- attach approvals to any model via a trait
- Single, sequential, and parallel approval chains
- Configurable approval flows with a Filament resource UI
- Approve, reject, comment, and delegate actions
- SLA timers with auto-escalation (notify, auto-approve, reassign, reject)
- Delegation -- approvers can delegate to another user
- Full audit trail of every action
- Pluggable approver resolvers (specific users, roles, custom callbacks)
- Filament database notifications (requested, approved, rejected, escalated, SLA warning)
- Dashboard widgets (pending approvals table with clickable rows, analytics stats)
- Approval status badge column for resource tables
- Approvals relation manager (full history with slide-over detail view)
- Infolist section for current approval status at a glance
- Per-panel plugin configuration (user model, resolvers, navigation group)
- Scheduled command for SLA processing
- Publishable config and views

## Installation

```bash
composer require wezlo/filament-approval
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag=filament-approval-migrations
php artisan migrate
```

Ensure you have a `notifications` table (required for Filament database notifications):

```bash
php artisan make:notifications-table
php artisan migrate
```

Register the plugin in your Panel Provider:

```php
use Wezlo\FilamentApproval\FilamentApprovalPlugin;

->plugins([
    FilamentApprovalPlugin::make(),
])
```

You can override resolvers, user model, and navigation group per-panel:

```php
use Wezlo\FilamentApproval\FilamentApprovalPlugin;

// SuperAdmin panel -- uses Admin model and custom resolvers
->plugins([
    FilamentApprovalPlugin::make()
        ->userModel(\App\Models\Admin::class)
        ->resolvers([
            \App\ApproverResolvers\AdminResolver::class,
        ])
        ->navigationGroup('Admin Approvals'),
])

// Company panel -- uses defaults from config
->plugins([
    FilamentApprovalPlugin::make(),
])
```

Resolution order: **plugin override (per-panel)** > **config file (global)** > **default fallback**.

You can also disable the flow resource or widgets per-panel:

```php
FilamentApprovalPlugin::make()
    ->flowResource(false)  // hide the Approval Flows resource
    ->widgets(false)       // hide dashboard widgets
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=filament-approval-config
```

## Quick Start

### 1. Add the trait to your model

```php
use Wezlo\FilamentApproval\Concerns\HasApprovals;

class PurchaseOrder extends Model
{
    use HasApprovals;
}
```

This gives you:

```php
$order->submitForApproval();       // Submit using auto-detected flow
$order->submitForApproval($flow);  // Submit using a specific flow
$order->isPendingApproval();       // Check if pending
$order->isApproved();              // Check if approved
$order->isRejected();              // Check if rejected
$order->approvalStatus();          // Get ApprovalStatus enum
$order->latestApproval();          // Get latest Approval model
$order->currentApproval();         // Get current pending Approval
$order->approvals;                 // All approval instances
```

### 2. Add approval actions to your resource page

```php
use Wezlo\FilamentApproval\Concerns\HasApprovalsResource;

class ViewPurchaseOrder extends ViewRecord
{
    use HasApprovalsResource;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->getApprovalHeaderActions(),
            // your other actions...
        ];
    }
}
```

This adds five context-aware actions to the page header:
- **Submit for Approval** -- visible when no pending approval
- **Approve** -- visible to assigned/delegated approvers who haven't acted
- **Reject** -- same visibility, requires a comment
- **Comment** -- visible to assigned approvers during a pending approval
- **Delegate** -- visible to assigned approvers, lets them delegate to another user

### 3. Add the status column to your table

```php
use Wezlo\FilamentApproval\Columns\ApprovalStatusColumn;

public static function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('title'),
        ApprovalStatusColumn::make(),
        // ...
    ]);
}
```

Displays a colored badge: Pending (warning), Approved (success), Rejected (danger), Cancelled (gray).

### 4. Add approval history to your resource

**Relation Manager** -- full approval history tab on View/Edit pages:

```php
use Wezlo\FilamentApproval\RelationManagers\ApprovalsRelationManager;

public static function getRelations(): array
{
    return [
        ApprovalsRelationManager::class,
    ];
}
```

This adds an "Approvals" tab showing all approval instances. Clicking "View" on any row opens a slide-over with:
- Approval details (flow, status, submitter, dates)
- Step progress (each step with status, approvers, received/required count, SLA)
- Full audit trail (every action with who, what, when, comment)

**Infolist Section** -- current approval at a glance on View pages:

```php
use Wezlo\FilamentApproval\Infolists\ApprovalStatusSection;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        // your other entries...
        ApprovalStatusSection::make(),
    ]);
}
```

Shows a collapsible section with:
- Current status badge, flow name, submitter, dates
- Current step details (name, pending approvers, progress, SLA deadline with overdue highlighting)
- Recent activity timeline (collapsible)

The section auto-hides when there's no approval on the record.

### 5. Create an approval flow

Navigate to the **Approvals > Approval Flows** resource in your Filament panel. Create a flow with:

- **Name** -- e.g. "Purchase Order Approval"
- **Applies To** -- dropdown auto-populated with models that use `HasApprovals` and are registered as resources in the current panel. Leave blank to apply to any model.
- **Steps** -- ordered list with:
  - Step name
  - Type: Single / Sequential / Parallel
  - Approver type: dropdown of resolvers configured on the plugin (e.g. Specific Users, Users by Role, or your custom resolvers)
  - Approver config: dynamic fields based on the selected resolver (user picker, role selector, etc.)
  - Required approvals (visible for Parallel type, with "Require N of M" hint)
  - SLA hours (optional)
  - Escalation action (visible when SLA is set)

## Approval Flow Types

### Single

One approver, one approval required. The simplest flow.

### Sequential

Multiple steps executed in order. Step 2 doesn't activate until step 1 is approved. A rejection at any step rejects the entire approval and skips remaining steps.

### Parallel

Multiple approvers on a single step. Configure `required_approvals` to set how many must approve (e.g., 2-of-3). The step completes when the threshold is met.

## Approver Resolvers

Resolvers determine who can approve each step. Three are included:

### UserResolver

Assigns specific users by ID:

```
Approver Type: Specific Users
Config: Select users from the dropdown
```

### RoleResolver

Assigns all users with a given Spatie role. Optionally scoped to the approvable model's `company_id`:

```
Approver Type: Users by Role
Config: Select a role
```

### CallbackResolver

Register named callbacks in your service provider for custom logic:

```php
use Wezlo\FilamentApproval\ApproverResolvers\CallbackResolver;

// In AppServiceProvider::boot()
CallbackResolver::register('project_manager', function ($approvable) {
    return [$approvable->project->manager_user_id];
});

CallbackResolver::register('department_head', function ($approvable) {
    return [$approvable->department->head_user_id];
});
```

Then select "Custom Callback" as the approver type in the flow builder.

### Custom Resolvers

Implement the `ApproverResolver` contract:

```php
use Wezlo\FilamentApproval\Contracts\ApproverResolver;
use Illuminate\Database\Eloquent\Model;

class TeamLeadResolver implements ApproverResolver
{
    public function resolve(array $config, Model $approvable): array
    {
        return $approvable->team->leads->pluck('id')->all();
    }

    public static function label(): string
    {
        return 'Team Leads';
    }

    public static function configSchema(): array
    {
        return [
            // Filament form components for configuring this resolver
        ];
    }
}
```

Register it in `config/filament-approval.php`:

```php
'approver_resolvers' => [
    \Wezlo\FilamentApproval\ApproverResolvers\UserResolver::class,
    \Wezlo\FilamentApproval\ApproverResolvers\RoleResolver::class,
    \Wezlo\FilamentApproval\ApproverResolvers\CallbackResolver::class,
    \App\ApproverResolvers\TeamLeadResolver::class,
],
```

## SLA & Escalation

Configure SLA on any step in the flow builder:

- **SLA (hours)** -- deadline after the step is activated
- **Escalation action** -- what happens when the SLA is breached:
  - **Send Reminder** -- notifies approvers again
  - **Auto-Approve** -- automatically approves the step
  - **Auto-Reject** -- automatically rejects the entire approval
  - **Reassign** -- reassigns to different approvers (configure via escalation config)

The `approval:process-sla` command runs every minute (configurable) and:
1. Sends SLA warnings at 75% of the deadline (configurable threshold)
2. Processes escalations when the deadline is breached

The command is auto-scheduled by the package. To disable:

```php
// config/filament-approval.php
'schedule_sla_command' => false,
```

## Delegation

Any assigned approver can delegate their approval authority to another user. The delegate can then approve or reject on their behalf. Delegations are recorded in the audit trail.

## Submission Policy

By default, any authenticated user can submit any record for approval, and re-submission is allowed after approval or rejection. Override these methods on your model to customize:

### One-time approval (no re-submission)

```php
class Contract extends Model
{
    use HasApprovals;

    /**
     * Once approved or rejected, the submit button won't appear again.
     */
    public function allowsApprovalResubmission(): bool
    {
        return false;
    }
}
```

### Restrict who can submit

```php
class PurchaseOrder extends Model
{
    use HasApprovals;

    /**
     * Only the creator or admins can submit for approval.
     */
    public function canSubmitForApproval(?int $userId = null): bool
    {
        $userId ??= auth()->id();

        return $this->created_by === $userId
            || User::find($userId)?->hasRole('admin');
    }
}
```

### Combine both

```php
class Invoice extends Model
{
    use HasApprovals;

    public function allowsApprovalResubmission(): bool
    {
        // Allow resubmission only if previously rejected (not if approved)
        $latest = $this->latestApproval();

        return ! $latest || $latest->status !== ApprovalStatus::Approved;
    }

    public function canSubmitForApproval(?int $userId = null): bool
    {
        return $this->created_by === ($userId ?? auth()->id());
    }
}
```

The `canBeSubmittedForApproval()` method combines all checks (pending status + resubmission policy + user authorization) and is used by the Submit action's visibility logic.

## Audit Trail

Every action is recorded in the `approval_actions` table:

- Submitted
- Approved (with optional comment)
- Rejected (with required comment)
- Commented
- Delegated (with target user and reason)
- Escalated (with escalation action taken)

Access the audit trail:

```php
$approval = $order->latestApproval();
$actions = $approval->actions; // Collection of ApprovalAction models
```

## Notifications

The package sends Filament database notifications for:

- **Approval Requested** -- sent to each assigned approver when a step activates
- **Approval Approved** -- sent to the submitter when the full approval completes
- **Approval Rejected** -- sent to the submitter when rejected
- **SLA Warning** -- sent to approvers when approaching the deadline
- **Escalated** -- sent to approvers when the SLA is breached

## Dashboard Widgets

The plugin registers two widgets:

### PendingApprovalsWidget

A table showing the current user's pending approvals with step name, record reference, time waiting, and SLA status. Overdue items are highlighted in red.

### ApprovalAnalyticsWidget

Stats overview with:
- Pending approvals count
- Approved in last 30 days
- Rejected in last 30 days
- Overdue steps count

Disable widgets:

```php
FilamentApprovalPlugin::make()
    ->widgets(false)
```

Disable the flow resource:

```php
FilamentApprovalPlugin::make()
    ->flowResource(false)
```

## Blade Components

The package includes Blade components for custom views:

```blade
{{-- Approval timeline --}}
<x-filament-approval::components.approval-timeline :actions="$approval->actions" />

{{-- Status badge --}}
<x-filament-approval::components.approval-status-badge :status="$approval->status" />

{{-- Full approval history (pass the approvable record) --}}
@include('filament-approval::infolists.approval-history', ['record' => $order])
```

Publish views:

```bash
php artisan vendor:publish --tag=filament-approval-views
```

## Events & Model Callbacks

There are two ways to react to approval lifecycle events: **Laravel events** (for decoupled listeners) and **model callbacks** (for logic that belongs on the model itself).

### Laravel Events

Listen to these events via event listeners or subscribers:

```php
use Wezlo\FilamentApproval\Events\ApprovalSubmitted;
use Wezlo\FilamentApproval\Events\ApprovalStepCompleted;
use Wezlo\FilamentApproval\Events\ApprovalCompleted;
use Wezlo\FilamentApproval\Events\ApprovalRejected;
use Wezlo\FilamentApproval\Events\ApprovalEscalated;
```

Each event carries the relevant `Approval` or `ApprovalStepInstance` model.

### Model Lifecycle Callbacks

Override these methods on any model using `HasApprovals` to react directly on the model:

```php
use Wezlo\FilamentApproval\Concerns\HasApprovals;
use Wezlo\FilamentApproval\Models\Approval;
use Wezlo\FilamentApproval\Models\ApprovalAction;
use Wezlo\FilamentApproval\Models\ApprovalStepInstance;

class PurchaseOrder extends Model
{
    use HasApprovals;

    public function onApprovalSubmitted(Approval $approval): void
    {
        $this->update(['status' => 'pending_approval']);
    }

    public function onApprovalApproved(Approval $approval): void
    {
        $this->update(['status' => 'approved']);
        Mail::to($this->requester)->send(new OrderApprovedMail($this));
    }

    public function onApprovalRejected(Approval $approval): void
    {
        $this->update(['status' => 'rejected']);
    }

    public function onApprovalCancelled(Approval $approval): void
    {
        $this->update(['status' => 'draft']);
    }

    public function onApprovalCommented(ApprovalAction $action): void
    {
        // Notify the team about the comment
    }

    public function onApprovalDelegated(
        ApprovalStepInstance $stepInstance,
        int $fromUserId,
        int $toUserId,
    ): void {
        // Log delegation
    }

    public function onApprovalStepCompleted(ApprovalStepInstance $stepInstance): void
    {
        // Notify when a step passes
    }

    public function onApprovalEscalated(ApprovalStepInstance $stepInstance): void
    {
        // Alert management about SLA breach
    }
}
```

All callbacks are optional -- only override the ones you need. They are called after the action has been persisted to the database.

| Callback | When it fires | Arguments |
|---|---|---|
| `onApprovalSubmitted` | Model submitted for approval | `Approval` |
| `onApprovalApproved` | All steps approved | `Approval` |
| `onApprovalRejected` | Rejected at any step | `Approval` |
| `onApprovalCancelled` | Approval cancelled | `Approval` |
| `onApprovalCommented` | Comment added | `ApprovalAction` |
| `onApprovalDelegated` | Approver delegates | `ApprovalStepInstance`, `$fromUserId`, `$toUserId` |
| `onApprovalStepCompleted` | Individual step approved | `ApprovalStepInstance` |
| `onApprovalEscalated` | SLA breached | `ApprovalStepInstance` |

## Programmatic Usage

Use the `ApprovalEngine` service directly:

```php
use Wezlo\FilamentApproval\Services\ApprovalEngine;

$engine = app(ApprovalEngine::class);

// Submit
$approval = $engine->submit($order, $flow, auth()->id());

// Approve a step
$engine->approve($stepInstance, $userId, 'Looks good');

// Reject
$engine->reject($stepInstance, $userId, 'Budget exceeded');

// Comment
$engine->comment($approval, $userId, 'Please review section 3');

// Delegate
$engine->delegate($stepInstance, $fromUserId, $toUserId, 'On vacation');

// Cancel
$engine->cancel($approval);
```

## Configuration

```php
// config/filament-approval.php

return [
    'user_model' => \App\Models\User::class,

    'approver_resolvers' => [
        \Wezlo\FilamentApproval\ApproverResolvers\UserResolver::class,
        \Wezlo\FilamentApproval\ApproverResolvers\RoleResolver::class,
        \Wezlo\FilamentApproval\ApproverResolvers\CallbackResolver::class,
    ],

    'scope_approvers_to_company' => true,
    'sla_warning_threshold' => 0.75,    // 75% of SLA time
    'schedule_sla_command' => true,
    'navigation_group' => 'Approvals',
    'table_prefix' => '',
];
```

## Translations

The package ships with **English** and **Arabic** translations. All UI strings (labels, messages, notifications, enum values) are fully translated.

Publish translations to customize:

```bash
php artisan vendor:publish --tag=filament-approval-translations
```

This copies the language files to `lang/vendor/filament-approval/`. The translation file is organized by section:

- `status.*` -- Approval statuses (Pending, Approved, Rejected, Cancelled)
- `step_type.*` -- Step types (Single, Sequential, Parallel)
- `action_type.*` -- Audit trail actions (Submitted, Approved, Delegated, etc.)
- `escalation.*` -- Escalation actions (Send Reminder, Auto-Approve, etc.)
- `flow.*` -- Flow builder form labels
- `actions.*` -- Approval action buttons and modals
- `notifications.*` -- Database notification titles and bodies
- `widgets.*` -- Dashboard widget labels
- `relation_manager.*` -- Relation manager labels
- `infolist.*` -- Infolist section labels

To add a new language, create `lang/vendor/filament-approval/{locale}/approval.php` with the same structure.

## Custom Theme

If you have a custom Filament theme, add the package views to your `@source` directive:

```css
@source '../../../../vendor/wezlo/filament-approval/resources/views/**/*';
```

## Testing

```bash
php artisan test --filter=ApprovalEngine
```

## License

MIT
