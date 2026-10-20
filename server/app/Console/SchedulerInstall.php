<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

/**
 * Scheduled posts need something to wake up when they are due. On macOS that
 * is launchd, running `postslop run-due` once a minute; nothing stays running.
 */
final readonly class SchedulerInstall extends CliCommand
{
    private const string LABEL = 'sh.postslop.scheduler';

    public function description(): string
    {
        return <<<'TEXT'
            Publish scheduled posts on time: install, remove or check the launchd job
            Usage: postslop scheduler install | uninstall | status
            The job runs postslop run-due every minute. Logs: storage/logs/scheduler.log
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw Failure::invalid(
                'The scheduler job uses launchd on macOS. Elsewhere, run "postslop run-due" every minute from cron.',
            );
        }
        $plist = getenv('HOME') . '/Library/LaunchAgents/' . self::LABEL . '.plist';
        $domain = 'gui/' . getmyuid();
        switch ($input->argument(0, 'status')) {
            case 'install':
                self::launchctl(['bootout', $domain . '/' . self::LABEL]);
                if (!is_dir(dirname($plist))) {
                    mkdir(dirname($plist), 0o755, true);
                }
                file_put_contents($plist, self::plist());
                if (self::launchctl(['bootstrap', $domain, $plist]) !== 0) {
                    throw Failure::invalid('launchctl could not load ' . $plist . '.');
                }
                $output->line('Scheduled posts now publish on time, even with Postslop closed.');
                return self::OK;
            case 'uninstall':
                self::launchctl(['bootout', $domain . '/' . self::LABEL]);
                if (is_file($plist)) {
                    unlink($plist);
                }
                $output->line('Removed the scheduler job. Scheduled posts wait until you run postslop run-due.');
                return self::OK;
            case 'status':
                $loaded = self::launchctl(['print', $domain . '/' . self::LABEL]) === 0;
                $output->line(
                    $loaded
                        ? 'The scheduler job is running every minute.'
                        : 'The scheduler job is not installed. Run: postslop scheduler install',
                );
                return $loaded ? self::OK : self::FAILED;
            default:
                throw Failure::invalid('Use: postslop scheduler install | uninstall | status');
        }
    }

    private static function plist(): string
    {
        $root = dirname(__DIR__, 2);
        // Homebrew's stable path survives PHP upgrades; the versioned Cellar path does not.
        $php = is_executable('/opt/homebrew/bin/php') ? '/opt/homebrew/bin/php' : PHP_BINARY;
        $log = $root . '/storage/logs/scheduler.log';
        $values = array_map(static fn(string $value): string => htmlspecialchars($value, ENT_XML1), [
            self::LABEL,
            $php,
            $root . '/bin/postslop',
            $root,
            $log,
        ]);
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
                <key>Label</key><string>{$values[0]}</string>
                <key>ProgramArguments</key>
                <array><string>{$values[1]}</string><string>{$values[2]}</string><string>run-due</string></array>
                <key>WorkingDirectory</key><string>{$values[3]}</string>
                <key>StartInterval</key><integer>60</integer>
                <key>RunAtLoad</key><true/>
                <key>StandardOutPath</key><string>{$values[4]}</string>
                <key>StandardErrorPath</key><string>{$values[4]}</string>
            </dict>
            </plist>

            XML;
    }

    private static function launchctl(array $arguments): int
    {
        $process = proc_open(
            ['launchctl', ...$arguments],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        return is_resource($process) ? proc_close($process) : 1;
    }
}
