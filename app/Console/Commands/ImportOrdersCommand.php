<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:import {path : Path to the CSV file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        if (!is_readable($path)) {
            $this->error('File not readable');
            return self::FAILURE;
        }

        $batch = (string) Str::uuid();
        $groups = []; // group by order_number
        if (($h = fopen($path, 'r')) !== false) {
            $header = fgetcsv($h);
            while (($row = fgetcsv($h)) !== false) {
                $data = array_combine($header, $row);
                $groups[$data['order_number']][] = $data;
            }
            fclose($h);
        }

        foreach ($groups as $orderNumber => $lines) {
            \App\Jobs\ImportOrdersJob::dispatch($lines, $batch);
        }

        $this->info('Dispatched import jobs for ' . count($groups) . ' orders.');
        return self::SUCCESS;
    }
}
