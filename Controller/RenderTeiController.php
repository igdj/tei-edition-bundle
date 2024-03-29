<?php
// src/Controller/RenderTeiController.php

/**
 * Shared methods for Controllers working with the TEI-files
 */

namespace TeiEditionBundle\Controller;

use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\HttpKernel\KernelInterface;

use Symfony\Contracts\Translation\TranslatorInterface;

use Cocur\Slugify\SlugifyInterface;

use Doctrine\ORM\EntityManagerInterface;

use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;

use TeiEditionBundle\Utils\Xsl\XsltProcessor;
use TeiEditionBundle\Utils\PdfGenerator;

abstract class RenderTeiController
extends BaseController
{
    use SharingBuilderTrait,
        \TeiEditionBundle\Utils\RenderTeiTrait; // use shared method renderTei()

    protected $xsltProcessor;
    protected $pdfGenerator;

    /**
     * Inject XsltProcessor and PdfGenerator
     */
    public function __construct(KernelInterface $kernel,
                                SlugifyInterface $slugify,
                                SettableThemeContext $themeContext,
                                \Twig\Environment $twig,
                                XsltProcessor $xsltProcessor,
                                PdfGenerator $pdfGenerator)
    {
        parent::__construct($kernel, $slugify, $themeContext, $twig);

        $this->xsltProcessor = $xsltProcessor;
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * lookup internal links
     */
    protected function buildRefLookup($refs,
                                      EntityManagerInterface $entityManager,
                                      TranslatorInterface $translator, $language)
    {
        $refMap = [];

        if (empty($refs)) {
            return ;
        }

        $refs = array_unique(array_map(function ($uid) {
                // chop of anchor
                return preg_replace('/\#.*/', '', $uid);
            }, $refs));

        // make sure we only pick-up the published ones
        $query = $entityManager
            ->createQuery("SELECT a"
                          . " FROM \TeiEditionBundle\Entity\Article a"
                          . " WHERE a.status IN (1)"
                          . " AND a.uid IN (:refs)"
                          . (!empty($language) ? ' AND a.language=:language' : '')
                          . " ORDER BY a.name")
            ->setParameter('refs', $refs, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
            ;

        if (!empty($language)) {
            $query->setParameter('language', $language);
        }

        foreach ($query->getResult() as $article) {
            $prefix = null;
            switch ($article->getArticleSection()) {
                case 'background':
                    $prefix = $translator->trans('Topic');
                    $route = 'topic-background';
                    $params = [ 'slug' => $article->getSlug() ];
                    break;

                case 'interpretation':
                    $prefix = $translator->trans('Interpretation');
                    $route = 'article';
                    $params = [ 'slug' => $article->getSlug(true) ];
                    break;

                case 'source':
                    $prefix = $translator->trans('Source');
                    $route = 'source';
                    $params = [ 'uid' => $article->getUid() ];
                    break;

                default:
                    $route = null;
            }

            if (!is_null($route)) {
                $entry = [
                    'href' => $this->generateUrl($route, $params, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                ];

                if (!empty($prefix)) {
                    $entry['headline'] = $prefix . ': ' . $article->getName();
                    if (count($article->getAuthor()) > 0) {
                        $authors = [];
                        foreach ($article->getAuthor() as $author) {
                            $authors[] = $author->getFullname(true);
                        }
                        $entry['headline'] .= ' (' . implode(', ', $authors) . ')';
                    }
                }

                $refMap[$article->getUid()] = $entry;
            }
        }

        return $refMap;
    }

    /**
     * lookup marked-up entities
     */
    protected function buildEntityLookup(EntityManagerInterface $entityManager, $entities)
    {
        $entitiesByType = [
            'person' => [],
            'place' => [],
            'organization' => [],
            'date' => [],
        ];

        foreach ($entities as $entity) {
            if (!array_key_exists($entity['type'], $entitiesByType)) {
                continue;
            }

            if (!array_key_exists($entity['uri'], $entitiesByType[$entity['type']])) {
                $entitiesByType[$entity['type']][$entity['uri']] = [ 'count' => 0 ];
            }

            ++$entitiesByType[$entity['type']][$entity['uri']]['count'];
        }

        foreach ($entitiesByType as $type => $uriCount) {
            switch ($type) {
                case 'person':
                    $personGnds = $personDjhs = $personStolpersteine = [];
                    foreach ($uriCount as $uri => $count) {
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '(\d+[xX]?)$/', $uri, $matches))
                        {
                            $personGnds[$matches[1]] = $uri;
                        }
                        else if (preg_match('/^https?'
                                    . preg_quote('://www.dasjuedischehamburg.de/inhalt/', '/')
                                    . '(.+)$/', $uri, $matches))
                        {
                            $personDjhs[urldecode($matches[1])] = $uri;
                        }
                        else if (preg_match('/^https?'
                                            . preg_quote('://www.stolpersteine-hamburg.de/', '/')
                                            . '.*?BIO_ID=(\d+)/', $uri, $matches))
                        {
                            $personStolpersteine[$matches[1]] = $uri;
                        }
                    }

                    if (!empty($personGnds)) {
                        $persons = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Person')
                            ->findBy([ 'gnd' => array_keys($personGnds) ])
                            ;

                        foreach ($persons as $person) {
                            if ($person->getStatus() >= 0) {
                                $uri = $personGnds[$person->getGnd()];
                                $details = [
                                    'url' => $this->generateUrl('person-by-gnd', [
                                        'gnd' => $person->getGnd(),
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }

                    if (!empty($personDjhs)) {
                        $persons = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Person')
                            ->findBy([ 'djh' => array_keys($personDjhs) ])
                            ;

                        foreach ($persons as $person) {
                            if ($person->getStatus() >= 0) {
                                $uri = $personDjhs[$person->getDjh()];
                                $details = [
                                    'url' => $this->generateUrl('person', [
                                        'id' => $person->getId(),
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }

                    if (!empty($personStolpersteine)) {
                        $persons = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Person')
                            ->findBy([ 'stolpersteine' => array_keys($personStolpersteine) ])
                            ;

                        foreach ($persons as $person) {
                            if ($person->getStatus() >= 0) {
                                $uri = $personStolpersteine[$person->getStolpersteine()];
                                $details = [
                                    'url' => $this->generateUrl('person', [
                                        'id' => $person->getId(),
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }
                    break;

                case 'place':
                    $placeTgns = $placeGeo = [];
                    foreach ($uriCount as $uri => $count) {
                        if (preg_match('/^'
                                       . preg_quote('http://vocab.getty.edu/tgn/', '/')
                                       . '(\d+?)$/', $uri, $matches))
                        {
                            $placeTgns[$matches[1]] = $uri;
                        }
                        else if (preg_match('/^geo\:(-?\d+\.\d*)(,)\s*(-?\d+\.\d*)/', $uri, $matches)) {
                            $placeGeo['geo:' . $matches[1] . $matches[2] . $matches[3]] = $uri;
                        }
                        else {
                            // TODO: maybe handle gnd as well
                        }
                    }

                    if (!empty($placeTgns)) {
                        $places = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Place')
                            ->findBy([ 'tgn' => array_keys($placeTgns) ])
                            ;

                        foreach ($places as $place) {
                            if ($place->getStatus() >= 0) {
                                $uri = $placeTgns[$place->getTgn()];
                                $details = [
                                    'url' => $this->generateUrl('place-by-tgn', [
                                        'tgn' => $place->getTgn()
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }

                    if (!empty($placeGeo)) {
                        $geos = [];

                        foreach ($placeGeo as $uriNormalized => $uriOriginal) {
                            $coords = explode(',', $latLong = str_replace('geo:', '', $uriNormalized));
                            $details = [
                                'url' => $uriNormalized,
                                'latLong' => [ (double)$coords[0], (double)$coords[1] ],
                            ];
                            $entitiesByType[$type][$uriOriginal] += $details;

                            $geos[] = $latLong;
                        }

                        // override the urls of thse entries that link to a Landmark
                        $landmarks = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Landmark')
                            ->findBy([
                                'geo' => $geos,
                                'status' => [ 0, 1 ],
                            ])
                            ;

                        foreach ($landmarks as $landmark) {
                            if ($landmark->getStatus() >= 0) {
                                $uri = 'geo:' . $landmark->getGeo();
                                $entitiesByType[$type][$uri]['url'] = $this->generateUrl('landmark', [
                                    'id' => $landmark->getId(),
                                ]);
                            }
                        }
                    }
                    break;

                case 'organization':
                    $organizationGnds = [];
                    foreach ($uriCount as $uri => $count) {
                        if (preg_match('/^'
                                       . preg_quote('http://d-nb.info/gnd/', '/')
                                       . '(\d+[\-]?[\dxX]?)$/', $uri, $matches))
                        {
                            $organizationGnds[$matches[1]] = $uri;
                        }
                    }

                    if (!empty($organizationGnds)) {
                        $organizations = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Organization')
                            ->findBy([ 'gnd' => array_keys($organizationGnds) ])
                            ;

                        foreach ($organizations as $organization) {
                            if ($organization->getStatus() >= 0) {
                                $uri = $organizationGnds[$organization->getGnd()];
                                $details = [
                                    'url' => $this->generateUrl('organization-by-gnd', [
                                        'gnd' => $organization->getGnd(),
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }
                    break;

                case 'date':
                    $dateGnds = [];
                    foreach ($uriCount as $uri => $count) {
                        if (preg_match('/^'
                                       . preg_quote('http://d-nb.info/gnd/', '/')
                                       . '(\d+[\-]?[\dxX]?)$/', $uri, $matches))
                        {
                            $dateGnds[$matches[1]] = $uri;
                        }
                    }

                    if (!empty($dateGnds)) {
                        $events = $entityManager
                            ->getRepository('\TeiEditionBundle\Entity\Event')
                            ->findBy([ 'gnd' => array_keys($dateGnds) ])
                            ;

                        foreach ($events as $event) {
                            if ($event->getStatus() >= 0 && !is_null($event->getStartDate())) {
                                $uri = $dateGnds[$event->getGnd()];
                                $details = [
                                    'url' => $this->generateUrl('event-by-gnd', [
                                        'gnd' => $event->getGnd(),
                                    ]),
                                ];
                                $entitiesByType[$type][$uri] += $details;
                            }
                        }
                    }
                    break;
            }
        }

        return $entitiesByType;
    }

    /**
     * lookup marked-up glossary terms
     */
    protected function buildGlossaryLookup(EntityManagerInterface $entityManager,
                                           $glossaryTerms, $locale)
    {
        $glossaryLookup = [];

        if (empty($glossaryTerms)) {
            return $glossaryLookup;
        }

        $language = \TeiEditionBundle\Utils\Iso639::code1to3($locale);

        $that = $this;

        $slugs = array_map(
            function ($term) use ($that) {
                return $that->slugify($term);
            },
            $glossaryTerms);

        $termsBySlug = [];

        // lookup matching terms by slug
        foreach ($entityManager
                ->getRepository('\TeiEditionBundle\Entity\GlossaryTerm')
                ->findBy([
                   'status' => [ 0, 1 ],
                   'language' => $language,
                   'slug' => $slugs,
                ]) as $term)
        {
            $termsBySlug[$term->getSlug()] = $term;
        }

        foreach ($glossaryTerms as $glossaryTerm) {
            $slug = $this->slugify($glossaryTerm);
            if (array_key_exists($slug, $termsBySlug)) {
                $term = $termsBySlug[$slug];
                $headline = $term->getHeadline();
                $headline = str_replace(']]', '', $headline);
                $headline = str_replace('[[', '→', $headline);
                $glossaryLookup[$glossaryTerm] = [
                    'slug' => $term->getSlug(),
                    'name' => $term->getName(),
                    'headline' => $headline,
                ];
            }
        }

        return $glossaryLookup;
    }

    /**
     * prepend $baseUrl to relative media-src
     */
    protected function adjustMedia($html, $baseUrl, $imgClass = 'image-responsive')
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent('<body>' . $html . '</body>'); // wrap since $html can be fragment

        $crawler->filter('audio > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        // for https://github.com/iainhouston/bootstrap3_player
        $crawler->filter('audio')->each(function ($node, $i) use ($baseUrl) {
            $poster = $node->attr('data-info-album-art');
            if (!is_null($poster)) {
                $node->getNode(0)->setAttribute('data-info-album-art', $baseUrl . '/' . $poster);
            }
        });

        $crawler->filter('video > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        $crawler->filter('video')->each(function ($node, $i) use ($baseUrl) {
            $attrValue = $node->attr('poster');
            if (!is_null($attrValue)) {
                $node->getNode(0)->setAttribute('poster', $baseUrl . '/' . $attrValue);
            }
        });

        $crawler->filter('object')->each(function ($node, $i) use ($baseUrl) {
            $attrValue = $node->attr('data');
            if (!is_null($attrValue)) {
                $node->getNode(0)->setAttribute('data', $baseUrl . '/' . $attrValue);
            }
        });

        $crawler->filter('img')->each(function ($node, $i) use ($baseUrl, $imgClass) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
            if (!empty($imgClass)) {
                $node->getNode(0)->setAttribute('class', $imgClass);
            }
        });

        return preg_replace('/<\/?body>/', '', $crawler->html());
    }

    /**
     * Use PdfGenerator to transform HTML into PDF
     */
    protected function renderPdf($html, $filename = '', $dest = 'I')
    {
        /*
        // for debugging
        echo $html;
        exit;
        */

        $fnameLogo = $this->getGlobal('public_dir') . '/img/icon/icons_wide.png';
        $this->pdfGenerator->imageVars['logo_top'] = file_get_contents($fnameLogo);

        // silence due to https://github.com/mpdf/mpdf/issues/302 when using tables
        @$this->pdfGenerator->writeHTML($html);

        $this->pdfGenerator->Output($filename, 'I');
    }

    /**
     * Adjust internal links
     */
    protected function adjustRefs($html, $refs,
                                  EntityManagerInterface $entityManager,
                                  TranslatorInterface $translator, $language)
    {
        if (empty($refs)) {
            // nothing to do
            return $html;
        }

        $refLookup = $this->buildRefLookup($refs, $entityManager, $translator, $language);

        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent('<body>' . $html . '</body>');

        $crawler->filterXPath("//a[@class='external']")
            ->each(function ($crawler) use ($refLookup) {
                foreach ($crawler as $node) {
                    $href = $node->getAttribute('href');

                    if (preg_match('/^(jgo:(article|source)\-(\d+))(\#.+)?$/', $href, $matches)) {
                        $hrefBase = $matches[1]; // $href without anchor
                        $anchor = count($matches) > 4 ? $matches[4] : '';
                        if (array_key_exists($hrefBase, $refLookup)) {
                            $info = $refLookup[$hrefBase];
                            $node->setAttribute('href', $refLookup[$hrefBase]['href'] . $anchor);
                            if (!empty($info['headline'])) {
                                $node->setAttribute('title', $refLookup[$hrefBase]['headline']);
                                $node->setAttribute('class', 'setTooltip');
                            }
                        }
                        else {
                            $node->removeAttribute('href');
                            $node->setAttribute('class', 'externalDisabled');
                        }
                    }
                }
        });

        return preg_replace('/<\/?body>/', '', $crawler->html());
    }

    /**
     * Custom method since $node->text() returns node-content as well
     */
    private function extractText($node)
    {
        $html = $node->html();
        if (!preg_match('/</', $html)) {
            return $node->text();
        }

        return $this->removeByCssSelector('<body>' . $html . '</body>',
                                          [ 'span.editorial', 'a.editorial-marker' ],
                                          true);
    }

    /**
     * Use DomCrawler to extract specific parts from the HTML-representation
     */
    protected function extractPartsFromHtml(string $html,
                                            EntityManagerInterface $entityManager,
                                            TranslatorInterface $translator)
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        // headers for TOC
        $sectionHeaders = $crawler->filterXPath('//h2')->each(function ($node, $i) {
            return [ 'id' => $node->attr('id'), 'text' => $this->extractText($node) ];
        });

        // authors
        $authors = $crawler->filterXPath("//ul[@id='authors']/li")->each(function ($node, $i) {
            $author = [ 'text' => $node->text() ];

            $slug = $node->attr('data-author-slug');
            if (!empty($slug)) {
                $author['slug'] = $slug;
            }

            return $author;
        });

        // license
        $license = null;
        $node = $crawler
            ->filterXpath("//div[@id='license']");
        if (count($node) > 0) {
            $license = [
                'text' => trim($node->text()),
                'url' => $node->attr('data-target'),
            ];
        }

        // entities
        $entities = $crawler->filterXPath("//span[@class='entity-ref']")->each(function ($node, $i) {
            $entity = [];

            $type = $node->attr('data-type');
            if (!empty($type)) {
                $entity['type'] = $type;
            }

            $uri = $node->attr('data-uri');
            if (!empty($uri)) {
                $entity['uri'] = $uri;
            }

            return $entity;
        });

        // bibitem
        $bibitems = array_filter(array_unique($crawler->filterXPath("//span[@class='dta-bibl']")->each(function ($node, $i) {
            return trim($node->attr('data-corresp'));
        })));

        $bibitemsByCorresp = [];
        if (!empty($bibitems)) {
            foreach ($bibitems as $corresp) {
                $bibitemsMap[$corresp] = \TeiEditionBundle\Entity\Bibitem::slugifyCorresp($this->getSlugify(), $corresp);
            }

            $query = $entityManager
                ->createQuery('SELECT b.slug'
                              . ' FROM \TeiEditionBundle\Entity\Bibitem b'
                              . ' WHERE b.slug IN (:slugs) AND b.status >= 0')
                ->setParameter('slugs', array_values($bibitemsMap))
                ;

            foreach ($query->getResult() as $bibitem) {
                $corresps = array_keys($bibitemsMap, $bibitem['slug']);
                foreach ($corresps as $corresp) {
                    $bibitemsByCorresp[$corresp] = $bibitem;
                }
            }
        }

        //  glossary terms
        $glossaryTerms = array_unique($crawler->filterXPath("//span[@class='glossary']")->each(function ($node, $i) {
            return $node->attr('data-title');
        }));

        // refs to other articles in the format jgo:article-123 or jgo:source-123#anchor
        $refs = array_unique($crawler->filterXPath("//a[@class='external']")->each(function ($node, $i) {
            $href = $node->attr('href');
            if (preg_match('/^jgo:(article|source)\-(\d+)(\#.+)?$/', $node->attr('href'))) {
                return $node->attr('href');
            }
        }));

        // try to get bios in the current locale
        $locale = $translator->getLocale();
        $authorSlugs = [];
        $authorsBySlug = [];
        foreach ($authors as $author) {
            if (array_key_exists('slug', $author)) {
                $authorSlugs[] = $author['slug'];
                $authorsBySlug[$author['slug']] = $author;
            }
            else {
                $authorsBySlug[] = $author;
            }
        }

        if (!empty($authorSlugs)) {
            $query = $entityManager
                ->createQuery('SELECT p.slug, p.description, p.gender'
                              . ' FROM \TeiEditionBundle\Entity\Person p'
                              . ' WHERE p.slug IN (:slugs)')
                ->setParameter('slugs', $authorSlugs);

            foreach ($query->getResult() as $person) {
                $authorsBySlug[$person['slug']]['gender'] = $person['gender'];
                if (!is_null($person['description']) && array_key_exists($locale, $person['description'])) {
                    $authorsBySlug[$person['slug']]['description'] = $person['description'][$locale];
                }
            }
        }

        return [
            $authorsBySlug,
            $sectionHeaders,
            $license,
            $entities,
            $bibitemsByCorresp,
            $glossaryTerms,
            $refs,
        ];
    }
}
