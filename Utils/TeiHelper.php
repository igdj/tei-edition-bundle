<?php
/**
 * Helper Class to work with TEI / DTA-Basisformat DTABf
 */

namespace TeiEditionBundle\Utils;

use FluentDOM\DOM\Document as FluentDOMDocument;
use FluentDOM\Exceptions\LoadingError\FileNotLoaded;

class TeiHelper
{
    // Function for basic field validation (present and neither empty nor only white space
    // empty return true for "0" as well
    protected static function isNullOrEmpty($str)
    {
        return is_null($str) || '' === trim($str);
    }

    protected $schemePrefix = 'http://juedische-geschichte-online.net/doku/#';

    protected $errors = [];

    public function getErrors()
    {
        return $this->errors;
    }

    protected function findIdentifierByUri($uri)
    {
        static $registered = false;

        if (!$registered) {
            \LodService\Identifier\Factory::register(\LodService\Identifier\GndIdentifier::class);
            \LodService\Identifier\Factory::register(\LodService\Identifier\WikidataIdentifier::class);

            $registered = true;
        }

        return \LodService\Identifier\Factory::fromUri($uri);
    }

    public function buildPerson($element)
    {
        $person = new \TeiEditionBundle\Entity\Person();

        if (!empty($element['corresp'])) {
            $person->setSlug((string)$element['corresp']);
        }

        if (!empty($element['ref'])) {
            $refs = explode('/\s+/', $element['ref']);
            foreach ($refs as $ref) {
                $identifier = $this->findIdentifierByUri($ref);
                if (!is_null($identifier)) {
                    switch ($identifier->getName()) {
                        case 'gnd':
                            $person->setGnd($identifier->getValue());
                            break;
                    }
                }
            }
        }

        return $person;
    }

    /**
     * Register http://www.tei-c.org/ns/1.0 as default and tei namespace
     */
    protected function registerNamespaces(FluentDOMDocument $dom)
    {
        $dom->registerNamespace('#default', 'http://www.tei-c.org/ns/1.0');
        $dom->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0'); // needed for xpath
    }

    /**
     * Load file into \FluentDOM\DOM\Document
     *
     * @param string $fname
     * @return \FluentDOM\DOM\Document|false
     */
    public function loadXml(string $fname)
    {
        try {
            $dom = \FluentDOM::load($fname, 'xml', [
                \FluentDOM\Loader\Options::ALLOW_FILE => true,
                \FluentDOM\Loader\Options::PRESERVE_WHITESPACE => true,
            ]);
        }
        catch (FileNotLoaded $e) {
            return false;
        }

        $this->registerNamespaces($dom);

        return $dom;
    }

    /**
     * Go through <pb /> and return first
     * non empty facs-attribute
     */
    public function getFirstPbFacs($fname)
    {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        $fnameFacs = '';

        $result = $xml('/tei:TEI/tei:text//tei:pb');
        $facsRef = 1;
        foreach ($result as $element) {
            $facs = $element['facs'];
            if (!empty($facs) && preg_match('/(\d+)/', $facs, $matches)) {
                $facsRef = $matches[1];
            }

            $fnameFacs = sprintf('f%04d', $facsRef);
            break; // we only care about the first one
        }

        return $fnameFacs;
    }

    /**
     * Go through <figure /> and return first
     * non empty facs-attribute
     */
    public function getFirstFigureFacs($fname)
    {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        $fnameFacs = '';

        $result = $xml->xpath('/tei:TEI/tei:text//tei:figure');
        foreach ($result as $element) {
            $facs = $element['facs'];
            if (!empty($facs)) {
                $fnameFacs = (string)$facs;
                break; // we only care about the first one
            }
        }

        return $fnameFacs;
    }

    /**
     * Go through <figure /> and return all
     * non empty facs-attribute
     */
    public function getFigureFacs($fname)
    {
        $xml = $this->loadXml($fname);
        if (false === $xml) {
            return false;
        }

        $fnameFacs = [];

        $result = $xml->xpath('/tei:TEI/tei:text//tei:figure');
        foreach ($result as $element) {
            $facs = $element['facs'];
            if (!empty($facs)) {
                $fnameFacs[] = (string)$facs;
            }
        }

        return $fnameFacs;
    }

