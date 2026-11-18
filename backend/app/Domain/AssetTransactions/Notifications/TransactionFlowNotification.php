<?php

namespace App\Domain\AssetTransactions\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email of a flow's "kirim notifikasi" action (F-07 §4.1). The message is the organization's
 * own text, sent as plain lines (MailMessage escapes them); the link opens the running transaction.
 */
final class TransactionFlowNotification extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $transactionNumber,
        public readonly string $typeName,
        public readonly string $message,
        public readonly string $runId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("[{$this->transactionNumber}] {$this->typeName}")
            ->greeting('Halo,')
            ->line("Transaksi {$this->typeName} {$this->transactionNumber} di {$this->organizationName}:");
        foreach (preg_split('/\R/', $this->message) ?: [] as $line) {
            if (trim($line) !== '') {
                $mail->line($line);
            }
        }

        return $mail->action('Lihat transaksi', self::url($this->runId));
    }

    public static function url(string $runId): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/transaksi/berjalan/'.$runId;
    }
}
