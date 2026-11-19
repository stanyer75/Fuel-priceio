<?php

namespace App\Console\Commands;

use App\Exceptions\UpstreamException;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

/** Runs a sync and prints a readable summary of what happened. */
final class SyncReport
{
    public static function run(Command $command, callable $sync): int
    {
        try {
            /** @var SyncRun $run */
            $run = $sync();
        } catch (LockTimeoutException) {
            $command->error('Another Fuel Finder sync is still running (the API allows one request at a time). Try again shortly.');

            return Command::FAILURE;
        } catch (UpstreamException $e) {
            $command->error("[{$e->source}:{$e->errorCode}] {$e->getMessage()}");
            self::printRun($command, SyncRun::latest('id')->first());

            return Command::FAILURE;
        }

        self::printRun($command, $run);

        return Command::SUCCESS;
    }

    private static function printRun(Command $command, ?SyncRun $run): void
    {
        if (! $run) {
            return;
        }

        $command->line("Sync run #{$run->id} ({$run->type}, source: {$run->source}) <info>{$run->status}</info>");
        $command->table(['Requests', 'Batches', 'Records received', 'Rows saved', 'Rows rejected'], [[
            $run->requests_made, $run->batches, $run->records_received, $run->rows_saved, $run->rows_rejected,
        ]]);

        foreach (['rejections' => 'Rejected', 'corrections' => 'Repaired'] as $field => $label) {
            foreach ($run->{$field} ?? [] as $reason => $count) {
                $command->line("  {$label}: {$reason} x{$count}");
            }
        }
    }
}
