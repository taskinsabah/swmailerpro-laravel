<?php

namespace SabahWeb\SwMailerPro\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConfigurationException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;
use SabahWeb\SwMailerPro\Payload\PayloadFactory;

class TestCommand extends Command
{
    protected $signature = 'swmailerpro:test
        {--to= : Alıcı e-posta adresi (zorunlu)}
        {--from= : Gönderici e-posta adresi (boşsa config default kullanılır)}
        {--subject= : E-posta konusu}';

    // The command posts to /send-test, which the gateway answers with
    // "Dry-run successful — email not sent" (EmailController::sendTest calls
    // providerManager.sendDryRun). Nothing leaves the building. The old
    // description promised a delivered mail, so `php artisan list` told every
    // reader the opposite of what the command does — and someone waiting for
    // that mail to arrive would wait forever.
    protected $description = 'SwMailerPro payloadını doğrular (dry-run — e-posta GÖNDERİLMEZ)';

    /**
     * RFC 2606 §2-3 / RFC 6761 §6.2-6.4 rezerve alan adları.
     *
     * Mirrors RESERVED_EMAIL_DOMAINS in the gateway (src/config/constants.ts).
     * No mail server accepts these, and the gateway resolves the tenant from
     * the sender's domain — so a sender here can only ever come back as
     * TENANT_NOT_FOUND. Matching is label-bounded like the gateway's:
     * example.com matches mail.example.com but not notexample.com.
     *
     * @var list<string>
     */
    protected const RESERVED_SENDER_DOMAINS = [
        'example.com',
        'example.net',
        'example.org',
        'test',
        'example',
        'invalid',
        'localhost',
    ];

    /**
     * Konsol seçenekleri string değil mixed döner (bayrak, dizi, null olabilir).
     * String olmayanı boş kabul ediyoruz — komut zaten boşluğu kontrol ediyor.
     */
    protected function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Config değerleri de mixed; string olmayan bir ayar burada boş sayılır.
     */
    protected function configString(string $key): string
    {
        $value = Config::get($key);

        return is_string($value) ? $value : '';
    }

