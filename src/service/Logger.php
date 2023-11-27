<?php

namespace delivery\service;

use delivery\Application;
use InvalidArgumentException;

/**
 * Logger records logged messages in memory and sends them to log file.
 */
class Logger
{
    /**
     * @var string log file path.
     * The directory containing the log files will be automatically created if not existing.
     */
    public $logFile;
    /**
     * @var int maximum log file size, in mega-bytes. Defaults to 10, meaning 10MB.
     */
    public $maxFileSize = 10; // in MB
    /**
     * @var int number of log files used for rotation. Defaults to 5.
     */
    public $maxLogFiles = 5;
    /**
     * @var int the permission to be set for newly created log files.
     * If not set, the permission will be determined by the current environment.
     */
    public $fileMode;
    /**
     * @var int the permission to be set for newly created directories.
     * Defaults to 0775, meaning the directory is read-writable by owner and group,
     * but read-only for other users.
     */
    public $dirMode = 0775;

    /**
     * Error message level. An error message is one that indicates the abnormal termination of the
     * application and may require developer's handling.
     */
    const LEVEL_ERROR = 0x01;
    /**
     * Warning message level. A warning message is one that indicates some abnormal happens but
     * the application is able to continue to run. Developers should pay attention to this message.
     */
    const LEVEL_WARNING = 0x02;
    /**
     * Informational message level. An informational message is one that includes certain information
     * for developers to review.
     */
    const LEVEL_INFO = 0x04;

    /**
     * @var array logged messages. This property is managed by [[log()]] and [[flush()]].
     * Each log message is of the following structure:
     *
     * ```
     * [
     *   [0] => message (mixed, can be a string or some complex data, such as an exception object)
     *   [1] => level (integer)
     *   [2] => category (string)
     *   [3] => timestamp (float, obtained by microtime(true))
     *   [4] => traces (array, debug backtrace, contains the application code call stacks)
     *   [5] => memory usage in bytes (int, obtained by memory_get_usage()), available since version 2.0.11.
     * ]
     * ```
     */
    public $messages = [];
    /**
     * @var int how much call stack information (file name and line number) should be logged for each message.
     * If it is greater than 0, at most that number of call stacks will be logged. Note that only application
     * call stacks are counted.
     */
    public $traceLevel = 10;
    /**
     * @var array list of message categories that this target is interested in.
     * Defaults to empty, meaning all categories.
     */
    public $categories = [];

    /**
     * @var array list of message categories that this target is NOT interested in.
     * Defaults to empty, meaning no uninteresting messages.
     * @see categories
     */
    public $except = [];


    public $levels = 0;

    /**
     * @var Application
     */
    private $application;


    /**
     * Initializes the logger by registering [[flush()]] as a shutdown function.
     */
    public function __construct()
    {
        if (empty($this->logFile)) {
            $this->logFile = dirname(dirname(__DIR__)).'/log/app.log';
        }
        $logPath = dirname($this->logFile);
        $logPath = realpath($logPath);
        if (!is_dir($logPath)) {
            $this->createDirectory($logPath, $this->dirMode, true);
        }
        if ($this->maxLogFiles < 1) {
            $this->maxLogFiles = 1;
        }
        if ($this->maxFileSize < 1) {
            $this->maxFileSize = 1;
        }
        register_shutdown_function(function () {
            // make regular flush before other shutdown functions, which allows session data collection and so on
            $this->flush();
            // make sure log entries written by shutdown functions are also flushed
            // ensure "flush()" is called last when there are multiple shutdown functions
            register_shutdown_function([$this, 'flush'], true);
        });
    }

    /**
     * @param Application|null $application
     */
    public function setApplication(Application $application = null)
    {
        $this->application = $application;
    }

