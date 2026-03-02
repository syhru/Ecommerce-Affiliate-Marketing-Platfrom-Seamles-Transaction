<?php

namespace App\Services;

use App\Jobs\SendTelegramNotification;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Support\Carbon;

class NotificationService
{
    public function notifyOrderStatus(Order $order, string $event, ?string $note = null): void
    {
        $user = $order->customer;

        if (! $user) {
            return;
        }

        $message = $this->buildMessage($order, $event, $note);

        if (! $message) {
            return;
        }

        $notification = NotificationLog::create([
            'user_id'         => $user->id,
            'order_id'        => $order->id,
            'message_type'    => $event,
            'channel'         => 'telegram',
            'recipient'       => $user->telegram_chat_id ?? '',
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }


    private function buildMessage(Order $order, string $event, ?string $note = null): ?string
    {
        $customer   = $order->customer;
        $name       = $customer?->name ?? 'Pelanggan';
        $ordNum     = $order->order_number;
        $total       = 'Rp ' . number_format((float) $order->total_amount, 0, ',', '.');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $trackLink   = "{$frontendUrl}/orders/{$ordNum}";
        $date        = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');

        return match ($event) {
            'payment.confirmed' =>
                "*TDR-HPZ Store*, [{$date}]\n" .
                "✅ *Pembayaran Dikonfirmasi*\n\n" .
                "Halo {$name},\n\n" .
                "Pembayaran untuk pesanan *{$ordNum}* telah kami terima.\n" .
                "Total: *{$total}*\n\n" .
                "Pesanan Anda sedang kami proses. Kami akan mengirimkan notifikasi saat barang dikirim.\n\n" .
                "🔗 [Lacak Pesanan]({$trackLink})\n\n" .
                "Terima kasih telah berbelanja di store.tdr-hpz.com! 🚀",

            'order.processing' =>
                "*TDR-HPZ Store*, [{$date}]\n" .
                "⚙️ *Pesanan Diproses*\n\n" .
                "Halo {$name},\n\n" .
                "Pesanan *{$ordNum}* sedang dalam proses pengemasan.\n\n" .
                "🔗 [Lacak Pesanan]({$trackLink})\n\n" .
                "Kami akan segera mengirimkan pesanan Anda. Nantikan informasi pengiriman selanjutnya!",

            'order.shipped' => (function () use ($order, $ordNum, $name, $date, $trackLink, $note) {
                $courierLabels = [
                    'jne_reg'   => 'JNE Reguler',
                    'jne_yes'   => 'JNE YES',
                    'jnt_reg'   => 'J&T Reguler',
                    'sicepat'   => 'SiCepat',
                    'pos_biasa' => 'Pos Indonesia',
                ];
                $courier = $courierLabels[$order->shipping_courier] ?? strtoupper($order->shipping_courier ?? '-');
                $resi    = $order->shipping_tracking_number;
                $msg = "*TDR-HPZ Store*, [{$date}]\n"
                     . "📦 *Pesanan Dikirim*\n\n"
                     . "Halo {$name},\n\n"
                     . "Pesanan *{$ordNum}* telah dikirim via *{$courier}*.\n";
                if ($resi) {
                    $msg .= "Nomor Resi: `{$resi}`\n";
                }
                if ($note) {
                    $msg .= "\n📝 Catatan: {$note}\n";
                }
                $msg .= "\n🔗 [Lacak Pesanan]({$trackLink})\n\nSilakan pantau pengiriman Anda. Terima kasih! 🙏";
                return $msg;
            })(),

            'order.delivered' =>
                "*TDR-HPZ Store*, [{$date}]\n" .
                "🎉 *Pesanan Selesai*\n\n" .
                "Halo {$name},\n\n" .
                "Pesanan *{$ordNum}* telah selesai.\n\n" .
                "🔗 [Riwayat Pesanan]({$trackLink})\n\n" .
                "Terima kasih telah berbelanja di store.tdr-hpz.com! 🚀",

            'order.cancelled' =>
                "*TDR-HPZ Store*, [{$date}]\n" .
                "❌ *Pesanan Dibatalkan*\n\n" .
                "Halo {$name},\n\n" .
                "Pesanan *{$ordNum}* telah dibatalkan.\n\n" .
                "Jika ada pertanyaan, silakan hubungi kami.",

            default => null,
        };
    }


    public function notifyAffiliateBalanceCredited(Order $order): void
    {
        if (! $order->affiliate_id) {
            return;
        }

        $affiliate = $order->affiliate;
        $chatId    = $affiliate?->telegram_chat_id;
        if (! $chatId) {
            return;
        }

        $commission = \App\Models\AffiliateCommission::where('order_id', $order->id)
            ->where('affiliate_id', $order->affiliate_id)
            ->first();
        if (! $commission) {
            return;
        }

        $name    = $affiliate->name;
        $ordNum  = $order->order_number;
        $amount  = 'Rp ' . number_format((float) $commission->amount, 0, ',', '.');
        $date    = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');

        $message = "*TDR-HPZ Affiliate* 💰\n\n"
                 . "Halo *{$name}*!\n\n"
                 . "Pesanan *{$ordNum}* telah selesai.\n"
                 . "Komisi sebesar *{$amount}* sudah masuk ke saldo Anda.\n\n"
                 . "⏰ {$date}";

        $notification = NotificationLog::create([
            'user_id'         => $affiliate->id,
            'order_id'        => $order->id,
            'message_type'    => 'affiliate.balance_credited',
            'channel'         => 'telegram',
            'recipient'       => $chatId,
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }


    public function notifyAffiliateApproved(\App\Models\AffiliateProfile $profile): void
    {
        $user   = $profile->user;
        $chatId = $user?->telegram_chat_id;

        if (! $chatId) {
            return;
        }

        $date    = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');
        $name    = $user->name;
        $code    = $profile->referral_code;
        $rate    = $profile->commission_rate;

        $message = "*TDR-HPZ Affiliate* ✅\n\n"
                 . "Selamat *{$name}*!\n\n"
                 . "Akun affiliate Anda telah *disetujui*.\n\n"
                 . "🔑 Kode Referral: `{$code}`\n"
                 . "💰 Komisi: *{$rate}%* per transaksi\n"
                 . "⏰ Disetujui: {$date}\n\n"
                 . "Mulai bagikan link referral Anda dan raih komisi! 🚀";

        $notification = NotificationLog::create([
            'user_id'         => $user->id,
            'order_id'        => null,
            'message_type'    => 'affiliate.approved',
            'channel'         => 'telegram',
            'recipient'       => $chatId,
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }

    public function notifyAffiliateCommission(\App\Models\AffiliateCommission $commission): void
    {
        $affiliate = $commission->affiliate;
        $chatId    = $affiliate?->telegram_chat_id;

        if (! $chatId) {
            return;
        }

        $order      = $commission->order;
        $affName    = $affiliate->name;
        $ordNum     = $order?->order_number ?? '—';
        $amount     = 'Rp ' . number_format((float) $commission->amount, 0, ',', '.');
        $rate       = $commission->commission_rate;
        $date       = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');

        $message = "*TDR-HPZ Affiliate* 🎉\n\n"
                 . "*{$affName}*, kamu baru saja mendapatkan komisi!\n\n"
                 . "📦 Order: *{$ordNum}*\n"
                 . "💰 Komisi ({$rate}%): *{$amount}*\n"
                 . "⏰ Waktu: {$date}\n\n"
                 . "Komisi akan masuk ke saldo setelah pesanan selesai (completed). Keep sharing! 🚀";

        $notification = NotificationLog::create([
            'user_id'         => $affiliate->id,
            'order_id'        => $commission->order_id,
            'message_type'    => 'affiliate.commission',
            'channel'         => 'telegram',
            'recipient'       => $chatId,
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }


    public function notifyAffiliateWithdrawal(\App\Models\AffiliateProfile $profile, \App\Models\AffiliateWithdrawal $withdrawal): void
    {
        $user   = $profile->user;
        $chatId = $user?->telegram_chat_id;

        if (! $chatId) {
            return;
        }

        $date    = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');
        $name    = $user->name;
        $amount  = 'Rp ' . number_format((float) $withdrawal->amount, 0, ',', '.');
        $bank    = $withdrawal->bank_name;
        $account = $withdrawal->bank_account_number;
        $holder  = $withdrawal->bank_account_holder;

        $message = "*TDR-HPZ Affiliate* 💸\n\n"
                 . "Halo *{$name}*,\n\n"
                 . "Permintaan pencairan komisi Anda telah *diterima*.\n\n"
                 . "💰 Jumlah: *{$amount}*\n"
                 . "🏦 Bank: *{$bank}*\n"
                 . "📋 No. Rekening: `{$account}`\n"
                 . "👤 Atas Nama: {$holder}\n"
                 . "⏰ Diajukan: {$date}\n\n"
                 . "Admin akan memproses pencairan dalam 1×24 jam. Kami akan menghubungi Anda jika ada kendala. 🙏";

        $notification = NotificationLog::create([
            'user_id'         => $user->id,
            'order_id'        => null,
            'message_type'    => 'affiliate.withdrawal',
            'channel'         => 'telegram',
            'recipient'       => $chatId,
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }


    public function notifyAffiliateWithdrawalProcessed(
        \App\Models\AffiliateProfile    $profile,
        \App\Models\AffiliateWithdrawal $withdrawal,
        bool                             $approved,
        string                           $reason = ''
    ): void {
        $user   = $profile->user;
        $chatId = $user?->telegram_chat_id;

        if (! $chatId) {
            return;
        }

        $date    = \Illuminate\Support\Carbon::now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i');
        $name    = $user->name;
        $amount  = 'Rp ' . number_format((float) $withdrawal->amount, 0, ',', '.');
        $bank    = $withdrawal->bank_name;
        $account = $withdrawal->bank_account_number;

        if ($approved) {
            $message = "*TDR-HPZ Affiliate* ✅\n\n"
                     . "Halo *{$name}*,\n\n"
                     . "Pencairan komisi Anda telah *disetujui* dan sedang diproses.\n\n"
                     . "💰 Jumlah: *{$amount}*\n"
                     . "🏦 Bank: *{$bank}*\n"
                     . "📋 No. Rekening: `{$account}`\n"
                     . "⏰ Diproses: {$date}\n\n"
                     . "Dana akan masuk ke rekening Anda dalam 1×24 jam kerja. Terima kasih! 🙏";
            $messageType = 'affiliate.withdrawal_approved';
        } else {
            $reasonText = $reason ? "\n📝 Alasan: {$reason}" : '';
            $message = "*TDR-HPZ Affiliate* ❌\n\n"
                     . "Halo *{$name}*,\n\n"
                     . "Maaf, pencairan komisi Anda *ditolak*.\n\n"
                     . "💰 Jumlah: *{$amount}*\n"
                     . "🏦 Bank: *{$bank}*\n"
                     . "📋 No. Rekening: `{$account}`\n"
                     . "⏰ Diproses: {$date}{$reasonText}\n\n"
                     . "Saldo Anda telah dikembalikan. Silakan hubungi admin jika ada pertanyaan. 🙏";
            $messageType = 'affiliate.withdrawal_rejected';
        }

        $notification = NotificationLog::create([
            'user_id'         => $user->id,
            'order_id'        => null,
            'message_type'    => $messageType,
            'channel'         => 'telegram',
            'recipient'       => $chatId,
            'message_content' => $message,
            'status'          => 'queued',
        ]);

        SendTelegramNotification::dispatch($notification->id);
    }
}
