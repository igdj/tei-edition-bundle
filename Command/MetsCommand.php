<?php
// src/Command/MetsCommand.php

namespace TeiEditionBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Generate METS for MyCoRe-Viewer.
 */
class MetsCommand
extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('source:mets')
            ->setDescription('Generate METS')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'TEI file'
            )
        ;
    }

    protected function registerXpathNamespaces($xml)
    {
        $xml->registerXPathNamespace('tei', 'http://www.tei-c.org/ns/1.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $fname = $input->getArgument('file');

        $fs = new Filesystem();

        if (!$fs->exists($fname)) {
            $output->writeln(sprintf('<error>%s does not exist</error>', $fname));

            return 1;
        }

        $teiHelper = new \TeiEditionBundle\Utils\TeiHelper();

        $article = $teiHelper->analyzeHeader($fname);
        if (false === $article) {
            $output->writeln(sprintf('<error>%s could not be loaded</error>', $fname));
            foreach ($teiHelper->getErrors() as $error) {
                $output->writeln(sprintf('<error>  %s</error>', trim($error->message)));
            }

            return 1;
        }

        if (empty($article->language)) {
            $output->writeln(sprintf('<error>%s is missing the language</error>', $fname));

            return 1;
        }

        /* TODO: Move from simplexml to FluentDOM */
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_file($fname);

        if (false === $xml) {
            $output->writeln(sprintf('<error>%s could not be loaded</error>', $fname));
            foreach (libxml_get_errors() as $error) {
                $output->writeln(sprintf('<error>  %s</error>', trim($error->message)));
            }

            return 1;
        }

        libxml_use_internal_errors(false);

        $this->registerXpathNamespaces($xml);

        $result = $xml->xpath('/tei:TEI/tei:text//tei:pb');
        if (empty($result)) {
            $output->writeln('<error>No pb found</error>');

            return 1;
        }

        $ID = pathinfo($fname, PATHINFO_FILENAME);

        // id isn't escaped in
        //  this._container.find("div[data-id=" + id + "] img")
        // in ThumbnailOverviewView.updateTilePosition
        //  https://github.com/MyCoRe-Org/mycore/blob/master/mycore-viewer/src/main/typescript/modules/base/widgets/thumbnail/ThumbnailOverviewView.ts#L122
        // Until this is fixed, we therefore need to replace - and . with _
        $ID = preg_replace('/[\-\.]/', '_', $ID);

        $PAGES = [];

        $facs_ref = $facs_counter = 1;
        foreach ($result as $element) {
            $facs = $element['facs'];
            if (!empty($facs) && preg_match('/(\d+)/', $facs, $matches)) {
                $facs_ref = $matches[1];
            }

            $page = [
                'counter' => $facs_counter++,
                'facs' => sprintf('f%04d', $facs_ref++),
            ];

            $n = $element['n'];
            if (!empty($n)) {
                $page['n'] = (string)$n;
            }

            $PAGES[$page['counter']] = $page;
        }

        // set the publisher - needs to be localized
        $this->translator->setLocale(\TeiEditionBundle\Utils\Iso639::code3To1($article->language));

        $FILE_GROUPS = [
            'MASTER',
            'TEI.TRANSCRIPTION', // changed in iview 2018.06 from TRANSCRIPTION
        ];

        if (!empty($article->translatedFrom) && $article->translatedFrom != $article->language) {
            if ($article->translatedFrom == 'yid') {
                $FILE_GROUPS[] = 'TEI.TRANSLATION.YL'; // yivo-transcript in latin letters
            }

            $code1 =  \TeiEditionBundle\Utils\Iso639::code3to1($article->language);
            $FILE_GROUPS[] = 'TEI.TRANSLATION.' . strtoupper($code1) ; // changed in iview 2018.06 from TRANSLATION
        }

        $LOGICAL_LABEL = $article->name;
        $LOGICAL_TYPE = 'text';
        $LOGICAL_PARTS = [];

        // get $LOGICAL_PARTS from <div n="2">..</div> if available
        $result = $xml->xpath('/tei:TEI/tei:text/tei:body/tei:div[@n="2"]');
        if (!empty($result)) {
            $order = 1;

            $pbCount = 0;

            foreach ($xml->xpath('/tei:TEI/tei:text/tei:body/tei:*') as $toplevel) {
                switch ($toplevel->getName()) {
                    case 'pb':
                        ++$pbCount;
                        break;

                    case 'div':
                        foreach ($toplevel->attributes() as $name => $value) {
                            if ('n' == $name && 2 == $value) {
                                $this->registerXpathNamespaces($toplevel);
                                $head = $toplevel->xpath('tei:*[name()="head" and position() = 1]');
                                if (1 == count($head)) {
                                    // $last_physical: count all <pb> in all previous-siblings
                                    $part = [
                                        'TYPE' => 'section',
                                        'LABEL' => (string)$head[0], // doesn't handle additional markup in <head>
                                        'ORDER' => $order++,
                                        'physical_start' => $pbCount > 0 ? $pbCount : 1,
                                    ];

                                    $LOGICAL_PARTS[] = $part;
                                }

                            }
                        }
                        // fallthrough
                    default:
                        // increase $pbCount
                        $this->registerXpathNamespaces($toplevel);
                        foreach ($toplevel->xpath('.//tei:pb') as $pb) {
                            ++$pbCount;
                        }
                }
            }
        }

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  '); // two chars

        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs('mets', 'mets', 'http://www.loc.gov/METS/');
        $xw->writeAttributeNs('xsi', 'schemaLocation',
                              'http://www.w3.org/2001/XMLSchema-instance',
                              'http://www.loc.gov/METS/ http://www.loc.gov/standards/mets/mets.xsd');

        // Descriptive Metadata
        foreach ([ 'dmd', 'amd' ] as $sec) {
            $xw->startElement('mets:' . $sec . 'Sec');
            $xw->writeAttribute('ID', $sec . '_' . $ID);
            $xw->endElement(); // </mets:${sec}Sec>
        }

        // fileSec
        $fileids_by_page = [];
        $xw->startElement('mets:fileSec');
        foreach ($FILE_GROUPS as $group) {
            $xw->startElement('mets:fileGrp');
            $xw->writeAttribute('USE', $group);
            foreach ($PAGES as $page_def) {
                $page = $page_def['counter'];
                $hrefs = [];
                if ('MASTER' == $group) {
                    $mime = 'image/jpeg';
                    $hrefs[] = sprintf('%s.jpg', $page_def['facs']);
                }
                else {
                    $mime = 'text/xml';

                    if (preg_match('/^TEI\.TRANSLATION\.(.+)/', $group, $matches)) {
                        $code1 = strtolower($matches[1]);
                        $hrefs[] = sprintf('tei/translation.%s/page-%d.xml',
                                           $code1, $page);
                    }
                    else {
                        // language of the transcription
                        if (!empty($translations) && empty($article->translatedFrom)) {
                            die('translationFrom is not set');
                        }

                        $langTranscription = !empty($article->translatedFrom)
                            ? $article->translatedFrom : $article->language;

                        $hrefs[] = sprintf('tei/transcription.%s/page-%d.xml',
                                           \TeiEditionBundle\Utils\Iso639::code3to1($langTranscription),
                                           $page);
                    }
                }

                foreach ($hrefs as $href) {
                    $id = sprintf('%s_%s', strtolower($group), md5($href));

                    if (!array_key_exists($page, $fileids_by_page)) {
                        $fileids_by_page[$page] = [];
                    }

                    if (!array_key_exists($group, $fileids_by_page[$page])) {
                        $fileids_by_page[$page][$group] = [];
                    }

                    $fileids_by_page[$page][$group][] = $id;
                    $xw->startElement('mets:file');
                    $xw->writeAttribute('ID', $id);
                    $xw->writeAttribute('MIMETYPE', $mime);

                    $xw->startElement('mets:FLocat');
                    $xw->writeAttribute('LOCTYPE', 'URL');
                    $xw->writeAttributeNs('xlink', 'href',
                                          'http://www.w3.org/1999/xlink',
                                          $href);
                    $xw->endElement(); // </mets:FLocat>

                    $xw->endElement(); // </mets:file>
                }
            }

            $xw->endElement(); // </mets:fileGrp>
        }

        $xw->endElement(); // </mets:fileSec>

        // struct maps
        foreach ([ 'PHYSICAL', 'LOGICAL' ] as $type) {
            $xw->startElement('mets:structMap');
            $xw->writeAttribute('TYPE', $type);

            if ('PHYSICAL' == $type) {
                // physSequence
                $xw->startElement('mets:div');
                $xw->writeAttribute('ID', 'phys_dmd_' . $ID);
                $xw->writeAttribute('TYPE', 'physSequence');
                foreach ($fileids_by_page as $order => $fileidsByGroup) {
                    $xw->startElement('mets:div');
                    $xw->writeAttribute('ID', 'phys_dmd_' . $ID . '_' . $order);
                    $xw->writeAttribute('TYPE', 'page');
                    $xw->writeAttribute('ORDER', $order);

                    if (array_key_exists($order, $PAGES)) {
                        if (!empty($PAGES[$order]['n'])) {
                           $xw->writeAttribute('ORDERLABEL', $PAGES[$order]['n']);
                        }
                    }

                    foreach ($fileidsByGroup as $fileids) {
                        foreach ($fileids as $fileid) {
                            $xw->startElement('mets:fptr');
                            $xw->writeAttribute('FILEID', $fileid);
                            $xw->endElement(); // </mets:fptr>
                        }
                    }

                    $xw->endElement(); // </mets:div>
                }

                $xw->endElement(); // </mets:div>
            }
            else if ('LOGICAL' == $type) {
                $xw->startElement('mets:div');
                $xw->writeAttribute('ID', 'log_' . $ID);
                $xw->writeAttribute('DMDID', 'dmd_' . $ID);
                $xw->writeAttribute('ADMIDID', 'amd_' . $ID);
                $xw->writeAttribute('TYPE', $LOGICAL_TYPE);
                $xw->writeAttribute('LABEL', $LOGICAL_LABEL);
                $xw->writeAttribute('ORDER', 1);

                $logical_by_physical_start = [];
                foreach ($LOGICAL_PARTS as $part) {
                    $logical_by_physical_start[$part['physical_start']] = $part;
                    $xw->startElement('mets:div');
                    $xw->writeAttribute('TYPE', $part['TYPE']);
                    $xw->writeAttribute('ID', 'log_' . $ID . '_' . $part['ORDER']);
                    $xw->writeAttribute('ORDER', $part['ORDER']);
                    $xw->writeAttribute('LABEL', $part['LABEL']);
                    $xw->endElement(); // </mets:div>
                }

                $xw->endElement(); // </mets:div>

                // structLink
                $xw->startElement('mets:structLink');
                $from = 'log_' . $ID; // start with root
                foreach ($PAGES as $page_def) {
                    $page = $page_def['counter'];
                    if (array_key_exists($page, $logical_by_physical_start)) {
                        $part = $logical_by_physical_start[$page];
                        $from = 'log_' . $ID . '_' . $part['ORDER'];
                    }

                    $to = sprintf('phys_dmd_%s_%s',
                                  $ID, $page);
                    $xw->startElement('mets:smLink');
                    $xw->writeAttribute('LOCTYPE', 'URL');
                    $xw->writeAttributeNs('xlink', 'from',
                                          'http://www.w3.org/1999/xlink',
                                          $from);
                    $xw->writeAttributeNs('xlink', 'to',
                                          'http://www.w3.org/1999/xlink',
                                          $to);
                    $xw->endElement(); // </mets:smlink>
                }

                $xw->endElement(); // </mets:structLink>
            }

            $xw->endElement(); // </mets:structMap>
        }

        $xw->endElement(); // </mets:mets>

        echo $xw->outputMemory(true);

        return 0;
    }
}
