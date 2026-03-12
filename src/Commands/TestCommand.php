<?php

namespace SabahWeb\SwMailerPro\Commands;

use Illuminate\Console\Command;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;

class TestCommand extends Command
{
    protected $signature = 'swmailerpro:test
        {--to= : Alıcı e-posta adresi (zorunlu)}
        {--from= : Gönderici e-posta adresi (boşsa config default kullanılır)}
        {--subject= : E-posta konusu}';

    protected $description = 'SwMailerPro üzerinden test e-postası gönderir';

    public function handle(SwMailerProClient $client): int
    {
        $to = $this->option('to');

        if (empty($to)) {
            $this->error('--to parametresi zorunludur.');
            return self::FAILURE;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Geçersiz e-posta adresi: {$to}");
            return self::FAILURE;
        }

        $from = $this->option('from') ?: config('swmailerpro.defaults.from_email') ?: config('mail.from.address');
        $fromName = config('mail.from.name', 'SwMailerPro');
        $subject = $this->option('subject') ?: 'SwMailerPro Test E-postası';

        if (empty($from)) {
            $this->error('Gönderici adresi belirtilmedi. --from parametresi kullanın veya config ayarlayın.');
            return self::FAILURE;
        }

        $payload = PayloadFactory::fromArray([
            'from' => [
                'email' => $from,
                'name' => $fromName,
            ],
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                ],
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/plain',
                    'value' => "Bu bir SwMailerPro test e-postasıdır.\nGönderim zamanı: " . now()->toDateTimeString(),
                ],
                [
                    'type' => 'text/html',
                    'value' => '<div style="font-family:sans-serif;padding:20px;background:#f8f9fa;border-radius:8px">'
                        . '<h2 style="color:#2563eb">SwMailerPro Test</h2>'
                        . '<p>Bu bir <strong>test e-postası</strong>dır.</p>'
                        . '<p style="color:#6b7280;font-size:13px">Gönderim: ' . now()->toDateTimeString() . '</p>'
                        . '</div>',
                ],
            ],
        ]);

        $this->info("Test e-postası gönderiliyor...");
        $this->line("  Gönderici: {$from}");
        $this->line("  Alıcı:    {$to}");
        $this->line("  Konu:     {$subject}");
        $this->newLine();

        try {
            $result = $client->sendTest($payload);

            $data = $result['data'] ?? $result;

            $this->table(
                ['Alan', 'Değer'],
                [
                    ['Durum', $data['status'] ?? 'ok'],
                    ['Mesaj', $data['message'] ?? '-'],
                    ['Provider', $data['provider'] ?? '-'],
                    ['Message ID', $data['provider_message_id'] ?? '-'],
                    ['Request ID', $result['request_id'] ?? '-'],
                ]
            );

            $this->newLine();
            $this->info('Test e-postası başarıyla gönderildi.');

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error("API Hatası [{$e->errorCode}]: {$e->getMessage()}");

            if (!empty($e->errorBody)) {
                $details = $e->errorBody['error']['details'] ?? null;
                if (is_array($details)) {
                    foreach ($details as $field => $messages) {
                        $msg = is_array($messages) ? implode(', ', $messages) : $messages;
                        $this->line("  <fg=yellow>{$field}</>: {$msg}");
                    }
                }
            }

            return self::FAILURE;
        } catch (SwMailerProException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Bağlantı hatası: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
