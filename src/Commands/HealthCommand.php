<?php

namespace SabahWeb\SwMailerPro\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\ConfigurationException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;

class HealthCommand extends Command
{
    protected $signature = 'swmailerpro:health';

    protected $description = 'SwMailerPro gateway sağlık durumunu kontrol eder';

    public function handle(): int
    {
        $client = $this->resolveClient();

        if ($client === null) {
            return self::FAILURE;
        }

        $this->info('SwMailerPro gateway\'e bağlanılıyor...');
        $this->newLine();

        try {
            $result = $client->health();
        } catch (ApiException $e) {
            $this->error("API Hatası [{$e->errorCode}]: {$e->getMessage()}");

            return self::FAILURE;
        } catch (SwMailerProException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Bağlantı hatası: {$e->getMessage()}");

            return self::FAILURE;
        }

        $data = $result['data'] ?? $result;

        $status = $data['status'] ?? 'unknown';
        $statusColor = $status === 'healthy' ? 'green' : ($status === 'degraded' ? 'yellow' : 'red');
        $this->line("  Durum: <fg={$statusColor};options=bold>{$status}</>");

        if (isset($data['uptime'])) {
            $this->line("  Uptime: {$data['uptime']}s");
        }

        if (isset($data['version'])) {
            $this->line("  Sürüm: {$data['version']}");
        }

        $schemaProblem = $this->renderSchema($data);
        $this->renderProviders($data);
        $queueProblem = $this->renderQueue($data);
        $this->renderSuppression($data);

        $this->newLine();

        // A half-finished deploy and a queue nothing is draining both mean
        // "mail is not moving", even while the API answers healthy — so they
        // fail the command rather than being printed and scrolled past. So does
        // a gateway that cannot read its own state: not knowing is not health.
        foreach ([$schemaProblem, $queueProblem] as $problem) {
            if ($problem !== null) {
                $this->error($problem);

                return self::FAILURE;
            }
        }

        if ($status === 'healthy') {
            $this->info('Gateway sağlıklı.');

            return self::SUCCESS;
        }

        $this->warn("Gateway durumu: {$status}");

        return self::FAILURE;
    }