    /**
     * Extract XML document properties into Object
     *
     * @param string $fname
     * @param bool $asXml       Returns result properties like title as xml fragment if true
     * @return Object|false
     */
    public function analyzeHeader($fname, bool $asXml = false)
    {
        $dom = $this->loadXml($fname);
        if (false === $dom) {
            return false;
        }

        $result = $dom('/tei:TEI/tei:teiHeader');
        if (0 == $result->length) {
            $this->errors = [
                (object) [ 'message' => 'No teiHeader found' ],
            ];

            return false;
        }

        $article = new \stdClass();

        $header = $result[0];

        // name
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:title[@type="main"]');
        if ($result->length > 0) {
            $article->name = $asXml
                ? $this->extractInnerContent($result[0])
                : $this->extractTextContent($result[0]);
        }

        // author
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:author/tei:persName');
        foreach ($result as $element) {
            $person = $this->buildPerson($element);
            if (!is_null($person)) {
                if (!isset($article->author)) {
                    $article->author = [];
                }

                $article->author[] = $person;
            }
        }

        // translator
        $article->translator = null;
        $result = $header('./tei:fileDesc/tei:titleStmt/tei:editor[@role="translator"]/tei:persName');
        if ($result->length > 0) {
            $element = $result[0];
            $person = $this->buildPerson($element);
            if (!is_null($person)) {
                $article->translator = $person;
            }
        }

        // datePublication
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:date');
        foreach ($result as $element) {
            switch ($element['type']) {
                case 'firstPublication':
                    $article->datePublished = new \DateTime((string)$element);
                    break;

                case 'publication':
                    $article->dateModified = new \DateTime((string)$element);
                    break;
            }
        }

        if (empty($article->datePublished) && !empty($article->dateModified)) {
            $article->datePublished = $article->dateModified;
        }

        if (!empty($article->datePublished) && !empty($article->dateModified)
            && $article->datePublished->format('Y-m-d') == $article->dateModified->format('Y-m-d'))
        {
            unset($article->dateModified);
        }

        // license
        $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence');
        if ($result->length > 0) {
            $article->license = (string)$result[0]['target'];
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence/tei:p');
            if ($result->length > 0) {
                $article->rights = trim($this->extractTextContent($result[0], false));
            }
        }
        else {
            $article->license = null;
            $result = $header('./tei:fileDesc/tei:publicationStmt/tei:availability/tei:p');
            if ($result->length > 0) {
                $article->rights = trim($this->extractTextContent($result[0], false));
            }
        }

        // uid, slug, shelfmark and doi
        foreach ([
                'DTAID' => 'uid',
                'DTADirName' => 'slug',
            ] as $type => $target)
        {
            $result = $header('(./tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="' . $type . '"])[1]');
            if ($result->length > 0) {
                $article->$target = (string)$result[0];
            }
        }

        // primary date and publication
        $result = $header('./tei:fileDesc/tei:sourceDesc/tei:bibl');
        if ($result->length > 0) {
            $bibl = $result[0];

            $result = $bibl('./tei:author');
            $article->creator = $result->length > 0
                ? trim((string)$result[0])
                : null;

            $result = $bibl('./tei:placeName');
            if ($result->length > 0) {
                $place = new \TeiEditionBundle\Entity\Place();
                $place->setName((string)$result[0]);
                $uri = $result[0]->hasAttribute('ref')
                    ? $result[0]->getAttribute('ref')
                    : null;

                if (!empty($uri)) {
                    if (preg_match('/^'
                                   . preg_quote('http://vocab.getty.edu/tgn/', '/')
                                   . '(\d+)$/', $uri, $matches))
                    {
                        $place->setTgn($matches[1]);
                    }
                }

                $article->contentLocation = $place;
                $corresp =  $result[0]->hasAttribute('corresp')
                    ? $result[0]->getAttribute('corresp')
                    : null;

                if (preg_match('/^\#([\+\-]?\d+\.?\d*)\s*,\s*([\+\-]?\d+\.?\d*)\s*$/', $corresp, $matches)) {
                    $article->geo = implode(',', [ $matches[1], $matches[2] ]);
                }
                else {
                    $article->geo = null;
                }
            }

            $result = $bibl('tei:orgName');
            if ($result->length > 0) {
                $org = new \TeiEditionBundle\Entity\Organization();
                $org->setName($this->extractTextContent($result[0]));
                $uri = $result[0]->hasAttribute('ref')
                    ? $result[0]->getAttribute('ref')
                    : null;
                if (!empty($uri)) {
                    if (preg_match('/^https?'
                                   . preg_quote('://d-nb.info/gnd/', '/')
                                   . '(\d+[\-]?[\dxX]?)$/', $uri, $matches))
                    {
                        $org->setGnd($matches[1]);
                    }
                }

                $article->provider = $org;
            }

            $result = $bibl('./tei:idno');
            $article->providerIdno =
                $result->length > 0
                    ? (string)($result[0]) : null;

            $result = $bibl('./tei:date');
            if ($result->length > 0) {
                $date = $result[0];
                $article->dateCreatedDisplay = $this->extractTextContent($date);
                $when = $date->hasAttribute('when')
                    ? $date->getAttribute('when')
                    : null;
                if (!empty($when)) {
                    $article->dateCreated = $when;
                }
            }
        }

        // url
        $article->url = null;
        $result = $header('(./tei:fileDesc/tei:sourceDesc/tei:msDesc/tei:msIdentifier/tei:idno/tei:idno[@type="URLImages"])[1]');
        if ($result->length > 0) {
            $article->url = (string)$result[0];
        }

        // genre, classification and translatedFrom
        $article->translatedFrom = null; // so legacy value gets cleared if now longer set
        $keywords = [];
        $result = $header('./tei:profileDesc/tei:textClass/tei:classCode');
        foreach ($result as $element) {
            $label_parts = explode(':', (string)$element, 2);
            $label = $label_parts[0];
            if (count($label_parts) > 1) {
                $article->sourceType = $label_parts[1];
            }

            switch ($element['scheme']) {
                case $this->schemePrefix . 'genre':
                    switch ($label) {
                        case 'Quelle':
                        case 'Source':
                            $article->genre = 'source';
                            break;

                        case 'Interpretation':
                        case 'Interpretationstext':
                        case 'Article':
                        case 'Beitrag':
                            $article->genre = 'interpretation';
                            break;

                        case 'Introduction':
                        case 'Einführung':
                        case 'Übersichtstext':
                        case 'Hintergrundtext':
                            $article->genre = 'background';
                            break;

                        default:
                            // var_dump($label);
                    }
                    break;

                case $this->schemePrefix . 'topic':
                    $keywords[] = $label;
                    break;

                case $this->schemePrefix . 'translated-from':
                    if (!empty($label)) {
                        $article->translatedFrom = $label;
                    }
                    break;
            }
        }

        $article->keywords = $keywords;

        // isPartOf
        if (isset($article->genre) && 'source' == $article->genre) {
            $result = $header('./tei:fileDesc/tei:seriesStmt/tei:idno[@type="DTAID"]');
            foreach ($result as $element) {
                $idno = trim((string)$element);
                if (!empty($idno)) {
                    if (preg_match('/^\#?(.*?(article|source)-\d+)$/', $idno, $matches)) {
                        $isPartOf = new \TeiEditionBundle\Entity\Article();
                        $isPartOf->setUid($matches[1]);
                        $article->isPartOf = $isPartOf;
                    }
                }
            }

            // legacy
            $result = $header('./tei:fileDesc/tei:seriesStmt/tei:title[@type="main"]');
            foreach ($result as $element) {
                if (!empty($element['corresp'])) {
                    $corresp = (string)$element['corresp'];
                    if (preg_match('/^\#?(.*?(article|source)-\d+)$/', $corresp, $matches)) {
                        $isPartOf = new \TeiEditionBundle\Entity\Article();
                        $isPartOf->setUid($matches[1]);
                        $article->isPartOf = $isPartOf;
                    }
                }
            }
        }

        // language
        $langIdents = [];
        $result = $header('./tei:profileDesc/tei:langUsage/tei:language');
        foreach ($result as $element) {
            if (!empty($element['ident'])) {
                $langIdents[] = (string)$element['ident'];
            }
        }
        $article->language = join(', ', $langIdents);

        return $article;
    }

