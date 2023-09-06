<?php
// src/Entity/SourceArticle.php

namespace TeiEditionBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use TeiEditionBundle\Utils\JsonLd;

/**
 *
 * @ORM\Entity
 */
class SourceArticle
extends Article
{
    /**
     * Gets genre.
     *
     * @return string
     */
    public function getGenre()
    {
        return 'source';
    }

    public static function buildDateBucket($date)
    {
        // we only care about the year
        if (is_object($date) && $date instanceof \DateTime) {
            $date = $date->format('Y');
        }
        else {
            if (!preg_match('/(\-?\d{4})/', $date, $matches)) {
                return [ $date, $date ];
            }

            $date = $matches[1];
        }

        if ($date < 1900) {
            $bucket = $date - $date % 100; // centuries
            if ($date < 0) {
                $key = 'epoch.century-bce';
            }
            else {
                $key = 'epoch.century';
                if (0 == $bucket / 100) {
                    // 1st century
                    $key = 'epoch.century-st';
                }
                else if (1 == $bucket / 100) {
                    // 2nd century
                    $key = 'epoch.century-nd';
                }
                else if (2 == $bucket / 100) {
                    // 3rd century
                    $key = 'epoch.century-rd';
                }
            }
        }
        else {
            $bucket = $date - $date % 10; // decade
            $key = 'epoch.decade';
        }

        return [ $bucket, $key ];
    }

    public function getEpochLabel()
    {
        return self::buildDateBucket($this->dateCreated);
    }

    public function licenseAllowsDownload()
    {
        // check if we are allowed to download
        $license = $this->getLicense();
        if (!empty($license)
            && in_array($license, [
                '#personal-use',
                '#public-domain',
                'http://creativecommons.org/publicdomain/zero/1.0/',
                'http://creativecommons.org/licenses/by-sa/4.0/',
                'http://creativecommons.org/licenses/by-nc-sa/4.0/',
                'http://creativecommons.org/licenses/by-nc-nd/4.0/',
                'http://rightsstatements.org/vocab/NoC-NC/1.0/',
            ]))
        {
            return true;
        }

        return false;
    }

    public function jsonLdSerialize($locale, $omitContext = false, $standalone = false)
    {
        $ret = parent::jsonLdSerialize($locale, $omitContext, $standalone);

        if (!empty($this->creator)) {
            $ret['creator'] = JsonLd::normalizeWhitespace($this->creator);
        }

        if (!empty($this->dateCreated)) {
            $ret['dateCreated'] = \TeiEditionBundle\Utils\JsonLd::formatDate8601($this->dateCreated);
        }

        if (isset($this->contentLocation)) {
            $ret['spatialCoverage'] = $this->contentLocation->jsonLdSerialize($locale, true, $standalone);
        }

        if (isset($this->provider)) {
            $ret['provider'] = $this->provider->jsonLdSerialize($locale, true, $standalone);
        }

        if (empty($ret['keywords']) && !is_null($this->isPartOf)) {
            // keywords are assigned to the interpretations
            $keywords = $this->isPartOf->getKeywords();
            if (!empty($keywords)) {
                $ret['keywords'] = join(', ', $keywords);
            }
        }

        return $ret;
    }
}