    /**
     * @return Application
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'logger';
    }

    /**
     * Logs a message with the given type and category.
     * If [[traceLevel]] is greater than 0, additional call stack information about
     * the application code will be logged as well.
     * @param string|array $message the message to be logged. This can be a simple string or a more
     * complex data structure that will be handled by a [[Target|log target]].
     * @param int $level the level of the message. This must be one of the following:
     * `Logger::LEVEL_ERROR`, `Logger::LEVEL_WARNING`, `Logger::LEVEL_INFO`.
     * @param string $category the category of the message.
     */
    public function log($message, $level, $category = 'application')
    {
        $time = microtime(true);
        $traces = [];
        if ($this->traceLevel > 0) {
            $count = 0;
            $backTraces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            array_pop($backTraces); // remove the last trace since it would be the entry script, not very useful
            foreach ($backTraces as $trace) {
                if (isset($trace['file'], $trace['line'])) {
                    unset($trace['object'], $trace['args']);
                    $traces[] = $trace;
                    if (++$count >= $this->traceLevel) {
                        break;
                    }
                }
            }
        }
        $this->messages[] = [$message, $level, $category, $time, $traces, memory_get_usage()];
        if (count($this->messages) > 0) {
            $this->flush();
        }
    }

    /**
     * Flushes log messages from memory to targets.
     */
    public function flush()
    {
        $messages = $this->messages;
        // new messages could be logged while the existing ones are being handled by targets
        $this->messages = [];
        $this->collect($messages);
    }

    /**
     * Returns the text display of the specified level.
     * @param int $level the message level, e.g. [[LEVEL_ERROR]], [[LEVEL_WARNING]].
     * @return string the text display of the level
     */
    public static function getLevelName($level)
    {
        static $levels = [
            self::LEVEL_ERROR => 'error',
            self::LEVEL_WARNING => 'warning',
            self::LEVEL_INFO => 'info',
        ];

        return isset($levels[$level]) ? $levels[$level] : 'unknown';
    }

    /**
     * Processes the given log messages.
     * This method will filter the given messages with [[levels]] and [[categories]].
     * And if requested, it will also export the filtering result to specific medium (e.g. email).
     * @param array $messages log messages to be processed. See [[Logger::messages]] for the structure
     * of each message.
     */
    public function collect($messages)
    {
        $this->messages = array_merge(
            $this->messages,
            static::filterMessages(
                $messages,
                $this->getLevels(),
                $this->categories
            )
        );
        $count = count($this->messages);
        if ($count > 0) {
            $this->export();
            $this->messages = [];
        }
    }

    /**
     * Writes log messages to a file.
     * @throws InvalidArgumentException if unable to open the log file for writing
     */
    public function export()
    {
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";
        if (($handle = @fopen($this->logFile, 'a')) === false) {
            throw new InvalidArgumentException("Unable to append to log file: {$this->logFile}");
        }
        @flock($handle, LOCK_EX);
        // clear stat cache to ensure getting the real current file size and not a cached one
        // this may result in rotating twice when cached file size is used on subsequent calls
        clearstatcache();
        if (@filesize($this->logFile) > $this->maxFileSize * 1048576) {
            $this->rotateFiles();
            @flock($handle, LOCK_UN);
            @fclose($handle);
            @file_put_contents($this->logFile, $text, FILE_APPEND | LOCK_EX);
        } else {
            @fwrite($handle, $text);
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        if ($this->fileMode !== null) {
            @chmod($this->logFile, $this->fileMode);
        }
    }

    /**
     * Filters the given messages according to their categories and levels.
     * @param array $messages messages to be filtered.
     * The message structure follows that in [[Logger::messages]].
     * @param int $levels the message levels to filter by. This is a bitmap of
     * level values. Value 0 means allowing all levels.
     * @param array $categories the message categories to filter by. If empty, it means all categories are allowed.
     * @return array the filtered messages.
     */
    public static function filterMessages($messages, $levels = 0, $categories = [])
    {
        foreach ($messages as $i => $message) {
            if (is_numeric($levels) && is_numeric($message[1]) && !($levels & $message[1])) {
                unset($messages[$i]);
                continue;
            }
            $matched = empty($categories);
            foreach ($categories as $category) {
                if ($message[2] === $category || !empty($category)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                unset($messages[$i]);
            }
        }

        return $messages;
    }

    /**
     * Formats a log message for display as a string.
     * @param array $message the log message to be formatted.
     * The message structure follows that in [[Logger::messages]].
     * @return string the formatted message
     */
    public function formatMessage($message)
    {
        list($text, $level, $category, $timestamp) = $message;
        $level = Logger::getLevelName($level);
        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof \Throwable || $text instanceof \Exception) {
                $text = (string)$text;
            } else {
                $text = var_export($text, true);
            }
        }
        $traces = [];
        if (isset($message[4])) {
            foreach ($message[4] as $trace) {
                $traces[] = "in {$trace['file']}:{$trace['line']}";
            }
        }

        return date('Y-m-d H:i:s', $timestamp) . " [$level][$category] $text"
            . (empty($traces) ? '' : "\n    " . implode("\n    ", $traces));
    }

