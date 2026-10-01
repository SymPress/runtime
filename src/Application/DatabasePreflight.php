<?php

declare(strict_types=1);

namespace SymPress\Runtime\Application;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Console\Io;
use SymPress\Runtime\Console\Selection;
use SymPress\Runtime\Database\DbChecker;

/** @internal */
final readonly class DatabasePreflight
{
    public function __construct(private Config $config, private DbChecker $database, private Io $io)
    {
    }

    public function run(Selection $selection): bool
    {
        if ($selection->list || ($selection->selected() && $this->config['compatibility-profile']->is('upstream-dev'))) {
            return true;
        }
        if ($this->config['skip-db-check']->is(true)) {
            return true;
        }
        if ($this->config['db-check']->is(false)) {
            return true;
        }
        if ($this->config['db-check']->is(DbChecker::HEALTH_CHECK) && $this->config['compatibility-profile']->not('native')) {
            $healthy = $this->database->mysqlcheck();
            if (!$healthy) {
                $this->io->error('Database health check did not pass.');
            }

            return true;
        }
        if ($this->config['db-check']->is(DbChecker::HEALTH_CHECK)) {
            $this->io->comment('Table health inspection is explicit: run doctor --database-health (optionally --quick).');
        }
        $status = $this->database->status();
        if ($status->exists === null || $status->installed === null) {
            $this->io->comment('Database status is unknown: ' . $status->reason . '.');
        }

        return true;
    }
}
