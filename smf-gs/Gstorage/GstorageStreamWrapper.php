<?php

/**
 * Google Cloud Storage stream wrapper.
 * @see http://php.net/manual/en/class.streamwrapper.php
 */
class GstorageStreamWrapper
{
    protected static $client;
    protected $body;
    protected $mode;
    protected $bucket;
    protected $path;

    public static function register(GstorageClient $client)
    {
        if (in_array('gs', stream_get_wrappers()))
            stream_wrapper_unregister('gs');
        stream_wrapper_register('gs', get_called_class(), STREAM_IS_URL);
        self::$client = $client;
    }

    public static function isRegistered()
    {
        return in_array('gs', stream_get_wrappers());
    }

    public static function getClient()
    {
        return self::$client;
    }

    public function url_stat($path, $flags)
    {
        $options = $this->getOptions($path);
        $result = self::$client->getObjectOrDir($options['bucket'], $options['path']);
        return $this->formatUrlStat($result);
    }

    public function stream_stat()
    {
        // TODO: Combine with url_stat()?
        $stat = fstat($this->body->stream);
        return $stat;
    }

    public static function createStreamFromString($string)
    {
        // TODO: Not sure if this function needed.
        $stream = fopen('php://temp', 'r+');
        if ($string !== '') {
            fwrite($stream, $string);
            rewind($stream);
        }
        return $stream;
    }

    /**
     * Prepare a url_stat result array.
     * @param string|object $result Data to add
     * @return array The modified url_stat result
     */
    protected function formatUrlStat($result = null)
    {
        static $statTemplate = array(
            0  => 0,  'dev'     => 0,
            1  => 0,  'ino'     => 0,
            2  => 0,  'mode'    => 0,
            3  => 0,  'nlink'   => 0,
            4  => 0,  'uid'     => 0,
            5  => 0,  'gid'     => 0,
            6  => -1, 'rdev'    => -1,
            7  => 0,  'size'    => 0,
            8  => 0,  'atime'   => 0,
            9  => 0,  'mtime'   => 0,
            10 => 0,  'ctime'   => 0,
            11 => -1, 'blksize' => -1,
            12 => -1, 'blocks'  => -1,
        );

        $stat = $statTemplate;
        $type = gettype($result);

        // Determine what type of data is being cached
        if ($type == 'NULL' || $type == 'string') {
            // Directory with 0777 access - see "man 2 stat".
            $stat['mode'] = $stat[2] = 0040777;
        } elseif ($type == 'object' && $result->getUpdated()) {
            $stat['mtime'] = $stat[9] = $stat['ctime'] = $stat[10] = strtotime($result->getUpdated());
            $stat['size'] = $stat[7] = $result->getSize();
            // Regular file with 0777 access - see "man 2 stat".
            $stat['mode'] = $stat[2] = 0100777;
        }

        return $stat;
    }

    public function dir_opendir($path, $options)
    {
        $options = $this->getOptions($path);
        $this->bucket = $options['bucket'];
        $this->path = $options['path'];
        $this->body = new ArrayObject();
        $this->body->options = new ArrayObject($options);
        try {
            $this->body->content = self::$client->listDir($this->bucket, $this->path);
            $this->iterator = new ArrayIterator($this->body->content);
        } catch (Exception $e) {
            $this->throwErrorIf(true, $e->getMessage());
            return false;
        }
        return true;
    }

    public function dir_closedir()
    {
        $this->body = null;
        $this->iterator = null;
        return true;
    }

    public function dir_rewinddir()
    {
        $this->iterator->rewind();
        return true;
    }

    public function dir_readdir()
    {
        if (!$this->iterator->valid())
            return false;
        $current = $this->iterator->current();
        $this->iterator->next();
        return $current;
    }

    public function mkdir($path)
    {
        $options = $this->getOptions($path);
        if (self::$client->isBucketExists($options['bucket']))
            return true;
        else
            return false; //TODO: create bucket?
    }

    public function rmdir($path)
    {
        // TODO: implement?
        return false;
    }

    function stream_open($path, $mode, $options, &$opened_path)
    {
        // We don't care about the binary flag
        $this->mode = $mode = rtrim($mode, 'bt');
        $options = $this->getOptions($path);
        $this->bucket = $options['bucket'];
        $this->path = $options['path'];
        $this->body = new ArrayObject();
        $this->body->options = new ArrayObject($options);
        $this->body->position = 0;
        switch ($mode) {
            case 'r':
                $this->body->content = self::$client->download($this->bucket, $this->path);
                break;
            case 'w':
                $this->body->content = '';
                break;
            default:
                return false;
        }
        $this->body->stream = self::createStreamFromString($this->body->content);

        return true;
    }

    function stream_read($count)
    {
        $ret = substr($this->body->content, $this->body->position, $count);
        $this->body->position += strlen($ret);
        return $ret;
    }

    function stream_write($data)
    {
        $left = substr($this->body->content, 0, $this->body->position);
        $right = substr($this->body->content, $this->body->position + strlen($data));
        $this->body->content = $left . $data . $right;
        $this->body->position += strlen($data);
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->body->position;
    }

    function stream_eof()
    {
        return $this->body->position >= strlen($this->body->content);
    }

    function stream_seek($offset, $whence)
    {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($this->body->content) && $offset >= 0) {
                    $this->body->position = $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_CUR:
                if ($offset >= 0) {
                    $this->body->position += $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_END:
                if (strlen($this->body->content) + $offset >= 0) {
                    $this->body->position = strlen($this->body->content) + $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            default:
                return false;
        }
    }

    public function stream_flush()
    {
        if ($this->mode == 'r')
            return false;
        try {
            self::$client->upload($this->bucket, $this->path, $this->body->content);
            return true;
        } catch (\Exception $e) {
            $this->throwErrorIf(true, $e->getMessage());
            return false;
        }
    }

    public function unlink($path)
    {
        list($bucket, $path) = $this->getOptions($path);
        try {
            self::$client->remove($bucket, $path);
        } catch (Exception $e) {
            $this->throwErrorIf(true, $e->getMessage());
            return false;
        }
        return true;
    }

    public function rename($path, $toPath)
    {
        list($bucket, $path) = $this->getOptions($path);
        list($toBucket, $toPath) = $this->getOptions($toPath);
        try {
            self::$client->rename($bucket, $path, $toBucket, $toPath);
        } catch (Exception $e) {
            $this->throwErrorIf(true, $e->getMessage());
            return false;
        }
        return true;
    }

    protected function getOptions($path)
    {
        $options = parse_url($path);
        $this->throwErrorIf($options === false, 'Wrong path');
        $bucket = $options['host'];
        $path = trim(isset($options['path']) ? $options['path'] : '', '/');
        return array(
            0 => $bucket,
            1 => $path,
            'bucket' => $bucket,
            'path' => $path,
        );
    }

    protected function throwErrorIf($expr, $msg, $flags = null)
    {
        if ($expr) {
            if ($flags & STREAM_URL_STAT_QUIET) {
                if ($flags & STREAM_URL_STAT_LINK) {
                    // This is triggered for things like is_link()
                    return $this->formatUrlStat(false);
                }
                return false;
            }
            trigger_error($msg, E_USER_WARNING);
        }
        return false;
    }
}
