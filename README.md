# fotoshare.co-album-downloader (vibecode fixed fork)

Download complete albums from fotoshare.co (dslrBooth).

This is a fork of [CodeBrauer/fotoshare.co-album-downloader](https://github.com/CodeBrauer/fotoshare.co-album-downloader) with an AI-assisted ("vibecoded") fix that makes it work again with the current fotoshare.co site and modern PHP.

> **Archived:** this repository is no longer maintained. fotoshare.co now offers a built-in download for all images, which is the better option if it is available for your album.

## What was changed

The original script stopped working because the site changed. Fixes in `index.php`:

| Problem | Fix |
| --- | --- |
| The scraped `data-img` values are now relative page paths (`/i/48dztej`), not file URLs, so `file_get_contents` failed. | Paths are no longer used for downloading. |
| `/i/<id>` is an **HTML page about the photo**, not the image. Downloading it saved a ~15 KB HTML file named `.jpg`, and the script still printed "Downloaded". | The script now downloads the image directly from the CDN link found in the page data (third column of `images.csv`). |
| That CDN link ends in `?width=450&aspect_ratio=1:1` (a small square thumbnail). | The query string is stripped to get the full-size original. |
| No error checking. Failed downloads left empty files that were skipped on the next run. | HTTP status is checked, and any response that looks like an HTML page is rejected and reported as `FAILED`. |
| Requests could be throttled. | Added a browser-like User-Agent, retries with exponential backoff (honoring `Retry-After`), and a short pause between downloads. |
| Videos | For `mp4` entries the script reads the video URL from the page's `og:video` tag, falling back to the CDN thumbnail/GIF. **This path is untested.** |
| PHP 8.5 deprecates `$http_response_header` | Uses `http_get_last_response_headers()` when available. |

Other changes:

- Files are now named after the CDN file, for example `20260930_224944_091.jpg`, instead of the short page id.
- `composer.json` / install steps: the original dependencies (Goutte 3.x, Symfony 4.1, Guzzle 6) require `php ^7.1.3` and have known security advisories, so Composer refuses to install them on modern PHP. See the install steps below for the workaround.

## Requirements

- PHP CLI (tested with PHP 8.5.4 on Ubuntu) with the `curl`, `mbstring`, `xml` and `zip` extensions
- Composer

## Installation

### 1. Install PHP and extensions (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install php8.5-cli php8.5-curl php8.5-mbstring php8.5-xml php8.5-zip unzip curl
```

Package names are version-specific on newer Ubuntu releases (the `php-curl` style metapackages may not exist). Adjust `8.5` to your PHP version. If a package can't be found, enable the `universe` repository: `sudo add-apt-repository universe && sudo apt update`.

### 2. Install Composer

`apt install composer` may not be available. Use the official installer:

```bash
cd ~
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
composer --version
```

### 3. Clone this repo and install dependencies

```bash
git clone https://github.com/ViktorGrozev/fotoshare.co-album-downloader-ai-fix.git
cd fotoshare.co-album-downloader-ai-fix
composer config audit.block-insecure false
composer install --ignore-platform-reqs
```

Why the two extra flags:

- `--ignore-platform-reqs` is needed because the locked dependencies declare `php ^7.1.3`. They still run fine on PHP 8.x for this script.
- `audit.block-insecure false` stops Composer from refusing old packages that have published security advisories. This is acceptable for a local scraper that only talks to fotoshare.co, but be aware of it. Note that this command edits `composer.json`.

If `composer install` complains that the lock file is out of date, use `composer update --ignore-platform-reqs` instead.

## Usage

The album must be **public**. You can set it to public temporarily and switch it back to private afterwards.

**Command line (recommended, especially for large albums):**

```bash
php index.php <album url>
```

Example:

```bash
php index.php https://fotoshare.co/e/hNLYZSe7_LG2Ibs0b9pFw
```

Files already downloaded are skipped, so you can safely re-run the command to resume or retry failures. Lines marked `FAILED:` can be retried by running the command again.

**Web UI (small albums):** start a local server and open it in your browser. This path has not been re-tested after the fix.

```bash
php -S localhost:3000
```

### Output

A folder named after the album id is created, containing:

- The album media files (full-size `jpg`, plus `gif`/`mp4` where applicable)
- `images.csv` describing the album:

| Column | Content |
| --- | --- |
| Image URL | Relative page path on fotoshare.co, for example `/i/48dztej` |
| GIF/Thumbnail | Relative page path (same as above for photos) |
| Fotoshare.co Path | CDN URL, with a `?width=450&aspect_ratio=1:1` thumbnail query for photos |
| Width, Height | Dimensions in pixels |
| Type | `jpg`, `mp4`, etc. |

Example row:

```csv
/i/48dztej,/i/48dztej,https://cdn-bz-op.fotoshare.co/b/56f9a/-P2CdvYx5pKHzo_--pdK/20260930_224944_091.jpg?width=450&aspect_ratio=1:1,3600,2400,jpg
```

## Troubleshooting

- **Downloaded images are ~15 KB and won't open:** you are running the old script and saved HTML pages. Delete them (`rm -f *.jpg` inside the album folder) and re-run with the fixed `index.php`.
- **`FAILED:` lines:** re-run the command. If a whole batch fails with HTTP 403, the CDN may require extra headers (for example a `Referer`). Open an issue or add the header in the stream context in `index.php`.
- **`vendor/autoload.php` not found:** `composer install` has not completed. See step 3.

## Credits

Original project by [CodeBrauer](https://github.com/CodeBrauer/fotoshare.co-album-downloader). Fix for the current fotoshare.co site by Claude, written with assistance by [ViktorGrozev](https://github.com/ViktorGrozev).
