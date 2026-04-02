# Static Photo Gallery Generator

**Version 1.1**

This repo contains a simple PHP CLI tool that generates a static photo gallery from the `albums/` directory.

- Album overview page: `index.html`
- Per-album page: `<album>/index.html` — shows subdirectories before photos if both exist
- Subdirectory support: any depth, each subdir gets its own `index.html` and `view.html`
- Thumbnails: `albums/<album>/thumb/`

## Requirements

- PHP 8+
- Either:
  - Imagick PHP extension (preferred), or
  - GD PHP extension
- Optional for correct JPEG rotation when using GD: `exif` extension

## Usage

From the project directory:

```bash
php build.php --out=/path/to/your/webroot/gallery
```

By default it reads albums from `./albums`.

### Options

- `--albums=/path/to/albums`
- `--out=/path/to/webroot/gallery`
- `--thumb=360` (large thumbnail max size in pixels)
- `--thumbSmall=96` (small thumbnail max size in pixels, used in viewer filmstrip)
- `--page=72` (items loaded per batch while scrolling)
- `--force` (recreate all thumbnails)

## Nginx layout

This generator assumes your images are available at:

- `/albums/<album>/<image>`
- `/albums/<album>/thumb/lg/<thumb>`
- `/albums/<album>/thumb/sm/<thumb>`

So `albums/` should exist under the gallery webroot (as you indicated).

## Notes

- Only `.jpg`, `.jpeg`, and `.png` files are included.
- Other file types (e.g. `Thumbs.db`) are ignored.
- Subdirectories inside an album are ignored.

## Security

`build.php` is intended to be run from the command line only. It exits immediately when accessed via HTTP.

For defense in depth, also block it in nginx:

```nginx
location = /PATH-TO-GALLERY/build.php { return 404; }
```

## Viewer

Each album also gets a simple full-size viewer page:

- `/<album>/view.html?i=0`

It supports:

- Previous/next buttons
- Left/right arrow keys
- Download link
- EXIF toggle (EXIF is embedded at build time for JPEG files when the PHP `exif` extension is available)
- Clickable thumbnail filmstrip
