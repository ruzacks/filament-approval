<?php

namespace Wezlo\FilamentApproval\Notifications;

use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Wezlo\FilamentApproval\FilamentApprovalPlugin;
use Wezlo\FilamentApproval\Models\Approval;
use Filament\Actions\Action;
use Filament\Facades\Filament;

class ApprovalApprovedNotification
{
    public static function send(Approval $approval, int $userId): void
    {
        $userModel = FilamentApprovalPlugin::resolveUserModel();
        $recipient = $userModel::find($userId);

        if (! $recipient) {
            return;
        }

        $approvable = $approval->approvable;
        $modelLabel = class_basename($approvable);

        $record = $approval->approvable;
        $resource = Filament::getModelResource(
            $record::class
        );

        $docNumber = $approvable->getKey();
        if ($modelLabel === "FundRequest") {
            $modelLabel = "Permintaan Pengeluaran Dana";
            $docNumber = $approvable->document_number;
        } else if ($modelLabel === "GeneratedDocument") {
            $modelLabel = "Permintaan Pengeluaran Dana";
            $docNumber = $approvable->title;
        }

        Notification::make()
            ->title(__('filament-approval::approval.notifications.approved_title'))
            ->body(__('filament-approval::approval.notifications.approved_body', ['model' => $modelLabel, 'id' => $docNumber]))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->success()
            ->actions([
                Action::make('view')
                    ->label('Lihat')
                    ->url(
                        $resource::getUrl('view', [
                            'record' => $record
                        ])
                    )
            ])
            ->sendToDatabase($recipient);
    }
}