    public function handle(PayloadFactory $payloadFactory): int
    {
        $to = $this->stringOption('to');

        if (empty($to)) {
            $this->error('--to parametresi zorunludur.');
            return self::FAILURE;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Geçersiz e-posta adresi: {$to}");
            return self::FAILURE;
        }

        $from = $this->resolveSender();

        if ($from === null) {
            return self::FAILURE;
        }

        $fromName = $this->configString('mail.from.name') ?: 'SwMailerPro';
        $subject = $this->stringOption('subject') ?: 'SwMailerPro Test E-postası';

        $client = $this->resolveClient();

        if ($client === null) {
            return self::FAILURE;
        }

        $payload = $payloadFactory->fromArray([
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
                    'value' => "Bu bir SwMailerPro test e-postasıdır.\nGönderim zamanı: " . Date::now()->toDateTimeString(),
                ],
                [
                    'type' => 'text/html',
                    'value' => '<div style="font-family:sans-serif;padding:20px;background:#f8f9fa;border-radius:8px">'
                        . '<h2 style="color:#2563eb">SwMailerPro Test</h2>'
                        . '<p>Bu bir <strong>test e-postası</strong>dır.</p>'
                        . '<p style="color:#6b7280;font-size:13px">Gönderim: ' . Date::now()->toDateTimeString() . '</p>'
                        . '</div>',
                ],
            ],
        ]);

        $this->info("Payload doğrulanıyor (dry-run — mail gönderilmez)...");
        $this->line("  Gönderici: {$from}");
        $this->line("  Alıcı:    {$to}");
        $this->line("  Konu:     {$subject}");
        $this->newLine();

        try {
            $result = $client->sendTest($payload);

            $data = $result['data'] ?? $result;

            // /send-test is a dry run: it validates and renders, and returns
            // message/provider/rendered_messages. There is no status and no
            // message id, because nothing was sent — printing those rows meant
            // two permanently empty lines and a success message that lied.
            $rendered = is_array($data['rendered_messages'] ?? null) ? count($data['rendered_messages']) : null;

            $this->table(
                ['Alan', 'Değer'],
                [
                    ['Sonuç', $data['message'] ?? 'Doğrulama başarılı'],
                    ['Provider', $data['provider'] ?? '-'],
                    ['Render edilen mesaj', $rendered ?? '-'],
                    ['Request ID', $result['request_id'] ?? '-'],
                ]
            );

            $this->newLine();
            $this->info('Payload doğrulandı. Bu bir dry-run: mail GÖNDERİLMEDİ.');

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error("API Hatası [{$e->errorCode}]: {$e->getMessage()}");

            if (!empty($e->errorBody)) {
                $error = is_array($e->errorBody['error'] ?? null) ? $e->errorBody['error'] : [];
                $details = $error['details'] ?? null;

                if (is_array($details)) {
                    $this->renderErrorDetails($details);
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

    /**
     * Kullanılabilir bir gönderici adresi bulur; yoksa nedenini yazıp null döner.
     *
     * Nothing here used to be checked beyond "is it empty". It never was, so
     * the fall-through reached Laravel's own shipped default,
     * hello@example.com — a domain reserved by RFC 2606 that the gateway can
     * never resolve a tenant for. The developer got TENANT_NOT_FOUND from a
     * server they had just configured correctly, and nothing in that answer
     * pointed at MAIL_FROM_ADDRESS. Refusing here names the env var instead.
     */
    protected function resolveSender(): ?string
    {
        $explicit = $this->stringOption('from');

        $from = $explicit
            ?: $this->configString('swmailerpro.defaults.from_email')
            ?: $this->configString('mail.from.address');

        if ($from === '') {
            $this->error('Gönderici adresi belirlenemedi.');
            $this->line('  --from ile verin veya .env dosyanızda şunlardan birini tanımlayın:');
            $this->line('    SWMAILERPRO_FROM_EMAIL=test@alan-adiniz.com   (yalnız bu paket için)');
            $this->line('    MAIL_FROM_ADDRESS=test@alan-adiniz.com        (uygulamanın geneli için)');

            return null;
        }

        $reserved = $this->reservedSenderDomain($from);

        if ($reserved !== null) {
            // --from ile gelen adres bir tercih, kaza değil. Gateway'in
            // gönderici tarafında rezerve alan adı kontrolü yok: tenant'ı
            // from.email'in alan adından çözüyor, ve operatör oraya pekâlâ
            // myapp.test gibi bir alan adı kaydetmiş olabilir (Herd'ün yerel
            // TLD'si tam da bu). Böyle bir adresi burada reddetmek, gateway'in
            // kabul edeceği bir gönderimi kaçış yolu bırakmadan kesmek olurdu.
            // Kapatılması gereken şey taze kurulumun sessizce
            // hello@example.com'a düşmesiydi — bilinçli bir bayrak değil.
            if ($explicit !== '') {
                $this->warn("Gönderici rezerve bir alan adında: {$from}");
                $this->line("  \"{$reserved}\" RFC 2606/6761 ile rezerve edilmiş bir alan adı. Gateway bu");
                $this->line('  alan adı için tenant bulamazsa 403 TENANT_NOT_FOUND döner. --from ile');
                $this->line('  verdiğiniz için devam ediliyor.');

                return $from;
            }

            $this->error("Gönderici adresi kullanılamaz: {$from}");
            $this->line("  \"{$reserved}\" RFC 2606/6761 ile rezerve edilmiş bir alan adı; hiçbir posta");
            $this->line('  sunucusu kabul etmez ve gateway bu alan adı için tenant bulamaz.');
            $this->line('  Doğrulanmış bir alan adı verin:');
            $this->line('    SWMAILERPRO_FROM_EMAIL=test@alan-adiniz.com   (yalnız bu paket için)');
            $this->line('    MAIL_FROM_ADDRESS=test@alan-adiniz.com        (uygulamanın geneli için)');
            $this->line('    --from=test@alan-adiniz.com                   (yalnız bu çalıştırma için)');

            return null;
        }

        return $from;
    }

    /**
     * Adres rezerve bir alan adındaysa eşleşen son eki döndürür.
     */
    protected function reservedSenderDomain(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = rtrim(strtolower(trim(substr($email, $at + 1))), '.');

        if ($domain === '') {
            return null;
        }

        foreach (self::RESERVED_SENDER_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
                return $reserved;
            }
        }

        return null;
    }

    /**
     * İstemciyi container'dan çözer; konfigürasyon eksikse nedenini yazıp null döner.
     *
     * The client used to be a handle() parameter, so the container built it
     * during method injection — before a single line of handle() ran. The
     * ConfigurationException the singleton throws on an empty url or key
     * therefore flew straight past handle()'s own try/catch and out of the
     * command as an uncaught error: a developer who had published the config
     * but not yet set SWMAILERPRO_KEY got a stack trace from a command whose
     * entire job is to diagnose that situation. Resolving it here, inside the
     * command's own control flow, is what makes the catch below reachable.
     */
    protected function resolveClient(): ?SwMailerProClient
    {
        try {
            return $this->laravel->make(SwMailerProClient::class);
        } catch (ConfigurationException $e) {
            $this->reportMissingConfiguration($e);

            return null;
        }
    }

    /**
     * Eksik konfigürasyonu, hangi env değerinin eksik olduğunu söyleyerek yazar.
     *
     * The exception's own message lists both variables whichever one is
     * missing. Reading the config back tells the developer which line to add.
     */
    protected function reportMissingConfiguration(ConfigurationException $e): void
    {
        $missing = [];

        if ($this->configString('swmailerpro.url') === '') {
            $missing['SWMAILERPRO_URL'] = 'https://gateway.alan-adiniz.com';
        }

        if ($this->configString('swmailerpro.key') === '') {
            $missing['SWMAILERPRO_KEY'] = 'tenant-api-anahtariniz';
        }

        if ($missing === []) {
            $this->error($e->getMessage());

            return;
        }

        $this->error('SwMailerPro yapılandırması eksik: ' . implode(' ve ', array_keys($missing)) . ' tanımlı değil.');
        $this->line('  .env dosyanıza ekleyin:');

        foreach ($missing as $name => $example) {
            $this->line("    {$name}={$example}");
        }
    }

    /**
     * Gateway'in doğrulama ayrıntılarını yazar.
     *
     * The gateway builds details as a LIST of {field, message, code} objects
     * and sends that same shape for 400 VALIDATION_ERROR and for 413
     * ATTACHMENT_TOO_LARGE alike (src/middleware/validate.ts). Iterating it as
     * field => messages therefore labelled every row with its list index and
     * printed "Array" for the object — and where a value really was a list,
     * implode() on a nested array raised "Array to string conversion". The one
     * part of the error a developer can act on was the part that never
     * arrived. Rows that are not that shape are still printed rather than
     * dropped: a proxy or an older gateway may answer with something else, and
     * an unlabelled detail beats silence.
     *
     * @param array<array-key, mixed> $details
     */
    protected function renderErrorDetails(array $details): void
    {
        foreach ($details as $key => $detail) {
            if (is_array($detail) && (array_key_exists('message', $detail) || array_key_exists('field', $detail))) {
                $field = $this->detailText($detail['field'] ?? null);
                $label = $field !== '' && $field !== '-' ? $field : (is_string($key) ? $key : 'hata');
                $message = $this->detailText($detail['message'] ?? null);
                $code = $this->detailText($detail['code'] ?? null);
                $suffix = $code !== '' && $code !== '-' ? " ({$code})" : '';

                $this->line("  <fg=yellow>{$label}</>: {$message}{$suffix}");
                continue;
            }

            $label = is_string($key) ? $key : 'hata';
            $this->line("  <fg=yellow>{$label}</>: " . $this->detailText($detail));
        }
    }

    /**
     * Ayrıntı değerini metne çevirir.
     *
     * Anything can arrive here — the gateway's own shape is scalar, but a
     * proxy's is not. Flattening nested arrays instead of interpolating them
     * is what keeps "Array to string conversion" out of a diagnostic command's
     * output.
     */
    protected function detailText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->detailText($item), $value));
        }

        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
