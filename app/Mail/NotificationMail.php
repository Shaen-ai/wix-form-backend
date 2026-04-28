<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $rows;
    public ?string $submitterName;
    public ?string $submitterEmail;

    private const SIGNED_LINK_EXPIRES_DAYS = 7;

    public function __construct(
        public Submission $submission,
        public Form $form
    ) {
        $submission->load('submissionFiles');
        $fields = $form->formFields()->orderBy('order_index')->get()->keyBy('id');
        $data = $submission->data_json ?? [];
        $maxAttachmentBytes = config('mail.attachment_max_bytes', 5 * 1024 * 1024);

        $this->rows = [];
        $this->submitterName = null;
        $this->submitterEmail = null;

        foreach ($fields as $fieldId => $field) {
            $value = $this->rawDataValueForField($data, $fieldId);
            if ($value === self::MISSING) {
                continue;
            }

            if ($field->type === 'file_upload') {
                $fileEntries = $this->buildFileEntries($value, (int) $fieldId, $submission, $maxAttachmentBytes);
                if (empty($fileEntries)) {
                    continue;
                }
                $this->rows[] = [
                    'label' => $field->label,
                    'value' => null,
                    'type' => 'file_upload',
                    'fileEntries' => $fileEntries,
                ];
                continue;
            }

            $display = $this->formatDisplayValue($value);

            if ($display === '' || $display === '[]') {
                continue;
            }

            $this->rows[] = [
                'label' => $field->label,
                'value' => $display,
                'type' => $field->type,
            ];

            if ($field->type === 'email' && $this->submitterEmail === null) {
                $this->submitterEmail = $display;
            }

            if ($field->type === 'name' && $this->submitterName === null) {
                $this->submitterName = $display;
            }
        }

        if ($this->submitterName === null) {
            foreach ($this->rows as $row) {
                if (stripos($row['label'], 'name') !== false && isset($row['value'])) {
                    $this->submitterName = $row['value'];
                    break;
                }
            }
        }

        if ($this->rows === [] && $data !== []) {
            Log::warning('Notification mail has no rows but submission data_json is non-empty', [
                'form_id' => $form->id,
                'submission_id' => $submission->id,
                'data_keys' => array_keys($data),
                'field_ids' => $fields->keys()->values()->all(),
            ]);
        }
    }

    private const MISSING = '_missing_8f3a';

    /**
     * @return mixed|self::MISSING
     */
    private function rawDataValueForField(array $data, int|string $fieldId): mixed
    {
        $candidates = [$fieldId, is_numeric($fieldId) ? (int) $fieldId : null, (string) $fieldId];
        foreach ($candidates as $key) {
            if ($key === null) {
                continue;
            }
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return self::MISSING;
    }

    private function formatDisplayValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['value'] ?? json_encode($value));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    /**
     * @return array<int, array{name: string, attached: bool, downloadUrl: string|null}>
     */
    private function buildFileEntries($value, int $fieldId, Submission $submission, int $maxAttachmentBytes): array
    {
        $entries = [];
        $files = $submission->submissionFiles
            ->where('form_field_id', (int) $fieldId)
            ->values();

        foreach ($files as $file) {
            if ($file->virus_status === 'infected') {
                continue;
            }

            $fullPath = Storage::disk($file->storage_disk)->path($file->path);
            $size = $file->size_bytes ?? (file_exists($fullPath) ? filesize($fullPath) : 0);
            $attach = $size > 0 && $size <= $maxAttachmentBytes && file_exists($fullPath);

            $entries[] = [
                'name' => $file->original_name,
                'attached' => $attach,
                'downloadUrl' => $attach ? null : $this->signedDownloadUrl($file->id),
            ];

            if ($attach) {
                $this->attach($fullPath, [
                    'as' => $file->original_name,
                    'mime' => $file->mime ?? 'application/octet-stream',
                ]);
            }
        }

        return $entries;
    }

    private function signedDownloadUrl(int $fileId): string
    {
        return URL::temporarySignedRoute(
            'files.download-public',
            now()->addDays(self::SIGNED_LINK_EXPIRES_DAYS),
            ['id' => $fileId]
        );
    }

    public function envelope(): Envelope
    {
        $subject = trim((string) $this->form->name) !== ''
            ? $this->form->name
            : 'New form submission';

        $envelope = new Envelope(
            subject: $subject,
        );

        if ($this->submitterEmail) {
            $envelope->replyTo($this->submitterEmail, $this->submitterName ?? '');
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification'
        );
    }
}
