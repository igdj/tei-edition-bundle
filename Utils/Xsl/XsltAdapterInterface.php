<?php

namespace TeiEditionBundle\Utils\Xsl;

interface XsltAdapterInterface
{
    public function getErrors(): array;

    public function transformToXml(string $srcFilename, string $xslFilename, array $options = []);
}
