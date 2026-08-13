<?php
/**
 * Constants PHPStan needs to know about.
 *
 * The plugin defines these in its bootstrap at runtime; PHPStan analyses files in
 * isolation and would otherwise report every use as an undefined constant.
 *
 * @package ScreenlyCast
 */

declare(strict_types=1);

define( 'SRLY_VERSION', '0.0.0-phpstan' );
define( 'SRLY_PLUGIN_FILE', __DIR__ . '/../screenly-cast/screenly-cast.php' );
define( 'SRLY_PLUGIN_DIR', __DIR__ . '/../screenly-cast/' );
define( 'SRLY_PLUGIN_URL', 'https://example.test/wp-content/plugins/screenly-cast/' );
define( 'SRLY_QUERY_VAR', 'srly' );
