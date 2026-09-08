<?php

namespace SabahWeb\SwMailerPro\Commands;

use Illuminate\Console\Command;
use SabahWeb\SwMailerPro\Client\SwMailerProClient;
use SabahWeb\SwMailerPro\Exceptions\ApiException;
use SabahWeb\SwMailerPro\Exceptions\SwMailerProException;

class HealthCommand extends Command
{
    protected $signature = 'swmailerpro:health';

    protected $description = 'SwMailerPro gateway sağlık durumunu kontrol eder';

    public function handle(SwMailerProClient $client): int
    {
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

        $schemaBehind = $this->renderSchema($data);
        $this->renderProviders($data);
        $queueStuck = $this->renderQueue($data);
        $this->renderSuppression($data);

        $this->newLine();

        // A half-finished deploy and a queue nothing is draining both mean
        // "mail is not moving", even while the API answers healthy — so they
        // fail the command rather than being printed and scrolled past.
        if ($schemaBehind) {
            $this->error('Şema sürümü geride: migration çalışmamış, deploy yarım kalmış.');

            return self::FAILURE;
        }

        if ($queueStuck) {
            $this->error('Kuyrukta bekleyen iş var ama ilerlemiyor — worker çalışmıyor olabilir.');

            return self::FAILURE;
        }

        if ($status === 'healthy') {
            $this->info('Gateway sağlıklı.');

            return self::SUCCESS;
        }

        $this->warn("Gateway durumu: {$status}");

        return self::FAILURE;
    }

    /**
     * @param array<string, mixed> $data
     * @return bool Şema geride mi
     */
    protected function renderSchema(array $data): bool
    {
        if (! isset($data['schema_version'], $data['schema_version_expected'])) {
            return false;
        }

        $schemaStatus = $data['schema_status'] ?? 'unknown';
        $color = $schemaStatus === 'current' ? 'green' : 'red';

        $this->line(
            "  Şema: <fg={$color}>{$data['schema_version']}/{$data['schema_version_expected']} ({$schemaStatus})</>"
        );

        return $schemaStatus === 'behind';
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
     * @param array<string, mixed> $data
     * @return bool Kuyruk tıkanmış görünüyor mu
     */
    protected function renderQueue(array $data): bool
    {
        if (! array_key_exists('queue_async_sends', $data)) {
            return false;
        }

        $stats = is_array($data['queue_stats'] ?? null) ? $data['queue_stats'] : [];
        $pending = (int) ($stats['pending'] ?? 0);
        $dead = (int) ($data['dead_letter_count'] ?? $stats['dead'] ?? 0);
        $oldestMs = $data['queue_oldest_pending_ms'] ?? null;

        $mode = $data['queue_async_sends'] ? 'kuyruklu' : 'doğrudan';
        $this->line("  Kuyruk: {$mode} — bekleyen {$pending}, ölü mektup {$dead}");

        if ($dead > 0) {
            $this->warn("  {$dead} mesaj ölü mektup kutusunda — elden geçirilmeli.");
        }

        if ($oldestMs === null) {
            return false;
        }

        $oldestSeconds = (int) round(((int) $oldestMs) / 1000);
        if ($pending > 0) {
            $this->line("  En eski bekleyen: {$oldestSeconds}s");
        }

        // Five minutes is far past a healthy poll cycle; at that point the
        // queue is not slow, it is unattended.
        return $pending > 0 && $oldestSeconds > 300;
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