    private function createElement($doc, $name, $content = null, array $attributes = null)
    {
        list($prefix, $localName) = \FluentDOM\Utility\QualifiedName::split($name);

        if (!empty($prefix)) {
            // check if prefix is equal to the default prefix, then we drop it
            $namespaceURI = (string)$doc->namespaces()->resolveNamespace($prefix);
            if (!empty($namespaceURI) && $namespaceURI === (string)$doc->namespaces()->resolveNamespace('#default')) {
                $name = $localName;
            }
        }

        return $doc->createElement($name, $content, $attributes);
    }

    private function addDescendants($parent, $path, $callbacks, $updateLeafNode = false)
    {
        $pathParts = explode('/', $path);
        $updateExisting = false;

        // if missing, we need to iteratively add
        for ($depth = 0; $depth < count($pathParts); $depth++) {
            $name = $pathParts[$depth];
            $subPath = './' . $name;
            $result = $parent($subPath);
            if ($result->length > 0) {
                $parent = $result[0];
                if ($depth == count($pathParts) - 1) {
                    if ($updateLeafNode) {
                        $updateExisting = true;
                    }
                    else {
                        $parent = $parent->parentNode; // we append to parent, not to match
                    }
                }
                else {
                    continue;
                }
            }

            if (array_key_exists($name, $callbacks)) {
                // custom call
                $parent = $callbacks[$name]($parent, $name, $updateExisting);
            }
            else if (!$updateExisting) {
                // default is an element without attributes
                $attributes = null;
                if (preg_match('/\[(.*?)\]$/', $name, $matches)) {
                    $name = preg_replace('/\[(.*?)\]$/', '', $name);

                    // also deal with conditions in the form of @type="value" by setting this attribute
                    $condition = $matches[1];
                    if (preg_match('/^\@([a-z]+)\=([\'"])(.*?)\2$/', $condition, $matches)) {
                        $attributes[$matches[1]] = $matches[3];
                    }
                }

                $parent = $parent->appendChild($this->createElement($parent->ownerDocument, $name, $attributes));
            }
        }

        return $parent;
    }

