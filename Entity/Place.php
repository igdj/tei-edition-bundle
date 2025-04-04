<?php
// src/Entity/Place.php

namespace TeiEditionBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo; // alias for Gedmo extensions annotations

use FS\SolrBundle\Doctrine\Annotation as Solr;

use Symfony\Component\String\Inflector\EnglishInflector;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entities that have a somewhat fixed, physical extension.
 *
 * @see http://schema.org/Place Documentation on Schema.org
 *
 * Might actually be the more specific City / Country / State
 * extending AdministrativeArea
 *
 * @Solr\Document(indexHandler="indexHandler")
 * @Solr\SynchronizationFilter(callback="shouldBeIndexed")
 *
 * @ORM\Entity
 * @ORM\Table(name="place")
 */
class Place
extends PlaceBase
{
    static $zoomLevelByType = [
        'neighborhood' => 12,
        'city district' => 11,
        'district' => 11,
        'inhabited place' => 10,
    ];

    static $aatToType = [
        'facets (controlled vocabulary)' => 'root', // http://vocab.getty.edu/aat/300386699
        'continents' => 'continent',                // http://vocab.getty.edu/aat/300128176
        'oceans' => 'ocean',                        // http://vocab.getty.edu/aat/300008687
        'oceans (marine bodies of water)' => 'ocean',  // http://vocab.getty.edu/aat/300008687
        'seas' => 'sea',                            // http://vocab.getty.edu/aat/300008694
        'archipelagos' => 'archipelago',            // http://vocab.getty.edu/aat/300386854
        'peninsulas' => 'peninsula',                // http://vocab.getty.edu/aat/300008804
        'island groups' => 'island group',          // http://vocab.getty.edu/aat/300386853
        'islands (landforms)' => 'island',          // http://vocab.getty.edu/aat/300008791
        'gulfs (bodies of water)' => 'gulf',        // http://vocab.getty.edu/aat/300132315
        'bays (bodies of water)' => 'bay',          // http://vocab.getty.edu/aat/300132316
        'coastlines' => 'coastline',                // http://vocab.getty.edu/aat/300008734
        'capes (landforms)' => 'cape',              // https://vocab.getty.edu/aat/300008850
        'estuaries' => 'estuary',                   // http://vocab.getty.edu/aat/300266571
        'rivers' => 'river',                        // http://vocab.getty.edu/aat/300008707
        'streams' => 'stream',                      // http://vocab.getty.edu/aat/300008699
        'lakes (bodies of water)' => 'lake',        // http://vocab.getty.edu/aat/300008680
        'channels (water bodies)' => 'channel',     // http://vocab.getty.edu/aat/300008713
        'canals (waterways)' => 'canal',            // http://vocab.getty.edu/aat/300006075
        'mountains' => 'mountain',                  // http://vocab.getty.edu/aat/300008795
        'mountain ranges' => 'mountain range',      // http://vocab.getty.edu/aat/300386831
        'mountain systems' => 'mountain system',    // http://vocab.getty.edu/aat/300386832
        'hills' => 'hill',                          // http://vocab.getty.edu/aat/300008777
        'valleys (landforms)' => 'valley',          // http://vocab.getty.edu/aat/300008761
        'woods (plant communities)' => 'wood',      // http://vocab.getty.edu/aat/300157169
        'nations' => 'nation',                      // http://vocab.getty.edu/aat/300128207
        'countries (sovereign states)' => 'country',// http://vocab.getty.edu/aat/300387506
        'autonomous regions' => 'autonomous region',// http://vocab.getty.edu/aat/300387107
        'autonomous republics' => 'autonomous republic',// http://vocab.getty.edu/aat/300387110
        'dependent states' => 'dependent state',    // http://vocab.getty.edu/aat/300387176
        'occupied territories' => 'occupied territory', // http://vocab.getty.edu/aat/300387139
        'colonies' => 'colony',                     // http://vocab.getty.edu/aat/300387051
        'associations' => 'association',            // http://vocab.getty.edu/aat/300025950, e.g. Commonwealth
        'semi-independent political entities' => 'semi-independent political entity',    // http://vocab.getty.edu/aat/300387052
        'former groups of political entitites' => 'former group of political entitites',    // http://vocab.getty.edu/aat/300387354
        'former primary political entities' => 'former primary political entity',    // http://vocab.getty.edu/aat/300387356
        'former administrative divisions' => 'former administrative division',    // http://vocab.getty.edu/aat/300387179
        'states (political divisions)' => 'state',  // http://vocab.getty.edu/aat/300000776
        'departments (political divisions)' => 'department',  // http://vocab.getty.edu/aat/300000772
        'first level subdivisions (political entities)' => 'province',  // http://vocab.getty.edu/aat/300387064
        'third level subdivisions (political entities)' => 'district',  // http://vocab.getty.edu/aat/300387064
        'cantons (administrative bodies)' => 'canton', // http://vocab.getty.edu/aat/300000769
        'prefectures' => 'prefecture',              // http://vocab.getty.edu/aat/300235099
        'governorates' => 'governorate',            // http://vocab.getty.edu/aat/300235093
        'oblasts' => 'oblast',                      // http://vocab.getty.edu/aat/300235107
        'voivodeships' => 'voivodeship',            // http://vocab.getty.edu/aat/300235112
        'krays' => 'kray',                          // http://vocab.getty.edu/aat/300395501
        'provinces' => 'province',                  // http://vocab.getty.edu/aat/300000774
        'national districts' => 'national district',// http://vocab.getty.edu/aat/300387081
        'metropolitan areas' => 'metropolitan area',// http://vocab.getty.edu/aat/300132618
        'general regions' => 'general region',      // http://vocab.getty.edu/aat/300387346
        'regions (administrative divisions)' => 'region', // http://vocab.getty.edu/aat/300236112
        'regional divisions' => 'regional divisions', // http://vocab.getty.edu/aat/300387131
        'historical regions' => 'historical region', // http://vocab.getty.edu/aat/300387178
        'areas (geography)' => 'area',              // http://vocab.getty.edu/aat/300387575
        'Bezirke' => 'district',                    // http://vocab.getty.edu/aat/300387333
        'counties' => 'county',                     // http://vocab.getty.edu/aat/300000771
        'duchies' => 'duchy',                       // http://vocab.getty.edu/aat/300235088
        'autonomous cities' => 'autonomous city',   // http://vocab.getty.edu/aat/300387069
        'independent cities' => 'independent city', // http://vocab.getty.edu/aat/300387068
        'special cities' => 'special city',         // http://vocab.getty.edu/aat/300387067
        'autonomous communities' => 'autonomous community', // http://vocab.getty.edu/aat/300387113
        'municipalities' => 'municipality',         // http://vocab.getty.edu/aat/300265612
        'special municipalities' => 'special municipality', // http://vocab.getty.edu/aat/300387213
        'inhabited places' => 'inhabited place',    // http://vocab.getty.edu/aat/300008347
        'suburbs' => 'suburb',                      // http://vocab.getty.edu/aat/300000874
        'neighborhoods' => 'neighborhood',          // http://vocab.getty.edu/aat/300000745
        'urban districts' => 'urban district',      // http://vocab.getty.edu/aat/300387337
        'Ortsteile' => 'city district',             // http://vocab.getty.edu/aat/300387337
        'boroughs' => 'borough',                    // http://vocab.getty.edu/aat/300000778
        'unitary authorities' => 'unitary authority', // http://vocab.getty.edu/aat/300387071
        'deserted settlements' => 'deserted settlement', // http://vocab.getty.edu/aat/300167671
        'historic sites' => 'historic site',        // http://vocab.getty.edu/aat/300000833
        'castles (fortifications)' => 'castle',     // http://vocab.getty.edu/aat/300006891
        'miscellaneous' => 'miscellaneous',         // http://vocab.getty.edu/aat/300386698
    ];

    // Getty returns plurals for placeTypePreferred
    // e.g. continents for http://vocab.getty.edu/aat/300128176
    // we decided to store the singular form, therefore we need to map
    public static function mapAatToType($aatLabel)
    {
        if (array_key_exists($aatLabel, self::$aatToType)) {
            return self::$aatToType[$aatLabel];
        }

        return null;
    }

    public static function buildTypeLabel($type)
    {
        if ('root' == $type) {
            return '';
        }

        if ('inhabited place' == $type) {
            return 'place';
        }

        return $type;
    }

    public static function buildPluralizedTypeLabel($type, $count)
    {
        if (empty($type)) {
            return '';
        }

        $label = self::buildTypeLabel($type);
        if ($count > 1) {
            $inflector = new EnglishInflector();
            $labels = $inflector->pluralize($label);
            if (count($labels) > 0) {
                $label = $labels[0];
            }
        }

        return ucfirst($label);
    }

    /**
     * @ORM\ManyToOne(targetEntity="Place", inversedBy="children")
     * @ORM\JoinColumn(referencedColumnName="id", onDelete="CASCADE")
     */
    protected $parent;

    /**
     * @ORM\OneToMany(targetEntity="Place", mappedBy="parent")
     * @ORM\OrderBy({"type" = "ASC", "name" = "ASC"})
     */
    private $children;

    /**
     * @ORM\OneToMany(targetEntity="Article", mappedBy="contentLocation")
     */
    protected $articles;

    use ArticleReferencesTrait;

    /**
     * @ORM\OneToMany(targetEntity="ArticlePlace",
     *   mappedBy="place",
     *   cascade={"persist", "remove"},
     *   orphanRemoval=TRUE
     * )
     */
    protected $articleReferences;

    public function showCenterMarker()
    {
        $hasPlaceParent = false;
        $ancestorOrSelf = $this;
        while (!is_null($ancestorOrSelf)) {
            if (in_array($ancestorOrSelf->type, [ 'neighborhood', 'inhabited place' ])) {
                return true;
            }

            $ancestorOrSelf = $ancestorOrSelf->getParent();
        }

        return false;
    }

    public function getDefaultZoomlevel()
    {
        if (array_key_exists($this->type, self::$zoomLevelByType)) {
            return self::$zoomLevelByType[$this->type];
        }

        return 8;
    }

    public function setParent(Place $parent = null)
    {
        $this->parent = $parent;
    }

    public function getParent()
    {
        return $this->parent;
    }

    public function getChildren()
    {
        return $this->children;
    }

    public function getChildrenByType()
    {
        if (is_null($this->children)) {
            return null;
        }

        $ret = [];
        foreach ($this->children as $child) {
            $type = $child->getType();
            if (!array_key_exists($type, $ret)) {
                $ret[$type] = [];
            }
            $ret[$type][] = $child;
        }

        $typeWeights = [
            'continent' => -10,
            'nation' => 0,
            'dependent state' => 1,
            'former primary political entity' => 2,
            'state' => 3,
            'general region' => 5,
            'community' => 10,
            'historical region' => 11,
            'inhabited place' => 15,
            'archipelago' => 20,
        ];

        uksort($ret, function($typeA, $typeB) use ($typeWeights) {
            if ($typeA == $typeB) {
                return 0;
            }

            $typeOrderA = array_key_exists($typeA, $typeWeights) ? $typeWeights[$typeA] : 99;
            $typeOrderB = array_key_exists($typeB, $typeWeights) ? $typeWeights[$typeB] : 99;

            return ($typeOrderA < $typeOrderB) ? -1 : 1;
        });

        return $ret;
    }

    public function getTypeLabel()
    {
        return self::buildTypeLabel($this->type);
    }

    public function getPath()
    {
        $path = [];
        $parent = $this->getParent();
        while ($parent != null) {
            $path[] = $parent;
            $parent = $parent->getParent();
        }

        return array_reverse($path);
    }

    public function getArticles()
    {
        return $this->articles;
    }

    // solr-stuff
    public function indexHandler()
    {
        return '*';
    }

    /**
     * Index everything that isn't deleted (no explicit publishing needed)
     *
     * @return boolean
     */
    public function shouldBeIndexed()
    {
        return $this->status >= 0;
    }
}
