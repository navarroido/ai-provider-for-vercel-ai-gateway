<?php
/**
 * PSR-4 autoloader for the AI Provider for Vercel AI Gateway plugin.
 *
 * Used when the plugin is installed as a WordPress plugin without running
 * `composer install`. Composer's generated autoloader takes precedence when
 * present.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	$prefix  = 'WordPress\\VercelAiGatewayProvider\\';
	$pluginDir = dirname(__DIR__);

	$len = strlen($prefix);
	if (strncmp($class, $prefix, $len) !== 0) {
		return;
	}

	$relativeClass = substr($class, $len);
	$relativePath  = str_replace('\\', '/', $relativeClass) . '.php';

	// Map sub-namespaces to the on-disk layout the plugin uses.
	// "Admin\\..." lives under /admin, everything else under /includes.
	if (strncmp($relativeClass, 'Admin\\', 6) === 0) {
		$file = $pluginDir . '/admin/' . substr($relativePath, 6);
	} else {
		$file = $pluginDir . '/includes/' . $relativePath;
	}

	if (file_exists($file)) {
		require $file;
	}
});
