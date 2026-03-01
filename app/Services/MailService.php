<?php

namespace App\Services;

use App\Mail\AutoReplyMail;
use App\Mail\NotificationMail;
use App\Models\Form;
use App\Models\FormSettings;
use App\Models\Submission;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    public function sendNotification(Submission $submission, Form $form, ?FormSettings $settings = null): void
    {
        $settings ??= FormSettings::find($form->id);
        $formSettings = $form->settings_json ?? [];

        $email = ! empty($formSettings['adminNotificationEmail'])
            ? $formSettings['adminNotificationEmail']
            : $settings?->notification_email;

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info('Admin notification skipped: no valid admin email configured', [
                'form_id' => $form->id,
                'submission_id' => $submission->id,
            ]);
            return;
        }

        Mail::to($email)->send(new NotificationMail($submission, $form));
    }

    public function sendAutoReply(Submission $submission, Form $form, ?FormSettings $settings = null): void
    {
        $settings ??= FormSettings::find($form->id);
        $formSettings = $form->settings_json ?? [];

        $enabled = isset($formSettings['userConfirmationEmail'])
            ? (bool) $formSettings['userConfirmationEmail']
            : (bool) $settings?->auto_reply_enabled;

        if (! $enabled) {
            return;
        }

        $email = $this->extractSubmitterEmail($submission, $form);
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info('Auto-reply skipped: no valid submitter email in form data', [
                'form_id' => $form->id,
                'submission_id' => $submission->id,
            ]);
            return;
        }

        // Ensure we have a FormSettings instance (AutoReplyMail requires it for subject/body)
        $settings = $settings ?? FormSettings::firstOrCreate(
            ['form_id' => $form->id],
            ['auto_reply_enabled' => false]
        );

        Mail::to($email)->send(new AutoReplyMail($submission, $form, $settings));
    }

    private function extractSubmitterEmail(Submission $submission, Form $form): ?string
    {
        $data = $submission->data_json ?? [];
        $fields = $form->formFields()->get()->keyBy('id');

        foreach ($data as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if (! $field || $field->type !== 'email') {
                continue;
            }
            $email = is_array($value)
                ? ($value['value'] ?? ($value['email'] ?? null))
                : (is_string($value) ? $value : null);
            if ($email && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }
}
