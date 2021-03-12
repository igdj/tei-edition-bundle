<?php
// src/TeiEditionBundle/Command/BaseCommand.php

namespace TeiEditionBundle\Command;

use Symfony\Component\Console\Command\Command;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

use Symfony\Contracts\Translation\TranslatorInterface;

use Cocur\Slugify\SlugifyInterface;

use Doctrine\ORM\EntityManagerInterface;

use LodService\LodService;
use LodService\Provider\DnbProvider;
use LodService\Provider\GettyVocabulariesProvider;
use LodService\Identifier\GndIdentifier;
use LodService\Identifier\TgnIdentifier;

use Sylius\Bundle\ThemeBundle\Context\SettableThemeContext;
use Sylius\Bundle\ThemeBundle\Repository\ThemeRepositoryInterface;

use TeiEditionBundle\Utils\ImageMagick\ImageMagickProcessor;
use TeiEditionBundle\Utils\Xsl\XsltProcessor;
use TeiEditionBundle\Utils\XmlFormatter\XmlFormatter;

/**
 * Shared Base for all Commands.
 */
abstract class BaseCommand
extends Command
{
    use \TeiEditionBundle\Utils\LocateDataTrait;

    protected $em;
    protected $kernel;
    protected $router;
    protected $translator;
    protected $slugify;
    protected $params;
    protected $themeRepository;
    protected $themeContext;
    protected $imagickProcessor;
    protected $xsltProcessor;
    protected $formatter;

    public function __construct(EntityManagerInterface $em,
                                KernelInterface $kernel,
                                RouterInterface $router,
                                TranslatorInterface $translator,
                                SlugifyInterface $slugify,
                                ParameterBagInterface $params,
                                ThemeRepositoryInterface $themeRepository,
                                SettableThemeContext $themeContext,
                                ?string $siteTheme,
                                ImageMagickProcessor $imagickProcessor,
                                XsltProcessor $xsltProcessor,
                                XmlFormatter $formatter
                            )
    {
        parent::__construct();

        $this->em = $em;
        $this->kernel = $kernel;
        $this->router = $router;
        $this->translator = $translator;
        $this->slugify = $slugify;
        $this->params = $params;
        $this->themeRepository = $themeRepository;
        $this->themeContext = $themeContext;
        $this->imagickProcessor = $imagickProcessor;
        $this->xsltProcessor = $xsltProcessor;
        $this->formatter = $formatter;

        if (!is_null($siteTheme)) {
            $theme = $this->themeRepository->findOneByName($siteTheme);
            if (!is_null($theme)) {
                $this->themeContext->setTheme($theme);
            }
        }
    }

    protected function getParameter(string $name)
    {
        if ($this->params->has($name)) {
            return $this->params->get($name);
        }
    }

    protected function jsonPrettyPrint($structure)
    {
        return json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildGndConditionbyUri($uri, $hyphenAllowed = true)
    {
        $condition = null;

        $regExp = '/^https?'
            . preg_quote('://d-nb.info/gnd/', '/')
            . ($hyphenAllowed ? '(\d+\-?[\dxX]?)' : '(\d+[xX]?)')
            . '$/';

        if (preg_match($regExp, $uri, $matches))
        {
            $condition = [ 'gnd' => $matches[1] ];
        }

        return $condition;
    }

    /**
     * Private helper to ignore SolrException
     */
    protected function flushEm($em)
    {
        try {
            $em->flush();
        }
        catch (\FS\SolrBundle\SolrException $e) {
        }
    }

    protected function buildPersonConditionByUri($uri)
    {
        $condition = $this->buildGndConditionByUri($uri, false);
        if (!empty($condition)) {
            return $condition;
        }

        if (preg_match('/^'
                       . preg_quote('http://www.dasjuedischehamburg.de/inhalt/', '/')
                       . '(.+)$/', $uri, $matches))
        {
            $condition = [ 'djh' => urldecode($matches[1]) ];
        }
        else if (preg_match('/^'
                            . preg_quote('http://www.stolpersteine-hamburg.de/', '/')
                            . '.*?BIO_ID=(\d+)/', $uri, $matches))
        {
            $condition = [ 'stolpersteine' => $matches[1] ];
        }

        return $condition;
    }

    protected function findPersonByUri($uri)
    {
        $condition = $this->buildPersonConditionByUri($uri);

        if (is_null($condition)) {
            die('Currently not handling ' . $uri);

            return -1;
        }

        return $this->em->getRepository('TeiEditionBundle\Entity\Person')
            ->findOneBy($condition);
    }

    protected function insertMissingPerson($uri)
    {
        $person = $this->findPersonByUri($uri);
        if (!is_null($person)) {
            return 0;
        }

        $person = new \TeiEditionBundle\Entity\Person();
        $condition = $this->buildPersonConditionByUri($uri);
        foreach ($condition as $field => $value) {
            switch ($field) {
                case 'gnd':
                    $lodService = new LodService(new DnbProvider());
                    $resource = $lodService->fetch(new GndIdentifier($value));

                    if (is_null($resource) || !($resource instanceof \LodService\Model\Person)) {
                        return -1;
                    }

                    $person->setGnd($value);

                    //  move properties from $resource to $person
                    foreach ([
                            'familyName',
                            'givenName',
                            'disambiguatingDescription',
                            'gender',
                            'birthDate',
                            'deathDate',
                        ] as $src)
                    {
                        $getter = 'get' . ucfirst($src);
                        $setter = 'set' . ucfirst($src);
                        $value = $resource->$getter();

                        if (!empty($value)) {
                            switch ($src) {
                                case 'gender':
                                    if ('Female' == $value) {
                                        $person->setGender('F');
                                    }
                                    else if ('Male' == $value) {
                                        $person->setGender('M');
                                    }
                                    break;

                                case 'disambiguatingDescription':
                                    $person->setDescription([ 'de' => $value ]);
                                    break;

                                default:
                                    $person->$setter($value);
                            }
                        }
                    }
                    break;

                default:
                    die('TODO: handle field ' . $field . ' for ' . $value);
            }
        }

        $this->em->persist($person);
        $this->flushEm($this->em);

        return 1;
    }

    protected function buildOrganizationConditionByUri($uri)
    {
        return $this->buildGndConditionByUri($uri);
    }

    protected function findOrganizationByUri($uri)
    {
        $condition = $this->buildOrganizationConditionByUri($uri);

        if (is_null($condition)) {
            die('Currently not handling ' . $uri);

            return -1;
        }

        return $this->em->getRepository('TeiEditionBundle\Entity\Organization')->findOneBy($condition);
    }

    protected function insertMissingOrganization($uri)
    {
        $organization = $this->findOrganizationByUri($uri);
        if (!is_null($organization)) {
            return 0;
        }

        $organization = new \TeiEditionBundle\Entity\Organization();
        $condition = $this->buildOrganizationConditionByUri($uri);
        foreach ($condition as $field => $value) {
            switch ($field) {
                case 'gnd':
                    $lodService = new LodService(new DnbProvider());
                    $resource = $lodService->fetch(new GndIdentifier($value));

                    if (is_null($resource) || !($resource instanceof \LodService\Model\Organization)) {
                        return -1;
                    }

                    $organization->setGnd($value);

                    //  move properties from $resource to $organization
                    foreach ([
                            'name',
                            'foundingDate',
                            'dissolutionDate',
                            'disambiguatingDescription',
                            'url',
                        ] as $src)
                    {
                        $getter = 'get' . ucfirst($src);
                        $setter = 'set' . ucfirst($src);
                        $value = $resource->$getter();
                        if (!empty($value)) {
                            switch ($src) {
                                case 'disambiguatingDescription':
                                    $organization->setDescription([ 'de' => $value ]);
                                    break;

                                default:
                                    $organization->$setter($value);
                            }
                        }
                    }
                    break;

                default:
                    die('TODO: handle field ' . $field);
            }
        }

        $this->em->persist($organization);
        $this->flushEm($this->em);

        return 1;
    }

    protected function buildPlaceConditionByUri($uri)
    {
        $condition = null;

        if (preg_match('/^'
                       . preg_quote('http://vocab.getty.edu/tgn/', '/')
                       . '(\d+)$/', $uri, $matches))
        {
            $condition = [ 'tgn' => $matches[1] ];
        }

        return $condition;
    }

    protected function findPlaceByUri($uri)
    {
        if (preg_match('/^geo:/', $uri)) {
            // ignore geo: $uri
            return null;
        }

        $condition = $this->buildPlaceConditionByUri($uri);

        if (is_null($condition)) {
            die('Currently not handling ' . $uri);

            return;
        }

        return $this->em->getRepository('TeiEditionBundle\Entity\Place')->findOneBy($condition);
    }

    protected function insertMissingPlace($uri, $additional = [])
    {
        if (preg_match('/^geo:/', $uri)) {
            // ignore geo: $uri
            return 0;
        }

        $entity = $this->findPlaceByUri($uri);
        if (!is_null($entity)) {
            // set gnd if given and not already set
            if (!empty($additional['gnd'])
                && preg_match('/^https?'
                              . preg_quote('://d-nb.info/gnd/', '/')
                              . '(\d+[\-]?[\dxX]?)$/', $additional['gnd'], $matches))
            {
                $gnd = $entity->getGnd();
                if (empty($gnd)) {
                    $entity->setGnd($matches[1]);
                    $this->em->persist($entity);
                    $this->flushEm($this->em);
                }
            }

            return 0;
        }

        $entity = new \TeiEditionBundle\Entity\Place();
        $condition = $this->buildPlaceConditionByUri($uri);
        foreach ($condition as $prefix => $value) {
            switch ($prefix) {
                case 'tgn':
                    var_dump($prefix . ':' . $value);

                    $lodService = new LodService(new GettyVocabulariesProvider());
                    $resource = $lodService->fetch(new TgnIdentifier($value));

                    if (is_null($resource) || !($resource instanceof \LodService\Model\Place)) {
                        return -1;
                    }

                    if (empty($resource->getName())) {
                        var_dump($resource);
                        die($tgn);
                    }

                    $entity->setTgn($value);

                    $parent = null;

                    $containedInPlace = $resource->getContainedInPlace();
                    if (!is_null($containedInPlace)) {
                        $tgnParent = $containedInPlace->getIdentifier('tgn');
                        if (is_null($tgnParent)) {
                            var_dump($tgnParent);
                            die('No tgn identifier found');
                        }

                        $parent = $this->em->getRepository('TeiEditionBundle\Entity\Place')->findOneBy([
                            'tgn' => $tgnParent->getValue(),
                        ]);

                        if (is_null($parent)) {
                            $res = $this->insertMissingPlace($tgnParent->toUri());
                            if ($res >= 0) {
                                $parent = $this->em->getRepository('TeiEditionBundle\Entity\Place')->findOneBy([ 'tgn' => $geo->tgnParent ]);
                            }
                        }
                    }

                    if (isset($parent)) {
                        $entity->setParent($parent);
                    }

                    if (!empty($additional['gnd'])) {
                        $uri = $additional['gnd'];
                        if (preg_match('/^https?'
                                       . preg_quote('://d-nb.info/gnd/', '/')
                                       . '(\d+[\-]?[\dxX]?)$/', $uri, $matches))
                        {
                            $entity->setGnd($matches[1]);
                        }
                    }

                    //  move properties from $resource to $entity
                    foreach ([
                            'name', 'alternateName',
                            'geo',
                            'additionalType',
                        ] as $src)
                    {
                        $getter = 'get' . ucfirst($src);
                        $setter = 'set' . ucfirst($src);
                        $value = $resource->$getter();

                        if (!empty($value)) {
                            switch ($src) {
                                case 'additionalType':
                                    $type = \TeiEditionBundle\Entity\Place::mapAatToType($value);
                                    if (is_null($type)) {
                                        die('No aatToType registered for ' . $value);
                                    }
                                    $entity->setType($type);
                                    break;

                                case 'geo':
                                    $entity->setGeo($value->getLatLong());
                                    $entity->setCountryCode($value->getAddressCountry());
                                    break;

                                default:
                                    $entity->$setter($value);
                            }
                        }
                    }
                    break;

                default:
                    die('TODO: handle field ' . $field);
            }
        }

        $this->em->persist($entity);
        $this->flushEm($this->em);

        return 1;
    }

    protected function buildLandmarkConditionByUri($uri)
    {
        if (preg_match('/^geo:(.*)/', $uri, $matches)) {
            return [
                'geo' => $matches[1],
            ];
        }
    }

    protected function findLandmarkByUri($uri)
    {
        if (!preg_match('/^geo:/', $uri)) {
            // ignore all non-geo: $uri
            return null;
        }

        $condition = $this->buildLandmarkConditionByUri($uri);

        if (is_null($condition)) {
            die('Currently not handling ' . $uri);

            return;
        }

        return $this->em->getRepository('TeiEditionBundle\Entity\Landmark')->findOneBy($condition);
    }

    protected function buildEventConditionByUri($uri)
    {
        return $this->buildGndConditionByUri($uri);
    }

    protected function findEventByUri($uri)
    {
        $condition = $this->buildEventConditionByUri($uri);

        if (is_null($condition)) {
            die('Currently not handling ' . $uri);

            return;
        }

        return $this->em->getRepository('TeiEditionBundle\Entity\Event')->findOneBy($condition);
    }

    protected function insertMissingEvent($uri, $additional = [])
    {
        $entity = $this->findEventByUri($uri);
        if (!is_null($entity)) {
            return 0;
        }

        $entity = new \TeiEditionBundle\Entity\Event();
        $condition = $this->buildEventConditionByUri($uri);
        foreach ($condition as $prefix => $value) {
            switch ($prefix) {
                case 'gnd':
                    $lodService = new LodService(new DnbProvider());
                    $resource = $lodService->fetch(new GndIdentifier($value));

                    if (is_null($resource) || !($resource instanceof \LodService\Model\DefinedTerm)) {
                        return -1;
                    }

                    $entity->setGnd($value);

                    //  move properties from $resource to $entity
                    foreach ([
                            'name',
                            'disambiguatingDescription',
                            'startDate',
                            'endDate',
                        ] as $src)
                    {
                        $getter = 'get' . ucfirst($src);
                        $setter = 'set' . ucfirst($src);
                        $value = $resource->$getter();

                        if (!empty($value)) {
                            switch ($src) {
                                case 'disambiguatingDescription':
                                    $entity->setDescription([ 'de' => $value ]);
                                    break;

                                default:
                                    $entity->$setter($value);
                            }
                        }
                    }
                    break;

                default:
                    die('TODO: handle field ' . $field);
            }
        }

        $this->em->persist($entity);
        $this->flushEm($this->em);

        return 1;
    }
}
