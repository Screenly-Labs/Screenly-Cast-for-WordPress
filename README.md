# Screenly Cast for WordPress

A WordPress plugin to enable easy and beautiful casting of pages, posts and image
media on [Screenly](https://www.screenly.io) digital signage devices.

The Screenly Cast plugin optimizes your website content for beautiful,
easy-to-read display on TVs and other non-interactive devices.

Without Screenly Cast for WordPress:
![Without Screenly Cast for WordPress](/assets/screenshot-1.png)

With Screenly Cast for WordPress:
![With Screenly Cast for WordPress](/assets/screenshot-2.png)

## Installing

* Search for *Screenly Cast* in the WordPress plugin directory (or you can find it
  [here](https://wordpress.org/plugins/screenly-cast/))
* Activate the plugin

## Usage with [Screenly](https://www.screenly.io)

To make use of the plugin on your **Screenly Screen** you just need to follow
these simple steps:

1. Make sure the **plugin is activated**.
1. **Copy and Paste** the URL of your website, post, page or attachment.
1. Change the URL, adding a parameter called `srly`, like this:
    * `https://www.mydomain.com/?srly`
    * `https://www.mydomain.com/my-post-url?srly`
    * `https://www.mydomain.com/my-page-url?srly`
    * `https://www.mydomain.com/my-attachment-url?srly`
    * `https://www.mydomain.com/?somevar=1&anothervar=2&srly` &mdash; in case you're
      using more than one parameter

    **NOTE:** There is no need to apply any value to the parameter. It just needs
    to exist in the query.

1. [Login to Screenly](https://login.screenlyapp.com) and navigate to **Content**
   on the sidebar.
1. Click on the button **+ New Content**.
1. Select the tab **URL**.
1. **Paste** the edited URL (from step 3).
1. Click **Finish**.
1. On the content detail page, make sure to set a **recognizable title** since this
   is what you will see in Screenly later.
1. Go to the **Playlists** section and go to the playlist where you want to add
    the new content.
1. Click the **+** button beside the **Content** section.
1. Select the content you just created and click **+** beside it. Click **Done**
    when you are ready.
1. Make sure to pick an appropriate **Duration** for a good reading experience.
1. Click **Save**.

Screenly Cast for WordPress works with both [Screenly](https://www.screenly.io)
and [Anthias](https://anthias.screenly.io/), and should also work just fine with
most other digital signage solutions, but the usage will vary.

## How it works

The plugin comes with a simple theme that will be used specifically for Screenly
content. The plugin detects the `srly` parameter in your URL, like in
`http://www.myblog.com/?srly`, and applies the Screenly Cast theme. Enabling the
theme will not affect your normal theme still in use for the rest of your site.

Because the plugin targets Screenly devices with no end user interaction, content
will be laid out in a simple and TV-friendly layout. Just the title, content and
featured image are used. The plugin automatically simplifies the markup and
removes functionality not appropriate for the medium. For example, since there is
no interaction, any clickable links is simplified to just the title text.

For the best experience for your reader you should assume that no more than 250
characters or so will be displayed. Screenly Cast does not try to automatically
scroll the content because for these non-interactive, often large TV displays,
fixed unmoving content usually looks and reads the best.

## Development

### Requirements

* Docker
* Docker Compose
* PHP 7.4 or higher (for local development without Docker)
* WordPress 6.2.4 or higher
* Composer for dependency management

> [!NOTE]
> While there's an option to run the development environment without containerization,
> it is highly recommended to use Docker for development to minimize the setup and
> configuration needed.

### Setup

#### Without Docker

```bash
composer install
npm install
```

#### With Docker

```bash
# Install Node.js dependencies
npm install

# Start development environment (WordPress + MySQL)
docker compose --profile dev up --build

# The development site will be available at http://localhost:8000
```

#### Running unit tests

```bash
# Start test environment
docker compose --profile test up wordpress-test
```

#### Running linting checks

```bash
# Run PHP linting checks
docker compose --profile lint up

# Run Node.js linting checks
docker compose --profile nodejs run --rm nodejs \
  bash -c "npm install && npm run lint"
```

### Release Process

* Update version and changelog:
  * Update the following information in the plugin header in [`screenly-cast.php`](/screenly-cast/screenly-cast.php):
    * `Version`
    * `Requires at least`
    * `Requires PHP`
  * Run the following commands to sync [`package.json`](/package.json) version with the plugin:

    ```bash
    docker compose --profile nodejs run --rm nodejs \
      bash -c "npm run version:sync && npm install"
    ```

  * Add detailed changelog entry in `readme.txt` under the `== Changelog ==`
    section
    * Follow the existing format (e.g., `= 1.0.0 =`)
    * List all changes with proper categorization (Major/Feature/Fix)
    * Include any breaking changes, new features, and bug fixes

* Test the changes locally:

```bash
# Run the test suite
docker compose run --rm wordpress-test composer test
```

* Build and verify the release locally:

```bash
# Clean install dependencies without dev packages
composer install --no-dev --optimize-autoloader

# Build the release
./bin/build.sh

# Verify the build output in build/screenly-cast/
```

* Commit changes and push to GitHub:

```bash
git add .
git commit -m "Prepare release vX.Y.Z"
git push origin master
```

* Create and push a new tag:

```bash
# Create a new tag
git tag -a vX.Y.Z -m "Version X.Y.Z"

# Push the tag
git push origin vX.Y.Z

# If you need to delete a tag locally and remotely (e.g., if made a mistake):
git tag -d vX.Y.Z               # Delete local tag
git push --delete origin vX.Y.Z # Delete remote tag
```

* The GitHub Actions workflow will automatically:
  * Run tests across supported PHP versions
  * Build the release package without dev dependencies
  * Deploy to WordPress.org plugin repository if the build is successful
  * The plugin will be available in the WordPress.org plugin directory after
     deployment.

### Version Numbers

We use [Semantic Versioning](https://semver.org/):

* MAJOR version for incompatible API changes
* MINOR version for backwards-compatible functionality additions
* PATCH version for backwards-compatible bug fixes

### Creating a WordPress Plugin ZIP

Running the build script will:

* Create a clean build in the `build/` directory for WordPress.org deployment
* Generate an installable ZIP file in the `dist/` directory

```bash
./bin/build.sh
```

The resulting `dist/screenly-cast.zip` file can be uploaded via WordPress's "Add New Plugin" interface.

## License

This project is licensed under the [GPLv2](/LICENSE).
