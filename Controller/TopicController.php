<?php
// src/Controller/TopicController.php

namespace TeiEditionBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

use Doctrine\ORM\EntityManagerInterface;

/**
 *
 */
class TopicController
extends RenderTeiController
{
    /* TODO: inject these topics */
    static $TOPICS = [
        'Demographics and Social Structure',
        'Education and Learning',
        'Family and Everyday Life',
        'Leisure and Sports',
        'Memory and Remembrance',
        'Antisemitism and Persecution',
        'Arts and Culture',
        'Migration',
        'Organizations and Institutions',
        'Law and Politics',
        'Religion and Identity',
        'Sephardic Jews',
        'Social Issues and Welfare',
        'Economy and Occupational Composition',
        'Scholarship',
    ];

    public static function lookupLocalizedTopic($topic, $translator, $locale)
    {
        if ('en' == $locale) {
            // no lookup needed
            return $topic;
        }

        // we need to get from german to english term
        $localeTranslator = $translator->getLocale();
        if ($localeTranslator != $locale) {
            $translator->setLocale($locale);
        }

        foreach (self::$TOPICS as $label) {
            if (/** @Ignore */ $translator->trans($label) == $topic) {
                $topic = $label;
                break;
            }
        }

        if ($localeTranslator != $locale) {
            $translator->setLocale($localeTranslator);
        }

        return $topic;
    }

    protected function buildTopicsBySlug(TranslatorInterface $translator, $translateKeys = false)
    {
        $topics = [];
        foreach (self::$TOPICS as $label) {
            /** @Ignore */
            $labelTranslated = $translator->trans($label);
            $key = $this->slugify($translateKeys ? $labelTranslated : $label);
            $topics[$key] = $labelTranslated;
        }

        return $topics;
    }

    protected function buildTopicsDescriptions(TranslatorInterface $translator, $locale)
    {
        $fnameAppend = !empty($locale) ? '.' . $locale : '';

        $topics = $this->buildTopicsBySlug($translator);
        asort($topics);

        $topicsDescription = [];
        foreach ($topics as $slug => $label) {
            $topicsDescription[$slug] = [ 'label' => $label ];
            $articleSlug =  $this->slugify($label);
            $articlePath = $this->locateTeiResource($articleSlug . $fnameAppend . '.xml');
            if (false !== $articlePath) {
                $topicsDescription[$slug]['article'] = $articleSlug;
            }
        }

        return $topicsDescription;
    }

    /**
     * @Route("/topic", name="topic-index")
     */
    public function indexAction(Request $request,
                                EntityManagerInterface $entityManager,
                                TranslatorInterface $translator)
    {
        return $this->render('@TeiEdition/Topic/index.html.twig', [
            'pageTitle' => $translator->trans('Topics'),
            'topics' => $this->buildTopicsDescriptions($translator, $request->getLocale()),
        ]);
    }

    /**
     * @Route("/topic/{slug}.jsonld", name="topic-background-jsonld")
     * @Route("/topic/{slug}.pdf", name="topic-background-pdf")
     * @Route("/topic/{slug}", name="topic-background")
     */
    public function backgroundAction(Request $request,
                                     EntityManagerInterface $entityManager,
                                     TranslatorInterface $translator,
                                     $slug)
    {
        $language = null;
        $locale = $request->getLocale();
        if (!empty($locale)) {
            $language = \TeiEditionBundle\Utils\Iso639::code1to3($locale);
        }
        $fnameAppend = !empty($locale) ? '.' . $locale : '';

        $topics = $this->buildTopicsBySlug($translator, true);
        if (!array_key_exists($slug, $topics)) {
            return $this->redirectToRoute('topic-index');
        }

        $generatePrintView = 'topic-background-pdf' == $request->get('_route');
        $fname = $slug . $fnameAppend . '.xml';
        $path = '';

        $criteria = [
            'slug' => $slug,
            'language' => \TeiEditionBundle\Utils\Iso639::code1to3($locale),
        ];

        $article = $entityManager
                ->getRepository('\TeiEditionBundle\Entity\Article')
                ->findOneBy($criteria)
                ;
        if (isset($article)) {
            $meta = $article;
            list($prefix, $path) = explode(':', $meta->getUid(), 2);
            if (preg_match('/\-(\d+)$/', $path, $matches)) {
                $path = preg_replace('/\-(\d+)$/', sprintf('-%05d', $matches[1]), $path);
            }
        }
        else {
            // fallback to file system
            $teiHelper = new \TeiEditionBundle\Utils\TeiHelper();
            $meta = $teiHelper->analyzeHeader($this->locateTeiResource($fname));
        }

        // TODO: Unify with ArticleController::renderArticle()
        // localize labels in xslt
        $language = null;
        $params = [];
        if ($article instanceof \TeiEditionBundle\Entity\Article) {
            $language = $article->getLanguage();
            if (!empty($language)) {
                $params['lang'] = $language;
            }
        }

        $html = $this->renderTei($fname, $generatePrintView ? 'dtabf_article-printview.xsl' : 'dtabf_article.xsl', [ 'params' => $params ]);

        list($authors, $sectionHeaders, $license, $entities, $bibitemLookup, $glossaryTerms, $refs) = $this->extractPartsFromHtml($html, $entityManager, $translator);
        $html = $this->adjustRefs($html, $refs, $entityManager, $translator, $language);

        $html = $this->adjustMedia($html,
                                   $request->getBaseURL()
                                   . '/viewer/' . $path,
                                   $generatePrintView ? '' : 'img-responsive');

        if ($generatePrintView) {
            $html = $this->removeByCssSelector('<body>' . $html . '</body>',
                                               [ 'h2 + br', 'h3 + br' ]);

            $html = $this->renderView('@TeiEdition/Article/article-printview.html.twig', [
                'name' => $topics[$slug],
                'html' => preg_replace('/<\/?body>/', '', $html),
                'authors' => $authors,
                'section_headers' => $sectionHeaders,
                'license' => $license,
            ]);

            $this->renderPdf($html, $slug . '.pdf');

            return;
        }

        $localeSwitch = [];
        if ($this->getParameter('fallback_locale') == $locale) {
            foreach ($this->getParameter('locales') as $alternateLocale) {
                if ($locale != $alternateLocale) {
                    $translator->setLocale($alternateLocale);
                    $localeSwitch[$alternateLocale] = [
                        'slug' => $this->slugify(/** @Ignore */ $translator->trans($topics[$slug])),
                    ];
                }
            }
            $translator->setLocale($locale);
        }
        else {
            // find corresponding slug for fallback_locale
            foreach (self::$TOPICS as $topicLabel) {
                if ($topics[$slug] == /** @Ignore */ $translator->trans($topicLabel)) {
                    $localeSwitch[$this->getParameter('fallback_locale')] = [ 'slug' => $this->slugify($topicLabel) ];
                    break;
                }
            }
        }

        $entityLookup = $this->buildEntityLookup($entityManager, $entities);
        $glossaryLookup = $this->buildGlossaryLookup($entityManager, $glossaryTerms, $locale);

        // sidebar
        $queryBuilder = $entityManager
                ->createQueryBuilder()
                ->select('A, S')
                ->from('\TeiEditionBundle\Entity\SourceArticle', 'S')
                ->leftJoin('S.isPartOf', 'A')
                ->where("A.status IN (1) AND A.keywords LIKE :topic AND A.articleSection <> 'background'"
                        . (!empty($language) ? ' AND A.language=:language' : ''))
                ->setParameter('topic', '%' . $topics[$slug] . '%')
                ->orderBy('S.dateCreated', 'ASC')
                ;
        if (!empty($language)) {
            $queryBuilder->setParameter('language', $language);
        }

        $articleIds = [];
        $sources = [];
        foreach ($queryBuilder->getQuery()->getResult() as $source) {
            $parentArticle = $source->getIsPartOf();
            $articleId = $parentArticle->getId();
            if (array_key_exists($articleId, $articleIds)) {
                // only use first source for multiple sources per article
                continue;
            }
            $keywords = $parentArticle->getKeywords();
            $articleIds[$articleId] = $topics[$slug] == $keywords[0];
            $sources[] = $source;
        }

        $sourcesAdditional = [];
        if (count($articleIds) > 8 && count(array_filter(array_values($articleIds))) > 4) {
            // if there are more than 8 in total and more than 4 who directly belong to this topic
            // split into primary and additional
            $sourcesPrimary = [];
            foreach ($sources as $source) {
                $parentArticle = $source->getIsPartOf();
                $articleId = $parentArticle->getId();
                if ($articleIds[$articleId]) {
                    $sourcesPrimary[] = $source;
                }
                else {
                   $sourcesAdditional[] = $source;
                }
            }
        }
        else {
            $sourcesPrimary = & $sources;
        }

        if (in_array($request->get('_route'), [ 'topic-background-jsonld' ])) {
            if (!is_null($article) && $article instanceof \TeiEditionBundle\Entity\Article) {
                return new JsonLdResponse($article->jsonLdSerialize($request->getLocale(), false, true));
            }
        }

        return $this->render('@TeiEdition/Topic/background.html.twig', [
            'article' => $article,
            'slug' => $slug,
            'name' => $topics[$slug],
            'pageTitle' => $topics[$slug], // TODO: Prepend Einfuehrung, append authors in brackets
            'html' => $html,
            'meta' => $meta,
            'authors' => $authors,
            'section_headers' => $sectionHeaders,
            'license' => $license,
            'entity_lookup' => $entityLookup,
            'bibitem_lookup' => $bibitemLookup,
            'glossary_lookup' => $glossaryLookup,
            'interpretations' => null, // $articles,
            'sources' => [ $sourcesPrimary, $sourcesAdditional ],
            'pageMeta' => [
                'jsonLd' => $article->jsonLdSerialize($request->getLocale()),
                'og' => $this->buildOg($article, $request, $entityManager, $translator, 'topic-background', [ 'slug' => $slug ]),
                'twitter' => $this->buildTwitter($article, $request, 'topic-background', [ 'slug' => $slug ]),
            ],
            'route_params_locale_switch' => $localeSwitch, // TODO: put into pageMeta
        ]);
    }
}