    protected function addChildStructure($parent, $structure, $prefix = '')
    {
        foreach ($structure as $tagName => $content) {
            if (is_scalar($content)) {
                $self = $parent->appendElement($prefix . $tagName, $content);
            }
            else {
                $atKeys = preg_grep('/^@/', array_keys($content));

                if (!empty($atKeys)) {
                    // simple element with attributes
                    if (in_array('@value', $atKeys)) {
                        $self = $parent->appendElement($prefix . $tagName, $content['@value']);
                    }
                    else {
                        $self = $parent->appendElement($prefix . $tagName);
                    }

                    foreach ($atKeys as $key) {
                        if ('@value' == $key) {
                            continue;
                        }

                        $self->setAttribute($prefix . ltrim($key, '@'), $content[$key]);
                    }
                }
                else {
                    $self = $parent->appendElement($prefix . $tagName);
                    $this->addChildStructure($self, $content, $prefix);
                }
            }
        }
    }

    /**
     * Load XML from file and adjust header according to $data
     *
     * @param string $fname
     * @param array $data
     * @return \FluentDOM\DOM\Document|false
     */
    public function adjustHeader($fname, array $data)
    {
        $dom = $this->loadXml($fname);
        if (false === $dom) {
            return false;
        }

        $hasHeader = $dom('count(/tei:TEI/tei:teiHeader) > 0');

        if (!$hasHeader) {
            // we only adjust data in header - so we are done
            return $dom;
        }

        $header = $dom('/tei:TEI/tei:teiHeader')[0];

        $root = $dom('/tei:TEI')[0];

        $lang = $root->getAttribute('xml:lang');
        if (!empty($lang)) {
            $langCode3 = \TeiEditionBundle\Utils\Iso639::code1to3($lang);
            if (!empty($langCode3)) {
                $langName = \TeiEditionBundle\Utils\Iso639::nameByCode3($langCode3);

                $this->addDescendants($header, 'tei:profileDesc/tei:langUsage/tei:language', [
                    'tei:language' => function ($parent, $name) use ($langName, $langCode3) {
                        $nameParts = explode(':', $name, 2);
                        if (count($nameParts) == 2 && 'tei' == $nameParts[0]) {
                            // default namespace
                            $name = $nameParts[1];
                        }

                        $self = $parent->appendElement($name, $langName);
                        $self->setAttribute('ident', $langCode3);

                        return $self;
                    },
                ]);

                // remove the original attribute
                $root->removeAttribute('xml:lang');
            }
        }

        // adjust <?xml-model if still set to basisformat_ohne_header.rng
        // see http://stackoverflow.com/a/24914655
        $xpath = new \DOMXpath($dom);
        $result = $xpath->evaluate(
            '/processing-instruction()[name() = "xml-model"]'
        );

        foreach ($result as $node) {
            if (preg_match('/basisformat_ohne_header\.rng/', $node->textContent)) {
                // we need to replace the node
                $pi = $dom->createProcessingInstruction('xml-model', preg_replace('/basisformat_ohne_header\.rng/', 'basisformat.rng', $node->textContent));
                $dom->appendChild($pi);
                $node->parentNode->insertBefore($pi, $node);
                $node->parentNode->removeChild($node);
            }
        }

        // remove all oxygen comments
        $result = $xpath->evaluate(
            '//processing-instruction()[name() = "oxy_comment_start" or name() = "oxy_comment_end"]'
        );

        foreach ($result as $node) {
            $node->parentNode->removeChild($node);
        }

        // if we have only <title> and not <title type="main">, add this attribute
        $hasTitleAttrMain = $header('count(./tei:fileDesc/tei:titleStmt/tei:title[@type="main"]) > 0');
        if (!$hasTitleAttrMain) {
            $result = $header('./tei:fileDesc/tei:titleStmt/tei:title[not(@type)]');
            if ($result->length > 0) {
                $result[0]->setAttribute('type', 'main');
            }
        }

        // set/remove translator
        if (array_key_exists('translator', $data)) {
            $xpath = 'tei:fileDesc/tei:titleStmt/tei:editor[@role="translator"]';

            if (empty($data['translator'])) {
                // remove
                \FluentDom($header)->find($xpath)->remove();
            }
            else {
                $this->addDescendants($header, $xpath, [
                    'tei:editor[@role="translator"]' => function ($parent, $name) use ($data) {
                        $self = null;
                        foreach ($data['translator'] as $corresp => $persName) {
                            $self = $parent->appendElement('editor');
                            $self->setAttribute('role', 'translator');
                            $persName = $self->appendElement('persName', $persName);
                            $persName->setAttribute('corresp', $corresp);
                        }

                        return $self;
                    },
                ]);
            }
        }

        // update publicationStmt
        if (!empty($data['publisher'])) {
            // remove unstructured publicationStmt
            \FluentDom($header)->find('./tei:fileDesc/tei:publicationStmt/tei:p[not(*) and not(normalize-space())]')
                ->remove();

            $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:publisher', [
                'tei:publisher' => function ($parent, $name) use ($data) {
                    $nameParts = explode(':', $name, 2);
                    if (count($nameParts) == 2 && 'tei' == $nameParts[0]) {
                        // default namespace
                        $name = $nameParts[1];
                    }

                    $self = $parent->appendElement($name);
                    $this->addChildStructure($self, $data['publisher']);

                    return $self;
                },
            ]);

