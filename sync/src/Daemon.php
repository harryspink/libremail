<?php

namespace App;

use Exception
  , App\Command
  , App\Events\StatsEvent
  , App\Console\DaemonConsole
  , React\ChildProcess\Process
  , React\EventLoop\LoopInterface
  , App\Exceptions\Terminate as TerminateException
  , Symfony\Component\EventDispatcher\EventDispatcher as Emitter;

class Daemon
{
    // Flag to not attempt to restart process
    private $halt;
    // Reference to the React event loop
    private $loop;
    private $config;
    private $emitter;
    private $console;
    private $command;
    // Used for internal message passing
    private $message;
    private $isReading;
    // Stored to send signals to
    private $syncProcess;
    // Ratchet websocket server
    private $webServerProcess;
    // References to true PIDs
    private $processPids = [];
    private $processRestartInterval = [];

    const PROC_SYNC = 'sync';
    const PROC_SERVER = 'server';
    const MESSAGE_PID = 'pid';
    const MESSAGE_STATS = 'stats';

    public function __construct(
        LoopInterface $loop,
        Emitter $emitter,
        DaemonConsole $console,
        Command $command,
        Array $config )
    {
        $this->loop = $loop;
        $this->config = $config;
        $this->emitter = $emitter;
        $this->console = $console;
        $this->command = $command;
        $this->processRestartInterval = [
            PROC_SYNC => $config[ 'restart_interval' ][ PROC_SYNC ],
            PROC_SERVER => $config[ 'restart_interval' ][ PROC_SERVER ]
        ];
    }

    public function startSync()
    {
        if ( $this->halt === TRUE ) {
            return;
        }

        if ( $this->syncProcess ) {
            $this->syncProcess->close();
            $this->syncProcess = NULL;
            $this->processPids[ PROC_SYNC ] = NULL;
        }

        $syncProcess = new Process( BASEPATH . EXEC_SYNC );

        // When the sync process exits, we want to alert the
        // daemon. This is to restart the sync upon crash or
        // to handle the error.
        $syncProcess->on( 'exit', function ( $exitCode, $termSignal ) {
            $this->emitter->dispatch( EV_SYNC_EXITED );
        });

        // Start the sync engine immediately
        $this->loop->addTimer( 0.001, function ( $timer ) use ( $syncProcess ) {
            $syncProcess->start( $timer->getLoop() );
            $syncProcess->stdout->on( 'data', function ( $output ) {
                // If JSON came back, we have a message to parse. Run
                // it through our message handler. Otherwise forward the
                // output to STDOUT.
                $this->processMessage( $output, PROC_SYNC );
            });
        });

        // Every 10 seconds signal the sync process to get statistics.
        // Only do this if the webserver is running.
        $this->loop->addPeriodicTimer( 10, function ( $timer ) use ( $syncProcess ) {
            if ( isset( $this->processPids[ PROC_SYNC ] )
                && $this->webServerProcess )
            {
                posix_kill( $this->processPids[ PROC_SYNC ], SIGUSR2 );
            }
        });

        $this->syncProcess = $syncProcess;
    }

    public function startWebServer()
    {
        if ( ! $this->console->webServer ) {
            return;
        }

        if ( $this->webServerProcess ) {
            $this->webServerProcess->close();
            $this->webServerProcess = NULL;
            $this->processPids[ PROC_SERVER ] = NULL;
        }

        if ( $this->halt === TRUE ) {
            return;
        }

        $webServerProcess = new Process( BASEPATH . EXEC_SERVER );

        // When the server process exits, we want to alert the
        // daemon. This is to restart the server upon crash or
        // to handle the error.
        $webServerProcess->on( 'exit', function ( $exitCode, $termSignal ) {
            $this->emitter->dispatch( EV_SERVER_EXITED );
        });

        // Start the web server immediately
        $this->loop->addTimer( 0.001, function ( $timer ) use ( $webServerProcess ) {
            $webServerProcess->start( $timer->getLoop() );
            $webServerProcess->stdout->on( 'data', function ( $output ) {
                $this->processMessage( $output, PROC_SERVER );
            });
        });

        $this->webServerProcess = $webServerProcess;
    }

    /**
     * Emits an event to restart a process after an interval
     * of time has passed, plus some decay value. The decay is
     * used to not thrash a reconnect if we have networking
     * problems.
     * @param string $process
     */
    public function restartWithDecay( $process, $event )
    {
        $decay = $this->config[ 'decay' ][ $process ];
        $restartMax = $this->config[ 'restart_max' ][ $process ];
        $currentInterval = $this->processRestartInterval[ $process ];
        $nextInterval = ( $decay * $currentInterval < $restartMax )
            ? $decay * $currentInterval
            : $restartMax;

        $this->loop->addTimer( $nextInterval, function ( $timer ) {
            $this->emitter->dispatch( $event );
        });

        // Update the restart interval for next time. At some point,
        // this needs to reset back to the starting interval.
        // @TODO
        $this->processRestartInterval[ $process ] = $nextInterval;
    }

    public function broadcastStats( $stats )
    {
        if ( ! $this->webServerProcess ) {
            return FALSE;
        }

        // Resume the stdin stream, send the message and then pause
        // it again.
        $this->webServerProcess->stdin->resume();
        $this->webServerProcess->stdin->write( json_encode( $stats ) );
        $this->webServerProcess->stdin->pause();
    }

    public function halt()
    {
        $this->halt = TRUE;

        if ( isset( $this->processPids[ PROC_SYNC ] ) ) {
            posix_kill( $this->processPids[ PROC_SYNC ], SIGQUIT );
        }

        if ( isset( $this->processPids[ PROC_SERVER ] ) ) {
            posix_kill( $this->processPids[ PROC_SERVER ], SIGQUIT );
        }

        $this->processPids = [];
    }

    private function processMessage( $message, $process )
    {
        if ( substr( $message, 0, 1 ) === "{" ) {
            $this->message = "";
            $this->isReading = TRUE;
        }

        if ( $this->isReading ) {
            $this->message .= $message;

            if ( substr( $message, -1 ) === "}" ) {
                $this->isReading = FALSE;
                $message = @json_decode( $this->message );
                $this->message = NULL;
                $this->handleMessage( $message, $process );
            }

            return;
        }

        // If the message was a command from a sub-process, then
        // handle that command. Otherwise print this message.
        if ( $this->handleCommand( $message ) ) {
            return;
        }

        fwrite( STDOUT, $message );
    }

    /**
     * Reads in a JSON message from one of the child processes.
     * The message expects certain fields to be set:
     *   type: 'pid' or 'stats'
     * @param stdClass $message
     * @param string $process
     */
    private function handleMessage( $message, $process )
    {
        switch ( $message->type ) {
            case self::MESSAGE_PID:
                $this->processPids[ $process ] = $message->pid;
                break;
            case self::MESSAGE_STATS:
                $this->emitter->dispatch(
                    EV_BROADCAST_STATS,
                    new StatsEvent( $message ) );
                break;
        }
    }

    /**
     * Reads in a message to determine if the message is a
     * command from a subprocess. If it is, pass this command
     * off to our handler library and return true.
     * @param string $message
     * @return boolean
     */
    private function handleCommand( $message )
    {
        if ( $this->command->isValid( $message ) ) {
            $this->command->run( $message );
            return TRUE;
        }

        return FALSE;
    }
}