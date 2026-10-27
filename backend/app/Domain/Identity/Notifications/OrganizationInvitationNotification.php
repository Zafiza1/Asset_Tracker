<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The invitation email. Sent synchronously on purpose: a queued notification would store
 * the token in the jobs table. The token travels in the URL fragment (#token=...), which
 * browsers never send to a server or in a Referer header.
 */
final class OrganizationInvitationNotification extends Notification
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $token,
        public readonly Carbon $expiresAt,
        public readonly ?string $licenseCode = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Undangan bergabung dengan {$this->organizationName}")
            ->greeting('Halo,')
            ->line("{$this->inviterName} mengundang Anda bergabung dengan {$this->organizationName} di Asset Tracker.");

        if ($this->licenseCode) {
            $mail->line("Kode Lisensi: **{$this->licenseCode}**")
                ->line('Kode lisensi ini sudah terhubung dengan undangan Anda. Tidak perlu memasukkannya lagi saat mendaftar.');
        }

        $mail->action('Lihat undangan', self::url($this->token))
            ->line('Undangan berlaku sampai '.$this->expiresAt->format('d-m-Y H:i T').' dan hanya dapat diterima satu kali, oleh akun dengan alamat email ini.')
            ->line('Abaikan email ini bila Anda tidak mengenal organisasi tersebut.');

        return $mail;
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/undangan#token='.$token;
    }
}
