<?php

/*
 * Plugin Name: Todebug Lite
 * Plugin URI: https://github.com/byevhen2/todebug-lite
 * Description: A simple WordPress logger.
 * Version: 1.1.0
 * Requires at least: 4.8
 * Requires PHP: 7.0
 * Author: Biliavskyi Yevhen
 * Author URI: https://github.com/byevhen2
 * License: MIT
 * Text Domain: todebug
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

class TodebugLiteLogger
{
	const LOG_DIR_NAME  = 'todebug';
	const LOG_FILE_NAME = 'todebug.log';

	const DATETIME_FORMAT_LOG    = 'Y-m-d H:i:s';
	const DATETIME_FORMAT_PUBLIC = 'F j Y, H:i:s';

	/** @var string */
	private static $logDir = '';

	/** @var string */
	private static $logFile = '';

	/** @var bool */
	private static $isRequestsSeparated = false;

	private static function getLogDir(): string
	{
		if (static::$logDir == '') {
			$uploads = wp_upload_dir();
			$baseDir = $uploads['basedir'];

			static::$logDir = $baseDir . DIRECTORY_SEPARATOR
				. static::LOG_DIR_NAME . DIRECTORY_SEPARATOR;
		}

		return static::$logDir;
	}

	private static function getLogFile(): string
	{
		if (static::$logFile == '') {
			static::$logFile = static::getLogDir() . static::LOG_FILE_NAME;
		}

		return static::$logFile;
	}

	private static function createLogDir(): bool
	{
		$dir = static::getLogDir();
		$isDirCreated = wp_mkdir_p($dir);

		if ($isDirCreated) {
			// Add index.php and .htaccess files.
			if (!file_exists($dir . 'index.php')) {
				file_put_contents($dir . 'index.php', static::getIndexPhpContent());
			}

			if (!file_exists($dir . '.htaccess')) {
				file_put_contents($dir . '.htaccess', static::getHtaccessContent());
			}
		}

		return $isDirCreated;
	}

	private static function createLogFile()
	{
		// Create directory?
		if (!is_dir(static::getLogDir())) {
			if (!static::createLogDir()) {
				return;
			}
		}

		// Create log file.
		file_put_contents(static::getLogFile(), '');

		// Nothing to separate in an empty file.
		static::$isRequestsSeparated = true;
	}

	public static function log(string $message)
	{
		$file = static::getLogFile();

		if (!file_exists($file)) {
			static::createLogFile();
		}

		if (!is_writable($file)) {
			return;
		}

		static::separateRequestsOnce();

		$time   = static::getCurrentTimeString('wp');
		$prefix = "[{$time}]";

		if ($message !== '') {
			file_put_contents($file, static::prefixMessage($message, $prefix) . PHP_EOL, FILE_APPEND);
		} else {
			file_put_contents($file, PHP_EOL, FILE_APPEND);
		}
	}

	public static function logAJAX(string $message)
	{
		static::log(static::prefixMessage($message, '[AJAX]'));
	}

	public static function logCron(string $message)
	{
		static::log(static::prefixMessage($message, '[Cron]'));
	}

	/**
	 * @requires WordPress 4.8.0
	 */
	public static function logAuto(string $message)
	{
		if (wp_doing_ajax()) {
			static::logAJAX($message);
		} else if (wp_doing_cron()) {
			static::logCron($message);
		} else {
			static::log($message);
		}
	}

	private static function prefixMessage(string $message, string $prefix): string
	{
		if ($message !== '') {
			return $prefix . ' ' . $message;
		} else {
			// Don't add a prefix for empty lines.
			return $message;
		}
	}

	/**
	 * @requires WordPress 5.3.0
	 *
	 * @param null|'wp'|string $timeZone Optional. Null by default (use the
	 *     server's time zone).
	 */
	private static function getCurrentTimeString($timeZone = null): string
	{
		if (!is_null($timeZone)) {
			if ($timeZone == 'wp') {
				if (function_exists('wp_timezone')) {
					$timeZone = wp_timezone();
				} else {
					$timeZone = null;
				}
			} else {
				$timeZone = new DateTimeZone($timeZone);
			}
		}

		$now = new DateTime('now', $timeZone);

		return $now->format(static::DATETIME_FORMAT_LOG);
	}

	/**
	 * Places a separator between messages of different requests.
	 */
	private static function separateRequestsOnce()
	{
		if (!static::$isRequestsSeparated) {
			static::addSeparator();

			static::$isRequestsSeparated = true;
		}
	}

	private static function addSeparator()
	{
		$separator = PHP_EOL . '------------------------------' . PHP_EOL . PHP_EOL;

		file_put_contents(static::getLogFile(), $separator, FILE_APPEND);
	}

	private static function getIndexPhpContent(): string
	{
		return '<?php' . PHP_EOL
			. '// Silence is golden.' . PHP_EOL;
	}

	private static function getHtaccessContent(): string
	{
		return 'Order allow,deny' . PHP_EOL
			. 'Deny from all' . PHP_EOL;
	}

	private static function getHtaccessWithMediaContent(): string
	{
		return 'Options -Indexes' . PHP_EOL
			. 'Deny from all' . PHP_EOL
			. "<FilesMatch '\.(jpg|jpeg|png|gif|mp3|ogg)$'>" . PHP_EOL
				. "\tOrder allow,deny" . PHP_EOL
				. "\tAllow from all" . PHP_EOL
			. '</FilesMatch>' . PHP_EOL;
	}

	public static function getType($var): string
	{
		$type = gettype($var);

		if ($type === 'NULL') {
			return 'null';
		} else if ($type == 'double') {
			return 'float';
		} else if (in_array($type, ['boolean', 'integer', 'string', 'array'])) {
			return $type;
		} else if ($var instanceof DateTime) {
			return 'DateTime';
		} else {
			return 'mixed';
		}
	}

	/**
	 * @param true|false|'not first' $strict Whether to wrap all strings with "".
	 */
	public static function toString(array $vars, $strict = 'not first'): string
	{
		$i = 1;
		$strings = [];

		foreach ($vars as $var) {
			$isStrict = $strict === true
				// "not first" only works on the first string.
				|| ($strict == 'not first' && ($i > 1 || !is_string($var)));

			$type   = static::getType($var);
			$string = static::toStringVar($var, $type, $isStrict);

			if ($string !== '') {
				$strings[] = $string;
			}

			$i++;
		}

		return implode(' ', $strings);
	}

	private static function toStringVar($var, string $type, bool $isStrict = true): string
	{
		$string = '';

		switch ($type) {
			case 'null':
				$string = 'null';
				break;

			case 'boolean':
				$string = $var ? 'true' : 'false';
				break;

			case 'integer':
				$string = (string)$var;
				break;

			case 'float':
				$string = number_format($var, 3, '.', '');
				break;

			case 'string':
				if ($isStrict) {
					$string = '"' . $var . '"';
				} else {
					$string = $var;
				}
				break;

			case 'array':
				$string = static::toStringArray($var);
				break;

			case 'DateTime':
				$string = '{' . $var->format(static::DATETIME_FORMAT_PUBLIC) . '}';
				break;

			case 'mixed':
			default:
				ob_start();
					var_dump($var);
				$string = ob_get_clean();

				$string = rtrim($string, PHP_EOL);

				break;
		}

		return trim($string);
	}

	private static function toStringArray(array $array): string
	{
		$keys  = [];
		$items = [];

		$index = 0;
		$isNaturalIndexes = true;

		foreach ($array as $key => $value) {
			$items[] = static::toStringVar($value, static::getType($value));

			if ($key === $index) {
				$keys[] = $index;
			} else {
				$keys[] = static::toStringVar($key, static::getType($key));
				$isNaturalIndexes = false;
			}

			$index++;
		}

		if ($isNaturalIndexes) {
			return '[' . implode(', ', $items) . ']';

		} else {
			$pairs = array_map(
				function ($key, $value) {
					return $key . ' => ' . $value;
				},
				$keys,
				$items
			);

			return '[' . implode(', ', $pairs) . ']';
		}
	}
}

/**
 * Similar to `todebugs()`, but doesn't wrap the first string in quotes `""`.
 *
 * @example `todebug('My message:', $var1, $var2)`
 */
function todebug(...$vars)
{
	$message = TodebugLite::toString($vars, 'not first');

	TodebugLite::logAuto($message);
}

/**
 * Strict version of `todebug()` (wraps all strings in quotes `""`).
 */
function todebugs(...$vars)
{
	$message = TodebugLite::toString($vars, true);

	TodebugLite::logAuto($message);
}

/**
 * Treats all strings as messages and doesn't wrap them in quotes `""`.
 */
function todebugm(...$vars)
{
	$message = TodebugLite::toString($vars, false);

	TodebugLite::logAuto($message);
}
