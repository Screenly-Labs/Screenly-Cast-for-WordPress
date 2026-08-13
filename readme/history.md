# Changelog history

Releases that predate CalVer, and with it this pipeline. Frozen text.

The cut is the versioning scheme: the generator reads GitHub releases whose tag
is CalVer (`YYYY.M.MICRO`) and takes everything older from this file. That is
not an arbitrary line — of the seven pre-CalVer tags, only `v0.1.18`, `v1.0.0`
and `v1.0.1` were ever published as releases, so for `v1.0.2` through `v1.0.5`
there is no release body to read. Keeping the whole legacy range static is the
only way that history survives, and it cannot go stale because those releases
will never be edited.

Nothing below should be changed. New entries are written as GitHub releases.

## 1.0.5

* Fix: Make failing unit tests pass
* Fix: Update handling of the `?srly` query parameter
* Fix: Screenly Cast theme not being applied when using the `srly` query
  parameter

## 1.0.4

* Fix: Move build artifacts to separate dist/ directory
* Fix: Improve build script to create correct WordPress plugin structure
* Fix: Update documentation for build process and plugin installation

## 1.0.3

* Fix: Correct directory structure for WordPress.org SVN deployment
* Fix: Ensure proper handling of plugin assets
* Fix: Streamline build process for cleaner releases

## 1.0.2

* Fix: Remove all development files and directories from release package
* Fix: Improve .distignore patterns to match actual directory structure
* Fix: Clean up root directory files from WordPress.org deployment

## 1.0.1

* Fix: Remove development files from release package
* Fix: Clean up files included in WordPress.org deployment

## 1.0.0

* Major: Complete rewrite of the plugin
* Modern PHP 7.4+ features and type safety
* Improved code organization and maintainability
* Updated minimum WordPress version to 6.2.4
* Better error handling and version compatibility checks
* Added comprehensive test suite with unit and integration tests
* Added theme installation and management functionality
* Added proper WordPress coding standards compliance
* Added proper query handling for Screenly Cast content

## 0.1.19

* Added PHPUnit test files for WordPress
