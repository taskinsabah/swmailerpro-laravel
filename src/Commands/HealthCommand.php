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

            $data = $result['data'] ?? $result;

            // Genel durum
            $status = $data['status'] ?? 'unknown';
            $statusColor = $status === 'healthy' ? 'green' : ($status === 'degraded' ? 'yellow' : 'red');
            $this->line("  Durum: <fg={$statusColor};options=bold>{$status}</>");

            // Uptime
            if (isset($data['uptime'])) {
                $this->line("  Uptime: {$data['uptime']}s");
            }

            // Provider durumları
            if (!empty($data['providers']) && is_array($data['providers'])) {
                $this->newLine();
                $rows = [];
                foreach ($data['providers'] as $provider) {
                    $pName = $provider['provider'] ?? $provider['name'] ?? 'unknown';
                    $pState = $provider['state'] ?? $provider['status'] ?? 'unknown';
                    $cbState = $provider['circuitBreaker']['state'] ?? '-';
                    $failures = $provider['circuitBreaker']['failures'] ?? 0;
                    $rows[] = [$pName, $pState, $cbState, $failures];
                }
                $this->table(
                    ['Provider', 'Durum', 'Circuit Breaker', 'Hata Sayısı'],
                    $rows
                );
            }

            // Database
            if (isset($data['database'])) {
                $dbStatus = $data['database']['status'] ?? $data['database'];
                $this->line("  Database: {$dbStatus}");
            }

            $this->newLine();

            if ($status === 'healthy') {
                $this->info('Gateway sağlıklı.');
                return self::SUCCESS;
            }

            $this->warn("Gateway durumu: {$status}");
            return self::FAILURE;
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
    }
}
