<?php

/**
 * @package redaxo\media-manager
 */
class rex_managed_media
{
    private $media_path = '';
    private $media;
    private $asImage = false;
    private $image;
    private $header = [];
    private $sourcePath;
    private $format;

    private $mimetypeMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/vnd.wap.wbmp' => 'wbmp',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct($media_path)
    {
        $this->setMediaPath($media_path);
        $this->format = strtolower(rex_file::extension($this->getMediaPath()));
    }

    /**
     * Returns the original path of the media.
     *
     * To get the current source path (can be changed by effects) use `getSourcePath` instead.
     *
     * @return null|string
     */
    public function getMediaPath()
    {
        return $this->media_path;
    }

    /**
     * @return void
     */
    public function setMediaPath($media_path)
    {
        $this->media_path = $media_path;

        if (null === $media_path) {
            return;
        }

        $this->media = basename($media_path);
        $this->asImage = false;

        if (file_exists($media_path)) {
            $this->sourcePath = $media_path;
        } else {
            $this->sourcePath = rex_path::addon('media_manager', 'media/warning.jpg');
        }
    }

    public function getMediaFilename()
    {
        return $this->media;
    }

    /**
     * @return void
     */
    public function setMediaFilename($filename)
    {
        $this->media = $filename;
    }

    /**
     * @return void
     */
    public function setHeader($type, $content)
    {
        $this->header[$type] = $content;
    }

    public function getHeader()
    {
        return $this->header;
    }

    /**
     * @return void
     */
    public function asImage()
    {
        if ($this->asImage) {
            return;
        }

        $this->asImage = true;

        $this->image = [];
        $this->image['src'] = false;

        // if mimetype detected and in imagemap -> change format
        if (class_exists('finfo') && $finfo = new finfo(FILEINFO_MIME_TYPE)) {
            if ($ftype = @$finfo->file($this->getSourcePath())) {
                if (array_key_exists($ftype, $this->mimetypeMap)) {
                    $this->format = $this->mimetypeMap[$ftype];
                }
            }
        }

        if ('jpg' == $this->format || 'jpeg' == $this->format) {
            $this->format = 'jpeg';
            $this->image['src'] = @imagecreatefromjpeg($this->getSourcePath());
        } elseif ('gif' == $this->format) {
            $this->image['src'] = @imagecreatefromgif($this->getSourcePath());
        } elseif ('wbmp' == $this->format) {
            $this->image['src'] = @imagecreatefromwbmp($this->getSourcePath());
        } elseif ('webp' == $this->format) {
            if (function_exists('imagecreatefromwebp')) {
                $this->image['src'] = @imagecreatefromwebp($this->getSourcePath());
                imagealphablending($this->image['src'], false);
                imagesavealpha($this->image['src'], true);
            }
        } else {
            $this->image['src'] = @imagecreatefrompng($this->getSourcePath());
            if ($this->image['src']) {
                imagealphablending($this->image['src'], false);
                imagesavealpha($this->image['src'], true);
                $this->format = 'png';
            }
        }

        if (!$this->image['src']) {
            $this->setSourcePath(rex_path::addon('media_manager', 'media/warning.jpg'));
            $this->asImage();
        } else {
            $this->fixOrientation();
            $this->refreshImageDimensions();
        }
    }

    /**
     * @return void
     */
    public function refreshImageDimensions()
    {
        if ($this->asImage) {
            $this->image['width'] = imagesx($this->image['src']);
            $this->image['height'] = imagesy($this->image['src']);

            return;
        }

        if ('jpeg' !== $this->format && !in_array($this->format, $this->mimetypeMap)) {
            return;
        }

        $size = getimagesize($this->sourcePath);
        $this->image['width'] = $size[0];
        $this->image['height'] = $size[1];
    }

    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @return void
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * @return void
     */
    public function sendMedia($sourceCacheFilename, $headerCacheFilename, $save = false)
    {
        $this->prepareHeaders();

        if ($this->asImage) {
            $src = $this->getSource();
            $this->setHeader('Content-Length', rex_string::size($src));

            rex_response::cleanOutputBuffers();
            foreach ($this->header as $t => $c) {
                header($t . ': ' . $c);
            }

            echo $src;

            if ($save) {
                rex_file::putCache($headerCacheFilename, [
                    'media_path' => $this->getMediaPath(),
                    'format' => $this->format,
                    'headers' => $this->header,
                ]);

                rex_file::put($sourceCacheFilename, $src);
            }
        } else {
            $this->setHeader('Content-Length', filesize($this->getSourcePath()));

            rex_response::cleanOutputBuffers();
            foreach ($this->header as $t => $c) {
                rex_response::setHeader($t, $c);
            }

            rex_response::sendFile($this->getSourcePath(), $this->header['Content-Type']);

            if ($save) {
                rex_file::putCache($headerCacheFilename, [
                    'media_path' => $this->getMediaPath(),
                    'format' => $this->format,
                    'headers' => $this->header,
                ]);

                rex_file::copy($this->getSourcePath(), $sourceCacheFilename);
            }
        }
    }

    /**
     * @return void
     */
    public function save($sourceCacheFilename, $headerCacheFilename)
    {
        $src = $this->getSource();

        $this->prepareHeaders($src);
        $this->saveFiles($src, $sourceCacheFilename, $headerCacheFilename);
    }

