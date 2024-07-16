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

/**
 * @since 1.0.0
 */
class TodebugLiteLogger
{
	const LOG_DIR_NAME  = 'todebug';
	const LOG_FILE_NAME = 'todebug.log';

	/** @since 1.1.0 */
	const DATETIME_FORMAT_LOG    = 'Y-m-d H:i:s';
	/** @since 1.1.0 */
	const DATETIME_FORMAT_PUBLIC = 'F j Y, H:i:s';

	/** @var bool */
	private static $isRequestsSeparated = false;

	private static function getLogDir(): string
	{
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . DIRECTORY_SEPARATOR
			. static::LOG_DIR_NAME . DIRECTORY_SEPARATOR;
	}

	private static function getLogFile(): string
	{
		return static::getLogDir() . static::LOG_FILE_NAME;
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

	/**
	 * @since 1.0.2
	 */
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
	 * @since 1.1.0
	 *
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

	/**
	 * @since 1.1.0
	 */
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
		return 'Options -Indexes' . PHP_EOL
			. 'deny from all' . PHP_EOL
			. "<FilesMatch '\.(jpg|jpeg|png|gif|mp3|ogg)$'>" . PHP_EOL
				. "\tOrder Allow,Deny" . PHP_EOL
				. "\tAllow from all" . PHP_EOL
			. '</FilesMatch>' . PHP_EOL;
	}

	/**
	 * @access private
	 *
	 * @param array $args Optional.
	 *     @param bool $args['strict'] Whether to wrap strings with "". True by
	 *         default.
	 *     @param bool $args['skip_first'] Don't apply "strict" to the first
	 *         parameter. True by default.
	 */
	public static function varsToString(array $vars, array $args = []): string
	{
		$args += [
			'strict'     => true,
			'skip_first' => true,
		];

		$strings = [];
		$i = 1;

		foreach($vars as $var) {
			$string = '';

			if (is_string($var)) {
				if ($args['strict'] && $args['skip_first'] && $i == 1) {
					$string = static::varToString($var, ['strict' => false]);
				} else {
					$string = static::varToString($var, $args);
				}
			} else {
				$string = static::varToString($var, $args);
			}

			$strings[] = $string;

			$i++;
		}

		return implode(' ', $strings);
	}

	/**
	 * @param array $args Optional.
	 *     @param bool $args['strict'] Whether to wrap strings with "". True by
	 *         default.
	 */
	private static function varToString($var, array $args = []): string
	{
		$args += [
			'strict' => true,
		];

		$string = '';

		if (is_null($var)) {
			$string = 'null';

		} else if (is_bool($var)) {
			$string = $var ? 'true' : 'false';

		} else if (is_int($var)) {
			$string = (string)$var;

		} else if (is_float($var)) {
			$string = number_format($var, 3, '.', '');

		} else if (is_string($var)) {
			if ($args['strict']) {
				$string = '"' . $var . '"';
			} else {
				$string = $var;
			}

		} else if (is_array($var)) {
			$string = static::arrayToString($var);

		} else if ($var instanceof DateTime) {
			$string = $var->format(static::DATETIME_FORMAT_PUBLIC);
			$string = '{' . $string . '}';

		} else {
			ob_start();
			var_dump($var);
			$string = ob_get_clean();
			$string = rtrim($string, PHP_EOL);
		}

		return trim($string);
	}

	private static function arrayToString(array $array): string
	{
		$keys  = [];
		$items = [];

		$i = 0;

		$isNaturalIndexes = true;

		foreach ($array as $key => $value) {
			$items[] = static::varToString($value);

			if ($key === $i) {
				$keys[] = $i;
			} else {
				$keys[] = static::varToString($key);
				$isNaturalIndexes = false;
			}

			$i++;
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

if (!function_exists('todebug')) {
	/**
	 * Similar to <code>todebugs()</code>, but doesn't wrap the first string in
	 * quotes "".
	 *
	 * @example <code>todebug('My message:', $var1, $var2)</code>
	 *
	 * @since 1.0.0
	 */
	function todebug(...$vars)
	{
		$message = TodebugLiteLogger::varsToString(
			$vars,
			[
				'strict'     => true,
				'skip_first' => true,
			]
		);

		TodebugLiteLogger::logAuto($message);
	}
}

if (!function_exists('todebugs')) {
	/**
	 * Strict version of <code>todebug()</code> (wraps all strings in quotes "").
	 *
	 * @since 1.0.0
	 */
	function todebugs(...$vars)
	{
		$message = TodebugLiteLogger::varsToString(
			$vars,
			[
				'strict'     => true,
				'skip_first' => false,
			]
		);

		TodebugLiteLogger::logAuto($message);
	}
}

if (!function_exists('todebugm')) {
	/**
	 * Treats all strings as messages and doesn't wrap strings in quotes "".
	 *
	 * @since 1.0.0
	 */
	function todebugm(...$vars)
	{
		$message = TodebugLiteLogger::varsToString(
			$vars,
			[
				'strict'     => false,
				'skip_first' => true,
			]
		);

		TodebugLiteLogger::logAuto($message);
	}
}