            if (!empty($data['dates'])) {
                foreach ($data['dates'] as $type => $val) {
                    $match = 'tei:date[@type="' . $type . '"]';
                    $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/' . $match, [
                        $match => function ($parent, $name) use ($type, $val) {
                            $self = $parent->appendElement('date', $val);
                            $self->setAttribute('type', $type);

                            return $self;
                        },
                    ]);
                }
            }

            if (!empty($data['license'])) {
                $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:availability', [
                    'tei:availability' => function ($parent, $name) use ($data) {
                        $nameParts = explode(':', $name, 2);
                        if (count($nameParts) == 2 && 'tei' == $nameParts[0]) {
                            // default namespace
                            $name = $nameParts[1];
                        }

                        $self = $parent->appendElement($name);
                        $targets = array_keys($data['license']);
                        if (!empty($targets)) {
                            $target = $targets[0];
                            if (!empty($target)) {
                                $self = $self->appendElement('licence');
                                $self->setAttribute('target', $target);
                                $this->addChildStructure($self, [ 'p' => $data['license'][$target] ]);
                            }
                            else {
                                $availability = $data['license'][$target];
                                if (!empty($availability)) {
                                    $this->addChildStructure($self, [ 'p' => $availability ]);
                                }
                            }
                        }

                        return $self;
                    },
                ]);
            }

            if (!empty($data['uid'])) {
                $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="DTAID"]', [
                    'tei:idno[@type="DTAID"]' => function ($parent, $name) use ($data) {
                        $self = $parent->appendElement('idno', $data['uid']);
                        $self->setAttribute('type', 'DTAID');

                        return $self;
                    },
                ]);
            }

            if (!empty($data['slug'])) {
                $this->addDescendants($header, 'tei:fileDesc/tei:publicationStmt/tei:idno/tei:idno[@type="DTADirName"]', [
                    'tei:idno[@type="DTADirName"]' => function ($parent, $name) use ($data) {
                        $self = $parent->appendElement('idno', $data['slug']);
                        $self->setAttribute('type', 'DTADirName');

                        return $self;
                    },
                ]);
            }
        }

        if (!empty($data['seriesStmt'])) {
            $this->addDescendants($header, 'tei:fileDesc/tei:seriesStmt', [
                'tei:seriesStmt' => function ($parent, $name) use ($header, $data) {
                    // seriesStmt must go before sourceDesc
                    \FluentDom($header)
                        ->find('./tei:fileDesc/tei:sourceDesc')
                        ->remove();

                    $self = $parent->appendElement('seriesStmt');
                    foreach ($data['seriesStmt'] as $corresp => $title) {
                        $child = $self->appendElement('title', $title);
                        $child->setAttribute('type', 'main');

                        $child = $self->appendElement('idno', $corresp);
                        $child->setAttribute('type', 'DTAID');
                    }

                    return $self;
                },
            ]);
        }

        if (!empty($data['bibl'])) {
            // remove sourceDesc if it is manually added <p>
            \FluentDom($header)
                ->find('./tei:fileDesc/tei:sourceDesc/tei:p')
                ->remove();

            $this->addDescendants($header, 'tei:fileDesc/tei:sourceDesc/tei:bibl', [
                'tei:bibl' => function ($parent, $name) use ($data) {
                    $nameParts = explode(':', $name, 2);
                    if (count($nameParts) == 2 && 'tei' == $nameParts[0]) {
                        // default namespace
                        $name = $nameParts[1];
                    }
                    $self = $parent->appendElement($name);
                    $this->addChildStructure($self, $data['bibl']);
                },
            ]);
        }

        if (!empty($data['URLImages'])) {
            $this->addDescendants($header, 'tei:fileDesc/tei:sourceDesc/tei:msDesc/tei:msIdentifier', [
                'tei:msIdentifier' => function ($parent, $name) use ($data) {
                    $nameParts = explode(':', $name, 2);
                    if (count($nameParts) == 2 && 'tei' == $nameParts[0]) {
                        // default namespace
                        $name = $nameParts[1];
                    }
                    $self = $parent->appendElement($name);

                    $structure = [];
                    if (!empty($data['bibl']['orgName'])) {
                        $structure['repository'] = $data['bibl']['orgName']['@value'];
                    }

                    $structure['idno'] = [
                        'idno' => [
                            '@type' => 'URLImages',
                            '@value' => htmlspecialchars($data['URLImages'], ENT_XML1, 'UTF-8'),
                        ],
                    ];

                    $this->addChildStructure($self, $structure);
                },
            ]);
        }

        if (!empty($data['genre'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "genre")]', [
                'tei:classCode[contains(@scheme, "genre")]' => function ($parent, $name) use ($data) {
                    $self = $parent->appendElement('classCode', $data['genre']);
                    $self->setAttribute('scheme', $this->schemePrefix . 'genre');

                    return $self;
                },
            ]);
        }

        if (!empty($data['topic'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "topic")]', [
                'tei:classCode[contains(@scheme, "topic")]' => function ($parent, $name) use ($data) {
                    $self = null;
                    foreach ($data['topic'] as $topic) {
                        $self = $parent->appendElement('classCode', $topic);
                        $self->setAttribute('scheme', $this->schemePrefix . 'topic');
                    }

                    return $self;
                },
            ]);
        }

        if (!empty($data['translatedFrom'])) {
            $this->addDescendants($header, 'tei:profileDesc/tei:textClass/tei:classCode[contains(@scheme, "translated-from")]', [
                'tei:classCode[contains(@scheme, "translated-from")]' => function ($parent, $name) use ($data) {
                    $self = $parent->appendElement('classCode', $data['translatedFrom']);
                    $self->setAttribute('scheme', $this->schemePrefix . 'translated-from');

                    return $self;
                },
            ]);
        }

        return $dom;
    }

    protected function registerXpathNamespaces($xml)
    {
        // $xml->registerXPathNamespace('xml', 'http://www.w3.org/XML/1998/namespace');
        $xml->registerXPathNamespace('tei', 'http://www.tei-c.org/ns/1.0');
    }

    protected function extractTextContent($node, $normalizeWhitespace = true)
    {
        $textContent = $node->textContent;

        if ($normalizeWhitespace) {
            // http://stackoverflow.com/a/33980774
            return preg_replace(['(\s+)u', '(^\s|\s$)u'], [' ', ''], $textContent);
        }

        return $textContent;
    }

    public function extractEntities($fname)
    {
        $reader = new CollectingReader();

        $reader->elementMap = [
            '{http://www.tei-c.org/ns/1.0}editor' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}persName' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}placeName' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}orgName' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
            '{http://www.tei-c.org/ns/1.0}date' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
        ];

        $input = file_get_contents($fname);

        $additional = [];
        try {
            $reader->XML($input);
            $output = $reader->parse();

            foreach ($output as $entity) {
                $attribute = '{http://www.tei-c.org/ns/1.0}date' == $entity['name']
                    ? 'corresp' : 'ref';

                if (empty($entity['attributes'][$attribute])) {
                  continue;
                }

                $uri = trim($entity['attributes'][$attribute]);

                switch ($entity['name']) {
                    case '{http://www.tei-c.org/ns/1.0}placeName':
                        $type = 'place';
                        if (preg_match('/^'
                                       . preg_quote('http://vocab.getty.edu/tgn/', '/')
                                       . '\d+$/', $uri))
                        {
                            ;
                        }
                        else if (preg_match('/geo\:(-?\d+\.\d*),\s*(-?\d+\.\d*)/', $uri, $matches)) {
                            $uri = sprintf('geo:%s,%s', $matches[1], $matches[2]);
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}persName':
                        $type = 'person';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+[xX]?$/', $uri)

                            || preg_match('/^'
                                       . preg_quote('http://www.dasjuedischehamburg.de/inhalt/', '/')
                                       . '.+$/', $uri)

                            || preg_match('/^'
                                            . preg_quote('http://www.stolpersteine-hamburg.de/', '/')
                                            . '.*?BIO_ID=(\d+)/', $uri)
                        )
                        {
                            ;
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}orgName':
                        $type = 'organization';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri))
                        {
                            ;
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      case '{http://www.tei-c.org/ns/1.0}date':
                        $type = 'event';
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '\d+\-?[\dxX]?$/', $uri))
                        {
                            ;
                        }
                        else {
                            // die($uri);
                            unset($uri);
                        }
                        break;

                      default:
                        unset($uri);
                }

                if (isset($uri)) {
                    if (!isset($additional[$type])) {
                        $additional[$type] = [];
                    }

                    if (!isset($additional[$type][$uri])) {
                        $additional[$type][$uri] = 0;
                    }

                    ++$additional[$type][$uri];
                }
            }
        }
        catch (\Exception $e) {
            var_dump($e);

            return false;
        }

        return $additional;
    }

    public function extractBibitems($fname, $slugify = null)
    {
        $input = file_get_contents($fname);
        $reader = new CollectingReader();

        $reader->elementMap = [
            '{http://www.tei-c.org/ns/1.0}bibl' => '\\TeiEditionBundle\\Utils\\CollectingReader::collectElement',
        ];

        $items = [];
        try {
            $reader->XML($input);
            $output = $reader->parse();
            foreach ($output as $item) {
                if (empty($item['attributes']['corresp'])) {
                  continue;
                }

                $key = trim($item['attributes']['corresp']);
                if (!is_null($slugify)) {
                    $key = \TeiEditionBundle\Entity\Bibitem::slugifyCorresp($slugify, $key);
                }

                if (!empty($key)) {
                    if (!isset($items[$key])) {
                        $items[$key] = 0;
                    }

                    ++$items[$key];
                }
            }
        }
        catch (\Exception $e) {
            var_dump($e);

            return false;
        }

        return $items;
    }

    public function validateXml($fname, $fnameSchema, $schemaType = 'relaxng')
    {
        switch ($schemaType) {
            case 'relaxng':
                $document = new \Brunty\DOMDocument;
                if (is_resource($fname)) {
                    $document->loadXML(stream_get_contents($fname));
                }
                else {
                    $document->load($fname);
                }

                $result = $document->relaxNGValidate($fnameSchema);
                if (!$result) {
                    $errors = [];
                    foreach ($document->getValidationWarnings() as $message) {
                        $errors[] = (object)[ 'message' => $message ];
                    }
                    $this->errors = $errors;
                }

                return $result;
                break;

            default:
                throw new \InvalidArgumentException('Invalid schemaType: ' . $schemaType);
        }
    }
}