    /**
     * @return int the message levels that this target is interested in. This is a bitmap of
     * level values. Defaults to 0, meaning  all available levels.
     */
    public function getLevels()
    {
        return $this->levels;
    }

    /**
     * Sets the message levels that this target is interested in.
     *
     * The parameter can be either an array of interested level names or an integer representing
     * the bitmap of the interested level values. Valid level names include: 'error',
     * 'warning', 'info', 'trace' and 'profile'; valid level values include:
     * [[Logger::LEVEL_ERROR]], [[Logger::LEVEL_WARNING]], [[Logger::LEVEL_INFO]].
     *
     * @param array|int $levels message levels that this target is interested in.
     * @throws InvalidArgumentException if $levels value is not correct.
     */
    public function setLevels($levels)
    {
        static $levelMap = [
            'error' => Logger::LEVEL_ERROR,
            'warning' => Logger::LEVEL_WARNING,
            'info' => Logger::LEVEL_INFO,
        ];
        if (is_array($levels)) {
            $this->levels = 0;
            foreach ($levels as $level) {
                if (isset($levelMap[$level])) {
                    $this->levels |= $levelMap[$level];
                } else {
                    throw new InvalidArgumentException("Unrecognized level: $level");
                }
            }
        } else {
            $bitmapValues = array_reduce($levelMap, function ($carry, $item) {
                return $carry | $item;
            });
            if (!($bitmapValues & $levels) && $levels !== 0) {
                throw new InvalidArgumentException("Incorrect $levels value");
            }
            $this->levels = $levels;
        }
    }

    /**
     * Creates a new directory.
     *
     * This method is similar to the PHP `mkdir()` function except that
     * it uses `chmod()` to set the permission of the created directory
     * in order to avoid the impact of the `umask` setting.
     *
     * @param string $path path of the directory to be created.
     * @param int $mode the permission to be set for the created directory.
     * @param bool $recursive whether to create parent directories if they do not exist.
     * @return bool whether the directory is created successfully
     * @throws InvalidArgumentException if the directory could not be created (i.e. php error due to parallel changes)
     */
    public function createDirectory($path, $mode = 0775, $recursive = true)
    {
        if (is_dir($path)) {
            return true;
        }
        $parentDir = dirname($path);
        // recurse if parent dir does not exist and we are not at the root of the file system.
        if ($recursive && !is_dir($parentDir) && $parentDir !== $path) {
            $this->createDirectory($parentDir, $mode, true);
        }
        try {
            if (!mkdir($path, $mode)) {
                return false;
            }
        } catch (\Exception $e) {
            if (!is_dir($path)) {
                throw new InvalidArgumentException(
                    "Failed to create directory \"$path\": " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        }
        try {
            return chmod($path, $mode);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                "Failed to change permissions for directory \"$path\": " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Rotates log files.
     */
    protected function rotateFiles()
    {
        $file = $this->logFile;
        for ($i = $this->maxLogFiles; $i >= 0; --$i) {
            // $i == 0 is the original log file
            $rotateFile = $file . ($i === 0 ? '' : '.' . $i);
            if (is_file($rotateFile)) {
                // suppress errors because it's possible multiple processes enter into this section
                if ($i === $this->maxLogFiles) {
                    @unlink($rotateFile);
                } else {
                    @rename($rotateFile, $file . '.' . ($i + 1));
                }
            }
        }
    }
}
