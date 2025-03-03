<?php

namespace TeiEditionBundle\Utils\Xsl;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class XsltCacheAdapter
implements XsltAdapterInterface
{
    protected $xsltAdapter;
    protected $cache;
    protected $errors = [];
    protected $config = [];

    public function __construct(XsltAdapterInterface $xsltAdapter, CacheInterface $cache = null, $config = null)
    {
        $this->xsltAdapter = $xsltAdapter;
        $this->cache = $cache;

        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function computeETag($fnameXml, $fnameXsl, $options = [])
    {
        if (!file_exists($fnameXml)) {
            return null;
        }

        $modifiedXml = filemtime($fnameXml);
        if (false === $modifiedXml) {
            return null;
        }

        if (!file_exists($fnameXsl)) {
            return null;
        }

        $modifiedXsl = filemtime($fnameXsl);
        if (false === $modifiedXsl) {
            return null;
        }

        return join('-', [
            md5($fnameXml), $modifiedXml,
            md5($fnameXsl), $modifiedXsl,
            md5(json_encode($options))
        ]);
    }

    public function transformToXml(string $srcFilename, string $xslFilename, array $options = [])
    {
        $this->errors = [];

        $etag = null;

        if (!is_null($this->cache)) {
            $etag = $this->computeETag($srcFilename, $xslFilename, $options);
        }

        if (is_null($etag)) {
            // transform without caching
            $res = $this->xsltAdapter->transformToXml($srcFilename, $xslFilename, $options);
            $this->errors = $this->xsltAdapter->getErrors();
        }
        else {
            // get from cache or generate
            $res = $this->cache->get($etag, function (ItemInterface $item, bool &$save) use ($srcFilename, $xslFilename, $options) : string {
                $res = $this->xsltAdapter->transformToXml($srcFilename, $xslFilename, $options);
                $this->errors = $this->xsltAdapter->getErrors();

                if (empty($res) || !empty($this->errors)) {
                    // don't save on errors
                    $save = false;
                }

                return $res;
            });
        }

        return $res;
    }
}