/**
 * We need two different implementations due to changed
 * method signature in PHP 8
 * All shared code is in the abstract imtermediary class
 */
abstract class CollectingReaderShared
extends \Sabre\Xml\Reader
{
    protected $collected;

    function parse() : array
    {
        $this->collected = [];
        parent::parse();

        return $this->collected;
    }

    function collect($output)
    {
        $this->collected[] = $output;
    }

    static function collectElement(CollectingReader $reader)
    {
        $name = $reader->getClark();

        switch ($name) {
            case '{http://www.tei-c.org/ns/1.0}editor':
                $attributes = $reader->parseAttributes();

                // ignore persName / orgName below <editor corresp="#DTACorpusPublisher">
                $isDTACorpusPublisher = !empty($attributes['corresp'])
                    && $attributes['corresp'] == '#DTACorpusPublisher';

                if ($isDTACorpusPublisher) {
                    // ignore
                    $reader->next();
                }
                else {
                    // continue with innerTree
                    // must come before $reader->readText() below
                    $reader->parseInnerTree();
                }
                break;

            default:
                $res = [
                    'name' => $name,
                    'attributes' => $reader->parseAttributes(),
                    'text' => $reader->readText(),
                ];

                $reader->collect($res);

                // continue
                $reader->next();
        }
    }
}

if (\PHP_VERSION_ID >= 80000) {
    class CollectingReader extends CollectingReaderShared
    {
        /**
         * In PHP 8 this method is now static,
         * but can still be called on an XMLReader instance.
         * https://www.php.net/manual/en/xmlreader.xml.php
         */
        public static function XML($source, $encoding = null, $options = 0)
        {
            // hack for <?xml-model href="http://www.deutschestextarchiv.de/basisformat_ohne_header.rng"
            // type="application/xml"
            // schematypens="http://relaxng.org/ns/structure/1.0"?\>
            $source = preg_replace('/<\?xml\-model [\s\S\n]*?\?>/', '', $source);

            parent::XML($source, $encoding, $options);
        }
    }
}
else {
    class CollectingReader extends CollectingReaderShared
    {
        /**
         * Non-static signature before PHP 8
         */
        public function XML($source, $encoding = null, $options = 0)
        {
            // hack for <?xml-model href="http://www.deutschestextarchiv.de/basisformat_ohne_header.rng"
            // type="application/xml"
            // schematypens="http://relaxng.org/ns/structure/1.0"?\>
            $source = preg_replace('/<\?xml\-model [\s\S\n]*?\?>/', '', $source);

            parent::XML($source, $encoding, $options);
        }
    }
}
