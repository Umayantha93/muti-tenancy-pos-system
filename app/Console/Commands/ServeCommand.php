<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'serve')]
class ServeCommand extends BaseServeCommand
{
    /**
     * @return list<string>
     */
    protected function serverCommand(): array
    {
        $command = parent::serverCommand();
        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize=128M',
            '-d', 'post_max_size=128M',
            '-d', 'max_file_uploads=20',
            '-d', 'max_execution_time=180',
        ]);

        return $command;
    }
}
