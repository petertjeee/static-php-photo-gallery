<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo "Not Found\n";
    exit(0);
}

final class GalleryBuilder
{
    private string $rootDir;
    private string $albumsDir;
    private string $outDir;
    private int $thumbLargeSize;
    private int $thumbSmallSize;
    private int $pageSize;
    private bool $force;
    private int $thumbsCreated = 0;
    private int $thumbsSkipped = 0;

    public function __construct(string $rootDir, string $albumsDir, string $outDir, int $thumbLargeSize, int $thumbSmallSize, int $pageSize, bool $force)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->albumsDir = rtrim($albumsDir, DIRECTORY_SEPARATOR);
        $this->outDir = rtrim($outDir, DIRECTORY_SEPARATOR);
        $this->thumbLargeSize = $thumbLargeSize;
        $this->thumbSmallSize = $thumbSmallSize;
        $this->pageSize = $pageSize;
        $this->force = $force;
    }

    public function run(): void
    {
        if (!is_dir($this->albumsDir)) {
            $this->fail("Albums directory not found: {$this->albumsDir}");
        }

        if (!is_dir($this->outDir) && !mkdir($this->outDir, 0775, true)) {
            $this->fail("Failed to create output directory: {$this->outDir}");
        }

        $this->log('Gallery build starting');
        $this->log('Albums: ' . $this->albumsDir);
        $this->log('Output: ' . $this->outDir);
        $this->log('Thumb large: ' . $this->thumbLargeSize . 'px');
        $this->log('Thumb small: ' . $this->thumbSmallSize . 'px');
        if ($this->force) {
            $this->log('Force mode: enabled');
        }

        $albums = $this->scanAlbums($this->albumsDir);
        $this->log('Found albums: ' . count($albums));

        foreach ($albums as &$album) {
            $this->log('Album: ' . $album['id']);
            $album['images'] = $this->scanImages($album['path']);
            $this->log('  Images: ' . count($album['images']));
            $album['thumb_dir'] = $album['path'] . DIRECTORY_SEPARATOR . 'thumb';
            $album['thumb_large_dir'] = $album['thumb_dir'] . DIRECTORY_SEPARATOR . 'lg';
            $album['thumb_small_dir'] = $album['thumb_dir'] . DIRECTORY_SEPARATOR . 'sm';
            $this->ensureDir($album['thumb_large_dir']);
            $this->ensureDir($album['thumb_small_dir']);

            foreach ($album['images'] as &$img) {
                $img['thumb_large_rel'] = 'albums/' . rawurlencode($album['id']) . '/thumb/lg/' . rawurlencode($img['thumb_name']);
                $img['thumb_small_rel'] = 'albums/' . rawurlencode($album['id']) . '/thumb/sm/' . rawurlencode($img['thumb_name']);
                $this->ensureThumbnail($img['abs'], $album['thumb_large_dir'] . DIRECTORY_SEPARATOR . $img['thumb_name'], $this->thumbLargeSize);
                $this->ensureThumbnail($img['abs'], $album['thumb_small_dir'] . DIRECTORY_SEPARATOR . $img['thumb_name'], $this->thumbSmallSize);
                $img['exif'] = $this->extractExif($img['abs']);
            }
            unset($img);
        }
        unset($album);

        $this->writeIndex($albums);
        foreach ($albums as $album) {
            $this->writeAlbumIndexPage($album);
            $this->writeAlbumViewerPage($album);
        }

        $this->log('Thumbnails created: ' . $this->thumbsCreated);
        $this->log('Thumbnails skipped: ' . $this->thumbsSkipped);
        $this->log('Gallery build complete');
    }

    private function scanAlbums(string $albumsDir): array
    {
        $items = scandir($albumsDir);
        if ($items === false) {
            $this->fail("Failed to read albums directory: {$albumsDir}");
        }

        $albums = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $albumsDir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path)) {
                continue;
            }

            if ($name === 'thumb') {
                continue;
            }

            $albums[] = [
                'id' => $name,
                'title' => $this->humanize($name),
                'path' => $path,
            ];
        }

        usort($albums, static fn(array $a, array $b) => strcmp($a['id'], $b['id']));
        return $albums;
    }

    private function scanImages(string $albumPath): array
    {
        $items = scandir($albumPath);
        if ($items === false) {
            $this->fail("Failed to read album directory: {$albumPath}");
        }

        $images = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $abs = $albumPath . DIRECTORY_SEPARATOR . $name;
            if (!is_file($abs)) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $base = pathinfo($name, PATHINFO_FILENAME);
            $thumbName = $base . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

            $images[] = [
                'name' => $name,
                'abs' => $abs,
                'rel' => $this->relativeToRoot($abs),
                'thumb_name' => $thumbName,
            ];
        }

        usort($images, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));
        return $images;
    }

    private function ensureThumbnail(string $src, string $dst, int $targetSize): void
    {
        if ($this->force) {
            if (class_exists('Imagick')) {
                $this->makeThumbImagick($src, $dst, $targetSize);
                $this->thumbsCreated++;
                return;
            }

            $this->makeThumbGd($src, $dst, $targetSize);
            $this->thumbsCreated++;
            return;
        }

        $srcMtime = @filemtime($src);
        $dstMtime = @filemtime($dst);

        $needs = false;
        if ($srcMtime === false) {
            $needs = false;
        } elseif ($dstMtime === false || $dstMtime < $srcMtime) {
            $needs = true;
        } else {
            $needs = !$this->thumbMatchesSize($dst, $targetSize);
        }

        if (!$needs) {
            $this->thumbsSkipped++;
            return;
        }

        if (class_exists('Imagick')) {
            $this->makeThumbImagick($src, $dst, $targetSize);
            $this->thumbsCreated++;
            return;
        }

        $this->makeThumbGd($src, $dst, $targetSize);
        $this->thumbsCreated++;
    }

    private function makeThumbImagick(string $src, string $dst, int $targetSize): void
    {
        $img = new Imagick();
        $img->readImage($src);

        if (method_exists($img, 'autoOrient')) {
            $img->autoOrient();
        } else {
            try {
                $orientation = $img->getImageOrientation();
                switch ($orientation) {
                    case Imagick::ORIENTATION_BOTTOMRIGHT:
                        $img->rotateImage('#000', 180);
                        break;
                    case Imagick::ORIENTATION_RIGHTTOP:
                        $img->rotateImage('#000', 90);
                        break;
                    case Imagick::ORIENTATION_LEFTBOTTOM:
                        $img->rotateImage('#000', -90);
                        break;
                }
                $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            } catch (Throwable $e) {
            }
        }

        $img->cropThumbnailImage($targetSize, $targetSize);

        $ext = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $img->setImageFormat('png');
        } else {
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(82);
        }

        $img->writeImage($dst);
        $img->clear();
        $img->destroy();
    }

    private function makeThumbGd(string $src, string $dst, int $targetSize): void
    {
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $srcImg = null;

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $srcImg = @imagecreatefromjpeg($src);
        } elseif ($ext === 'png') {
            $srcImg = @imagecreatefrompng($src);
        }

        if (!$srcImg) {
            return;
        }

        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);

        $orientation = 1;
        if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            if (is_array($exif) && isset($exif['Orientation'])) {
                $orientation = (int)$exif['Orientation'];
            }
        }

        if (function_exists('imagerotate')) {
            if ($orientation === 3) {
                $srcImg = imagerotate($srcImg, 180, 0);
            } elseif ($orientation === 6) {
                $srcImg = imagerotate($srcImg, -90, 0);
            } elseif ($orientation === 8) {
                $srcImg = imagerotate($srcImg, 90, 0);
            }

            $srcW = imagesx($srcImg);
            $srcH = imagesy($srcImg);
        }

        $scale = max($targetSize / max(1, $srcW), $targetSize / max(1, $srcH));
        $scaledW = max(1, (int)ceil($srcW * $scale));
        $scaledH = max(1, (int)ceil($srcH * $scale));

        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagecopyresampled($scaled, $srcImg, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

        $dstImg = imagecreatetruecolor($targetSize, $targetSize);
        $srcX = (int)max(0, (int)floor(($scaledW - $targetSize) / 2));
        $srcY = (int)max(0, (int)floor(($scaledH - $targetSize) / 2));
        imagecopy($dstImg, $scaled, 0, 0, $srcX, $srcY, $targetSize, $targetSize);

        $dstExt = strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        if ($dstExt === 'png') {
            imagepng($dstImg, $dst, 6);
        } else {
            imagejpeg($dstImg, $dst, 82);
        }

        imagedestroy($srcImg);
        imagedestroy($scaled);
        imagedestroy($dstImg);
    }

    private function thumbMatchesSize(string $thumbPath, int $targetSize): bool
    {
        $info = @getimagesize($thumbPath);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return false;
        }

        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        return $w === $targetSize && $h === $targetSize;
    }

    private function extractExif(string $src): array
    {
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            return [];
        }

        if (!function_exists('exif_read_data')) {
            return [];
        }

        $raw = @exif_read_data($src, null, true);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        $picked = [
            'IFD0' => ['Make', 'Model', 'Orientation', 'DateTime'],
            'EXIF' => ['DateTimeOriginal', 'ExposureTime', 'FNumber', 'ISOSpeedRatings', 'FocalLength', 'Flash', 'LensModel'],
            'COMPUTED' => ['ApertureFNumber'],
        ];

        foreach ($picked as $section => $keys) {
            if (!isset($raw[$section]) || !is_array($raw[$section])) {
                continue;
            }
            foreach ($keys as $k) {
                if (!array_key_exists($k, $raw[$section])) {
                    continue;
                }
                $val = $raw[$section][$k];
                if (is_array($val)) {
                    $val = implode(', ', array_map(static fn($v) => (string)$v, $val));
                }
                $out[$k] = (string)$val;
            }
        }

        return $out;
    }

    private function writeIndex(array $albums): void
    {
        $cards = '';
        foreach ($albums as $album) {
            $count = count($album['images']);
            $cover = $count > 0 ? $album['images'][0]['thumb_large_rel'] : '';

            $coverHtml = $cover !== ''
                ? '<img class="cover" loading="lazy" src="' . $this->escapeAttr($cover) . '" alt="" />'
                : '<div class="cover cover-empty"></div>';

            $cards .= '<a class="card" href="' . $this->escapeAttr(rawurlencode($album['id'])) . '/">'
                . $coverHtml
                . '<div class="meta">'
                . '<div class="title">' . $this->escapeHtml($album['title']) . '</div>'
                . '<div class="count">' . $count . ' photos</div>'
                . '</div>'
                . '</a>';
        }

        $html = $this->wrapPage('Gallery',
            '<div class="topbar"><div class="brand">Gallery</div></div>'
            . '<div class="container">'
            . '<div class="grid albums">' . $cards . '</div>'
            . '</div>'
        );

        $this->writeFile($this->outDir . DIRECTORY_SEPARATOR . 'index.html', $html);
    }

    private function writeAlbumIndexPage(array $album): void
    {
        $albumOut = $this->outDir . DIRECTORY_SEPARATOR . $album['id'];
        $this->ensureDir($albumOut);

        $items = [];
        foreach ($album['images'] as $idx => $img) {
            $items[] = [
                'idx' => $idx,
                'src' => '../albums/' . rawurlencode($album['id']) . '/' . rawurlencode($img['name']),
                'thumb' => '../' . $img['thumb_large_rel'],
                'thumbSm' => '../' . $img['thumb_small_rel'],
                'name' => $img['name'],
                'exif' => $img['exif'] ?? [],
            ];
        }

        $json = json_encode(
            [
                'id' => $album['id'],
                'title' => $album['title'],
                'items' => $items,
                'pageSize' => $this->pageSize,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $this->fail('Failed to encode album JSON for ' . $album['id']);
        }

        $content = ''
            . '<div class="topbar">'
            . '<a class="back" href="../">← Albums</a>'
            . '<div class="brand">' . $this->escapeHtml($album['title']) . '</div>'
            . '</div>'
            . '<div class="container">'
            . '<div id="grid" class="grid photos"></div>'
            . '<div id="loader" class="loader">Loading…</div>'
            . '</div>'
            . '<script>'
            . 'window.__GALLERY__=' . $json . ';'
            . '(function(){'
            . 'const data=window.__GALLERY__;'
            . 'const grid=document.getElementById("grid");'
            . 'const loader=document.getElementById("loader");'
            . 'let idx=0;'
            . 'function renderNext(){'
            . 'const end=Math.min(data.items.length, idx + (data.pageSize||60));'
            . 'for(;idx<end;idx++){' 
            . 'const it=data.items[idx];'
            . 'const a=document.createElement("a");a.className="photo";a.href="view.html?i="+it.idx;'
            . 'const img=document.createElement("img");img.loading="lazy";img.src=it.thumb;img.alt="";'
            . 'a.appendChild(img);'
            . 'grid.appendChild(a);'
            . '}'
            . 'if(idx>=data.items.length){loader.textContent="";obs.disconnect();}'
            . '}'
            . 'const obs=new IntersectionObserver((entries)=>{entries.forEach(e=>{if(e.isIntersecting) renderNext();});},{rootMargin:"800px"});'
            . 'obs.observe(loader);'
            . 'renderNext();'
            . '})();'
            . '</script>';

        $html = $this->wrapPage($album['title'], $content);

        $this->writeFile($albumOut . DIRECTORY_SEPARATOR . 'index.html', $html);
    }

    private function writeAlbumViewerPage(array $album): void
    {
        $albumOut = $this->outDir . DIRECTORY_SEPARATOR . $album['id'];
        $this->ensureDir($albumOut);

        $items = [];
        foreach ($album['images'] as $idx => $img) {
            $items[] = [
                'idx' => $idx,
                'src' => '../albums/' . rawurlencode($album['id']) . '/' . rawurlencode($img['name']),
                'thumbSm' => '../' . ($img['thumb_small_rel'] ?? ''),
                'name' => $img['name'],
                'exif' => $img['exif'] ?? [],
            ];
        }

        $json = json_encode(
            [
                'id' => $album['id'],
                'title' => $album['title'],
                'items' => $items,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $this->fail('Failed to encode viewer JSON for ' . $album['id']);
        }

        $content = ''
            . '<div class="topbar viewerbar">'
            . '<a class="back" href="./">← Thumbnails</a>'
            . '<div class="brand">' . $this->escapeHtml($album['title']) . '</div>'
            . '<div class="viewer-actions">'
            . '<a id="dl" class="btn" href="#" download>Download</a>'
            . '<button id="exifBtn" class="btn" type="button">EXIF</button>'
            . '</div>'
            . '</div>'
            . '<div class="viewer">'
            . '<button id="prev" class="nav prev" type="button" aria-label="Previous">‹</button>'
            . '<img id="main" class="main" alt="" />'
            . '<button id="next" class="nav next" type="button" aria-label="Next">›</button>'
            . '</div>'
            . '<div id="film" class="film"></div>'
            . '<div id="exif" class="exif" hidden><pre id="exifPre"></pre></div>'
            . '<script>'
            . 'window.__VIEWER__=' . $json . ';'
            . '(function(){'
            . 'const data=window.__VIEWER__;'
            . 'const main=document.getElementById("main");'
            . 'const prev=document.getElementById("prev");'
            . 'const next=document.getElementById("next");'
            . 'const film=document.getElementById("film");'
            . 'const dl=document.getElementById("dl");'
            . 'const exifBtn=document.getElementById("exifBtn");'
            . 'const exif=document.getElementById("exif");'
            . 'const exifPre=document.getElementById("exifPre");'
            . 'const qs=new URLSearchParams(location.search);'
            . 'let idx=parseInt(qs.get("i")||"0",10);'
            . 'if(Number.isNaN(idx)||idx<0) idx=0;'
            . 'if(idx>=data.items.length) idx=Math.max(0,data.items.length-1);'
            . 'function setIdx(i, push){'
            . 'idx=Math.max(0,Math.min(data.items.length-1,i));'
            . 'const it=data.items[idx];'
            . 'main.src=it.src;'
            . 'dl.href=it.src;'
            . 'document.title=data.title+" — "+it.name;'
            . 'Array.from(film.children).forEach((el)=>{el.classList.toggle("active", parseInt(el.dataset.idx,10)===idx);});'
            . 'const active=film.querySelector(".thumb.active");'
            . 'if(active){active.scrollIntoView({block:"nearest",inline:"center"});}'
            . 'const ex=it.exif||{};'
            . 'const lines=Object.keys(ex).map(k=>k+": "+ex[k]);'
            . 'exifPre.textContent=lines.length?lines.join("\n"):"No EXIF data";'
            . 'if(push){const u=new URL(location.href);u.searchParams.set("i",String(idx));history.replaceState(null,"",u.toString());}'
            . '}'
            . 'function buildFilm(){'
            . 'data.items.forEach((it)=>{'
            . 'const b=document.createElement("button");'
            . 'b.type="button";b.className="thumb";b.dataset.idx=String(it.idx);'
            . 'const im=document.createElement("img");im.loading="lazy";im.src=it.thumbSm;im.alt="";'
            . 'b.appendChild(im);'
            . 'b.addEventListener("click",()=>setIdx(it.idx,true));'
            . 'film.appendChild(b);'
            . '});'
            . '}'
            . 'prev.addEventListener("click",()=>setIdx(idx-1,true));'
            . 'next.addEventListener("click",()=>setIdx(idx+1,true));'
            . 'document.addEventListener("keydown",(e)=>{'
            . 'if(e.key==="ArrowLeft") setIdx(idx-1,true);'
            . 'if(e.key==="ArrowRight") setIdx(idx+1,true);'
            . 'if(e.key==="Escape" && !exif.hidden){exif.hidden=true;}'
            . '});'
            . 'exifBtn.addEventListener("click",()=>{exif.hidden=!exif.hidden;});'
            . 'buildFilm();'
            . 'setIdx(idx,false);'
            . '})();'
            . '</script>';

        $html = $this->wrapPage($album['title'], $content);
        $this->writeFile($albumOut . DIRECTORY_SEPARATOR . 'view.html', $html);
    }

    private function wrapPage(string $title, string $bodyHtml): string
    {
        $css = $this->baseCss();

        return "<!doctype html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "  <meta charset=\"utf-8\" />\n"
            . "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n"
            . "  <title>" . $this->escapeHtml($title) . "</title>\n"
            . "  <style>\n" . $css . "\n  </style>\n"
            . "</head>\n"
            . "<body>\n"
            . $bodyHtml
            . "\n</body>\n"
            . "</html>\n";
    }

    private function baseCss(): string
    {
        return <<<CSS
:root{color-scheme:light dark;--bg:#0b0c10;--panel:#111318;--text:#e9eef5;--muted:#9aa5b1;--border:rgba(255,255,255,.10);--card:#0f1116;--shadow:0 10px 25px rgba(0,0,0,.35)}
@media (prefers-color-scheme: light){:root{--bg:#f6f7fb;--panel:#ffffff;--text:#111827;--muted:#6b7280;--border:rgba(17,24,39,.10);--card:#ffffff;--shadow:0 10px 25px rgba(17,24,39,.12)}}
*{box-sizing:border-box}html,body{height:100%}body{margin:0;font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:var(--bg);color:var(--text)}
a{color:inherit;text-decoration:none}
.topbar{position:sticky;top:0;z-index:10;display:flex;gap:12px;align-items:center;padding:14px 16px;background:color-mix(in srgb,var(--panel) 92%, transparent);backdrop-filter:saturate(140%) blur(10px);border-bottom:1px solid var(--border)}
.brand{font-weight:700;letter-spacing:.2px}
.back{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:transparent}
.container{max-width:1200px;margin:0 auto;padding:16px}
.grid{display:grid;gap:12px}
.grid.albums{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
.grid.photos{grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
.card{display:block;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow)}
.cover{width:100%;height:180px;object-fit:cover;display:block;background:rgba(255,255,255,.04)}
.cover-empty{display:block}
.meta{padding:12px 12px 14px}
.title{font-weight:650}
.count{margin-top:4px;color:var(--muted);font-size:12px}
.photo{display:block;border-radius:14px;overflow:hidden;border:1px solid var(--border);background:rgba(255,255,255,.03)}
.photo img{width:100%;height:160px;object-fit:cover;display:block}
.loader{padding:22px 0;text-align:center;color:var(--muted)}
.viewerbar{justify-content:space-between}
.viewer-actions{display:flex;gap:10px;align-items:center}
.btn{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:transparent;color:inherit;cursor:pointer;font:inherit}
.viewer{position:relative;display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 160px);padding:16px}
.main{max-width:min(1400px,100%);max-height:calc(100vh - 220px);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.45);background:rgba(255,255,255,.03)}
.nav{position:absolute;top:50%;transform:translateY(-50%);width:52px;height:52px;border-radius:14px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.35);color:#fff;font-size:30px;line-height:48px;cursor:pointer}
.nav.prev{left:14px}
.nav.next{right:14px}
.film{display:flex;gap:8px;overflow:auto;padding:10px 12px;border-top:1px solid var(--border);background:color-mix(in srgb,var(--panel) 92%, transparent)}
.thumb{flex:0 0 auto;border:1px solid var(--border);background:transparent;border-radius:10px;padding:0;overflow:hidden;cursor:pointer}
.thumb img{display:block;width:74px;height:52px;object-fit:cover;background:rgba(255,255,255,.04)}
.thumb.active{outline:2px solid color-mix(in srgb,var(--text) 70%, transparent)}
.exif{position:fixed;right:14px;bottom:84px;max-width:min(520px,calc(100% - 28px));max-height:min(60vh,520px);overflow:auto;border:1px solid var(--border);border-radius:14px;background:color-mix(in srgb,var(--panel) 96%, transparent);box-shadow:var(--shadow);padding:12px}
.exif pre{margin:0;white-space:pre-wrap;font:12px/1.35 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:var(--text)}
CSS;
    }

    private function log(string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite(STDERR, '[' . $ts . '] ' . $message . "\n");
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);

        if (file_put_contents($path, $contents) === false) {
            $this->fail("Failed to write file: {$path}");
        }
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail("Failed to create directory: {$dir}");
        }
    }

    private function relativeToRoot(string $abs): string
    {
        $root = $this->rootDir . DIRECTORY_SEPARATOR;
        if (str_starts_with($abs, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($root)));
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', $abs);
    }

    private function humanize(string $s): string
    {
        $s = str_replace(['_', '-'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function escapeHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function fail(string $message): void
    {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function parseArgs(array $argv): array
{
    $args = [
        'albums' => null,
        'out' => null,
        'thumb' => 360,
        'thumbSmall' => 96,
        'page' => 72,
        'force' => false,
    ];

    foreach ($argv as $i => $v) {
        if ($i === 0) {
            continue;
        }

        if (!str_starts_with($v, '--')) {
            continue;
        }

        [$k, $val] = array_pad(explode('=', substr($v, 2), 2), 2, null);

        if ($k === 'albums') {
            $args['albums'] = $val;
        } elseif ($k === 'out') {
            $args['out'] = $val;
        } elseif ($k === 'thumb') {
            $args['thumb'] = (int)($val ?? 360);
        } elseif ($k === 'thumbSmall') {
            $args['thumbSmall'] = (int)($val ?? 96);
        } elseif ($k === 'page') {
            $args['page'] = (int)($val ?? 72);
        } elseif ($k === 'force') {
            $args['force'] = true;
        }
    }

    return $args;
}

$args = parseArgs($argv);
$root = __DIR__;
$albumsDir = $args['albums'] ?? ($root . DIRECTORY_SEPARATOR . 'albums');
$outDir = $args['out'] ?? ($root . DIRECTORY_SEPARATOR . 'site');

$builder = new GalleryBuilder($root, $albumsDir, $outDir, $args['thumb'], $args['thumbSmall'], $args['page'], (bool)$args['force']);
$builder->run();
