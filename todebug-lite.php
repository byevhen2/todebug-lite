<?php

/*
 * Plugin Name: Todebug Lite
 * Plugin URI: https://github.com/byevhen2/todebug-lite
 * Description: A simple WordPress logger.
 * Version: 1.0.2
 * Requires at least: 4.8
 * Requires PHP: 7.0
 * Author: Biliavskyi Yevhen
 * Author URI: https://github.com/byevhen2
 * License: MIT
 * Text Domain: todebug
 */

if (!defined('ABSPATH')) {
	exit;
}

class TodebugLiteLogger
{
	const LOG_DIR_NAME  = 'todebug';
	const LOG_FILE_NAME = 'todebug.log';

	/**
	 * @var bool
	 */
	protected static $isRequestsSeparated = false;

	protected static function getLogDir(): string
	{
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . DIRECTORY_SEPARATOR
			. static::LOG_DIR_NAME . DIRECTORY_SEPARATOR;
	}

	protected static function getLogFile(): string
	{
		return static::getLogDir() . static::LOG_FILE_NAME;
	}

	protected static function createLogDir(): bool
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

	/**
	 * @return void
	 */
	protected static function createLogFile()
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

	/**
	 * @return void
	 */
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

		$time   = date('Y-m-d H:i:s');
		$prefix = "[{$time}]";

		// Remove new line characters added by functions like var_dump().
		$message = rtrim($message, PHP_EOL);

		if (!empty($message)) {
			file_put_contents($file, static::prefixMessage($message, $prefix) . PHP_EOL, FILE_APPEND);
		} else {
			file_put_contents($file, PHP_EOL, FILE_APPEND);
		}
	}

	/**
	 * @return void
	 */
	public static function logAjax(string $message)
	{
		static::log(static::prefixMessage($message, '[AJAX]'));
	}

	/**
	 * @return void
	 */
	public static function logCron(string $message)
	{
		static::log(static::prefixMessage($message, '[Cron]'));
	}

	/**
	 * @return void
	 */
	public static function logAuto(string $message)
	{
		if (wp_doing_ajax()) { // Requires WordPress 4.7.
			static::logAjax($message);
		} else if (wp_doing_cron()) { // Requires WordPress 4.8.
			static::logCron($message);
		} else {
			static::log($message);
		}
	}

	protected static function prefixMessage(string $message, string $prefix): string
	{
		if (!empty($message)) {
			return $prefix . ' ' . $message;
		} else {
			// Don't add a prefix for empty lines.
			return $message;
		}
	}

	/**
	 * Places a separator between messages of different requests.
	 *
	 * @return void
	 */
	protected static function separateRequestsOnce() {
		if (!static::$isRequestsSeparated) {
			$separator = PHP_EOL . '------------------------------' . PHP_EOL . PHP_EOL;

			file_put_contents(static::getLogFile(), $separator, FILE_APPEND);

			static::$isRequestsSeparated = true;
		}
	}

	protected static function getIndexPhpContent(): string
	{
		return '<?php' . PHP_EOL
			. '// Silence is golden.' . PHP_EOL;
	}

	protected static function getHtaccessContent(): string
	{
		return 'Options -Indexes' . PHP_EOL
			. 'deny from all' . PHP_EOL
			. "<FilesMatch '\.(jpg|jpeg|png|gif|mp3|ogg)$'>" . PHP_EOL
				. "\tOrder Allow,Deny" . PHP_EOL
				. "\tAllow from all" . PHP_EOL
			. '</FilesMatch>' . PHP_EOL;
	}

	/**
	 * @param array $args Optional.
	 *     @param bool $args['strict'] Whether to wrap strings with "". True by
	 *         default.
	 *     @param bool $args['skip_first'] Don't apply "strict" to the first
	 *         parameter. True by default.
	 */
	public static function toString(array $vars, array $args = []): string
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
					$string = static::toStringVar($var, ['strict' => false]);
				} else {
					$string = static::toStringVar($var, $args);
				}
			} else {
				$string = static::toStringVar($var, $args);
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
	protected static function toStringVar($var, $args = []): string
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
			$string = number_format($var, 5, '.', '');

		} else if (is_string($var)) {
			$string = $var;

			if ($args['strict']) {
				$string = '"' . $string . '"';
			}

		} else if (is_array($var)) {
			$string = static::toStringArray($var);

		} else if ($var instanceof DateTime) {
			$string = $var->format('F j Y, H:i:s');
			$string = '{' . $string . '}';

		} else {
			ob_start();
			var_dump($var);
			$string = ob_get_clean();
			$string = rtrim($string, PHP_EOL);
		}

		return trim($string);
	}

	protected static function toStringArray(array $array): string
	{
		$keys  = [];
		$items = [];

		$i = 0;

		$isNaturalIndexes = true;

		foreach ($array as $key => $value) {
			$items[] = static::toStringVar($value);

			if ($key === $i) {
				$keys[] = $i;
			} else {
				$keys[] = static::toStringVar($key);
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
	 * @return void
	 */
	function todebug(...$vars)
	{
		$message = TodebugLiteLogger::toString(
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
	 * @return void
	 */
	function todebugs(...$vars)
	{
		$message = TodebugLiteLogger::toString(
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
	 * @return void
	 */
	function todebugm(...$vars)
	{
		$message = TodebugLiteLogger::toString(
			$vars,
			[
				'strict'     => false,
				'skip_first' => true,
			]
		);

		TodebugLiteLogger::logAuto($message);
	}
}
