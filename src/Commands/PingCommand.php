<?php

namespace Kandita\Paydita\Commands;

use Illuminate\Console\Command;
use Kandita\Paydita\Exceptions\PayditaException;
use Kandita\Paydita\PayditaClient;

class PingCommand extends Command
{
    protected $signature = 'paydita:ping';

    protected $description = 'Check that this application can reach Paydita with its credentials';

    public function handle(PayditaClient $client): int
    {
        try {
            $result = $client->ping();
        } catch (PayditaException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connected as {$result['application_code']} in {$result['environment']}.");

        return self::SUCCESS;
    }
}