    /**
     * @return false|string
     */
    protected function getImageSource()
    {
        $addon = rex_addon::get('media_manager');

        $format = $this->format;
        $format = 'jpeg' === $format ? 'jpg' : $format;

        $interlace = $this->getImageProperty('interlace', $addon->getConfig('interlace', ['jpg']));
        imageinterlace($this->image['src'], in_array($format, $interlace) ? 1 : 0);

        ob_start();
        if ('jpg' == $format) {
            $quality = $this->getImageProperty('jpg_quality', $addon->getConfig('jpg_quality', 85));
            imagejpeg($this->image['src'], null, $quality);
        } elseif ('png' == $format) {
            $compression = $this->getImageProperty('png_compression', $addon->getConfig('png_compression', 5));
            imagepng($this->image['src'], null, $compression);
        } elseif ('gif' == $format) {
            imagegif($this->image['src']);
        } elseif ('wbmp' == $format) {
            imagewbmp($this->image['src']);
        } elseif ('webp' == $format) {
            $quality = $this->getImageProperty('webp_quality', $addon->getConfig('webp_quality', 85));
            imagewebp($this->image['src'], null, $quality);
        }
        return ob_get_clean();
    }

    public function getImage()
    {
        return $this->image['src'];
    }

    /**
     * @return void
     */
    public function setImage($src)
    {
        $this->image['src'] = $src;
        $this->asImage = true;
    }

    /**
     * @return void
     */
    public function setSourcePath($path)
    {
        $this->sourcePath = $path;

        $this->asImage = false;

        if (isset($this->image['src']) && is_resource($this->image['src'])) {
            imagedestroy($this->image['src']);
        }
    }

    /**
     * Returns the current source path.
     *
     * To get the original media path use `getMediaPath()` instead.
     *
     * @return string
     */
    public function getSourcePath()
    {
        return $this->sourcePath;
    }

    /**
     * @return string
     */
    public function getSource()
    {
        if ($this->asImage) {
            return $this->getImageSource();
        }

        return rex_file::get($this->sourcePath);
    }

    /**
     * @return void
     */
    public function setImageProperty($name, $value)
    {
        $this->image[$name] = $value;
    }

    public function getImageProperty($name, $default = null)
    {
        return isset($this->image[$name]) ? $this->image[$name] : $default;
    }

    public function getWidth()
    {
        return $this->image['width'];
    }

    public function getHeight()
    {
        return $this->image['height'];
    }

    /**
     * @deprecated since 2.3.0, use `getWidth()` instead
     */
    public function getImageWidth()
    {
        return $this->getWidth();
    }

    /**
     * @deprecated since 2.3.0, use `getHeight()` instead
     */
    public function getImageHeight()
    {
        return $this->getHeight();
    }

    /**
     * @return void
     */
    private function fixOrientation()
    {
        if (!function_exists('exif_read_data')) {
            return;
        }
        // exif_read_data() only works on jpg/jpeg/tiff
        if (!in_array($this->getFormat(), ['jpg', 'jpeg', 'tiff'])) {
            return;
        }
        // suppress warning in case of corrupt/ missing exif data
        $exif = @exif_read_data($this->getSourcePath());

        if (!isset($exif['Orientation']) || !in_array($exif['Orientation'], [3, 6, 8])) {
            return;
        }

        switch ($exif['Orientation']) {
            case 8:
                $this->image['src'] = imagerotate($this->image['src'], 90, 0);
                break;
            case 3:
                $this->image['src'] = imagerotate($this->image['src'], 180, 0);
                break;
            case 6:
                $this->image['src'] = imagerotate($this->image['src'], -90, 0);
                break;
        }
    }

    /**
     * @param string $src Source content
     *
     * @return void
     */
    private function prepareHeaders($src = null)
    {
        if (null !== $src) {
            $this->setHeader('Content-Length', rex_string::size($src));
        }

        $header = $this->getHeader();
        if (!isset($header['Content-Type'])) {
            $content_type = '';

            if (!$content_type && function_exists('mime_content_type')) {
                $content_type = mime_content_type($this->getSourcePath());
            }

            if (!$content_type && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $content_type = finfo_file($finfo, $this->getSourcePath());
            }

            // In case mime_content_type() returns 'text/plain' for CSS / JS files:
            if ('text/plain' == $content_type) {
                if ('css' == pathinfo($this->getSourcePath(), PATHINFO_EXTENSION)) {
                    $content_type = 'text/css';
                } elseif ('js' == pathinfo($this->getSourcePath(), PATHINFO_EXTENSION)) {
                    $content_type = 'application/javascript';
                }
            }

            if ('' != $content_type) {
                $this->setHeader('Content-Type', $content_type);
            }
        }
        if (!isset($header['Content-Disposition'])) {
            $this->setHeader('Content-Disposition', 'inline; filename="' . $this->getMediaFilename() . '";');
        }
        if (!isset($header['Last-Modified'])) {
            $this->setHeader('Last-Modified', gmdate('D, d M Y H:i:s T'));
        }
    }

    /**
     * @param string $src                 Source content
     * @param string $sourceCacheFilename
     * @param string $headerCacheFilename
     *
     * @return void
     */
    private function saveFiles($src, $sourceCacheFilename, $headerCacheFilename)
    {
        rex_file::putCache($headerCacheFilename, [
            'media_path' => $this->getMediaPath(),
            'format' => $this->format,
            'headers' => $this->header,
        ]);

        rex_file::put($sourceCacheFilename, $src);
    }
}