    /**
     * İstemciyi container'dan çözer; konfigürasyon eksikse nedenini yazıp null döner.
     *
     * The client used to be a handle() parameter, so the container built it
     * during method injection — before a single line of handle() ran. The
     * ConfigurationException the singleton throws on an empty url or key
     * therefore flew straight past the try/catch below and out of the command
     * as an uncaught error: a developer who had published the config but not
     * yet set SWMAILERPRO_KEY got a stack trace from the one command whose
     * entire job is to diagnose that situation. Resolving it here, inside the
     * command's own control flow, is what makes that catch reachable at all.
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
     * Config değerleri mixed; string olmayan bir ayar burada boş sayılır.
     */
    protected function configString(string $key): string
    {
        $value = Config::get($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Şema satırını yazar ve varsa sorunu döndürür.
     *
     * Eskiden yalnızca 'behind' hata sayılıyordu. Gateway iki durum daha
     * üretiyor: sürüm beklenenden yeniyse 'ahead' (kod eski kalmış), sürümü
     * hiç okuyamadıysa 'unknown' ve schema_version null. isset() null için
     * false olduğundan 'unknown' dalı en baştan geri dönüyor, komut da hiçbir
     * şey bilmediği bir şema için 0 ile çıkıyordu.
     *
     * @param array<string, mixed> $data
     * @return string|null Şemayla ilgili sorun; yoksa null
     */
    protected function renderSchema(array $data): ?string
    {
        if (! array_key_exists('schema_version', $data) || ! array_key_exists('schema_version_expected', $data)) {
            return null;
        }

        $version = $data['schema_version'];
        $expected = $data['schema_version_expected'];
        $schemaStatus = is_string($data['schema_status'] ?? null) ? $data['schema_status'] : 'unknown';
        $color = $schemaStatus === 'current' ? 'green' : 'red';
        $shown = is_scalar($version) ? (string) $version : '?';
        $shownExpected = is_scalar($expected) ? (string) $expected : '?';

        $this->line("  Şema: <fg={$color}>{$shown}/{$shownExpected} ({$schemaStatus})</>");

        if ($version === null || $schemaStatus === 'unknown') {
            return 'Şema sürümü okunamadı: gateway veritabanına erişemiyor.';
        }

        if ($schemaStatus === 'behind' || (is_int($version) && is_int($expected) && $version < $expected)) {
            return 'Şema sürümü geride: migration çalışmamış, deploy yarım kalmış.';
        }

        if ($schemaStatus === 'ahead' || (is_int($version) && is_int($expected) && $version > $expected)) {
            return 'Şema sürümü ileride: veritabanı bu paketin beklediğinden yeni, kod eski kalmış.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderProviders(array $data): void
    {
        if (empty($data['providers']) || ! is_array($data['providers'])) {
            return;
        }

        $this->newLine();

        $rows = [];
        foreach ($data['providers'] as $provider) {
            $rows[] = [
                $provider['provider'] ?? $provider['name'] ?? 'unknown',
                $provider['state'] ?? $provider['status'] ?? 'unknown',
                $provider['circuitBreaker']['state'] ?? '-',
                $provider['circuitBreaker']['failures'] ?? 0,
                isset($provider['errorRate']) ? round(((float) $provider['errorRate']) * 100) . '%' : '-',
            ];
        }

        $this->table(['Provider', 'Durum', 'Circuit Breaker', 'Hata Sayısı', 'Hata Oranı'], $rows);
    }

    /**
     * Kuyruk satırını yazar ve varsa sorunu döndürür.
     *
     * Gateway kuyruğu okuyamadığında queue_stats, dead_letter_count ve
     * queue_oldest_pending_ms alanlarını birlikte null gönderiyor. Bunları 0
     * saymak "bekleyen 0, ölü mektup 0" yazdırıyordu: gateway hiçbir şey
     * bilmediğini söylerken komut her şeyin yolunda olduğunu bildiriyordu.
     * Boş kuyruk bu şekli hiç üretmez — getOldestPendingAgeMs() bekleyen iş
     * yokken null değil 0 döner.
     *
     * @param array<string, mixed> $data
     * @return string|null Kuyrukla ilgili sorun; yoksa null
     */
    protected function renderQueue(array $data): ?string
    {
        if (! array_key_exists('queue_async_sends', $data)) {
            return null;
        }

        $mode = $data['queue_async_sends'] ? 'kuyruklu' : 'doğrudan';

        if ($this->queueUnreadable($data)) {
            $this->line("  Kuyruk: {$mode} — <fg=red>durum okunamadı</>");

            return 'Gateway kuyruk durumunu okuyamadı — kuyruğun ilerleyip ilerlemediği bilinmiyor.';
        }

        $stats = is_array($data['queue_stats'] ?? null) ? $data['queue_stats'] : [];
        $pending = (int) ($stats['pending'] ?? 0);
        $dead = (int) ($data['dead_letter_count'] ?? $stats['dead'] ?? 0);
        $oldestMs = $data['queue_oldest_pending_ms'] ?? null;

        $this->line("  Kuyruk: {$mode} — bekleyen {$pending}, ölü mektup {$dead}");

        if ($dead > 0) {
            $this->warn("  {$dead} mesaj ölü mektup kutusunda — elden geçirilmeli.");
        }

        if ($oldestMs === null) {
            return null;
        }

        $oldestSeconds = (int) round(((int) $oldestMs) / 1000);
        if ($pending > 0) {
            $this->line("  En eski bekleyen: {$oldestSeconds}s");
        }

        // Five minutes is far past a healthy poll cycle; at that point the
        // queue is not slow, it is unattended.
        return $pending > 0 && $oldestSeconds > 300
            ? 'Kuyrukta bekleyen iş var ama ilerlemiyor — worker çalışmıyor olabilir.'
            : null;
    }

    /**
     * Gateway kuyruk durumunu okuyamamış mı.
     *
     * İki alanı birlikte arıyoruz: gateway üçünü tek try/catch içinde
     * dolduruyor, dolayısıyla okuma patladıysa ikisi de null olur.
     *
     * @param array<string, mixed> $data
     */
    protected function queueUnreadable(array $data): bool
    {
        return array_key_exists('queue_stats', $data) && $data['queue_stats'] === null
            && array_key_exists('dead_letter_count', $data) && $data['dead_letter_count'] === null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderSuppression(array $data): void
    {
        if (isset($data['suppression_list_size'])) {
            $this->line("  Engelli adres: {$data['suppression_list_size']}");
        }
    }
}
