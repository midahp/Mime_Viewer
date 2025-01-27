<?php
/**
 * The Horde_Mime_Viewer_Ooo class renders out OpenOffice.org documents in
 * HTML format.
 *
 * Copyright 2003-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author   Marko Djukic <marko@oblo.com>
 * @author   Jan Schneider <jan@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package  Mime_Viewer
 */
class Horde_Mime_Viewer_Ooo extends Horde_Mime_Viewer_Base
{
    /**
     * This driver's display capabilities.
     *
     * @var array
     */
    protected $_capability = array(
        'full' => true,
        'info' => false,
        'inline' => false,
        'raw' => false
    );

    /**
     * Metadata for the current viewer/data.
     *
     * @var array
     */
    protected $_metadata = array(
        /* At this point assume that the document takes advantage of ZIP
         * compression. */
        'compressed' => true,
        'embedded' => false,
        'forceinline' => false
    );

    /**
     * Constructor.
     *
     * @param Horde_Mime_Part $mime_part  The object with the data to be
     *                                    rendered.
     * @param array $conf                 Configuration:
     *   - 'zip': (Horde_Compress_Zip) A zip object.
     *   - 'temp_dir': (string) Where to create a temporary directory.
     */
    public function __construct(Horde_Mime_Part $part, array $conf = array())
    {
        parent::__construct($part, $conf);
    }

    /**
     * Return the full rendered version of the Horde_Mime_Part object.
     *
     * @return array  See parent::render().
     * @throws Horde_Exception
     */
    protected function _render()
    {
        $has_xsl = Horde_Util::extensionExists('xsl');
        if ($has_xsl) {
            $tmpdir = Horde_Util::createTempDir(true, $this->getConfigParam('temp_dir')) . '/';
        }

        $fnames = array('content.xml', 'styles.xml', 'meta.xml');
        $tags = array(
            'text:p' => 'p',
            'table:table' => 'table border="0" cellspacing="1" cellpadding="0" ',
            'table:table-row' => 'tr bgcolor="#cccccc"',
            'table:table-cell' => 'td',
            'table:number-columns-spanned=' => 'colspan='
        );

        if (!$this->getConfigParam('zip')) {
            $this->setConfigParam('zip', Horde_Compress::factory('Zip'));
        }
        $list = $this->getConfigParam('zip')
            ->decompress($this->_mimepart->getContents(),
                         array('action' => Horde_Compress_Zip::ZIP_LIST));

        foreach ($list as $key => $file) {
            if (in_array($file['name'], $fnames)) {
                $content = $this->getConfigParam('zip')
                    ->decompress($this->_mimepart->getContents(), array(
                        'action' => Horde_Compress_Zip::ZIP_DATA,
                        'info' => $list,
                        'key' => $key
                    ));
                if ($has_xsl) {
                    file_put_contents($tmpdir . $file['name'], $content);
                } elseif ($file['name'] == 'content.xml') {
                    return array(
                        $this->_mimepart->getMimeId() => array(
                            'data' => str_replace(array_keys($tags), array_values($tags), $content),
                            'status' => array(),
                            'type' => 'text/html; charset=UTF-8'
                        )
                    );
                }
            }
        }

        if (!$has_xsl) {
            return array();
        }

        $xslt = new XSLTProcessor();
        $xsl = new DOMDocument();
        $xsl->load(realpath(__DIR__ . '/Ooo/export/xhtml/opendoc2xhtml.xsl'));
        $xslt->importStylesheet($xsl);
        $xslt->setParameter('http://www.w3.org/1999/XSL/Transform', array(
            'metaFileURL' => 'file://' . $tmpdir . 'meta.xml',
            'stylesFileURL' => 'file://' . $tmpdir . 'styles.xml',
            'java' => false,
        ));
        $xml = new DOMDocument();
        $xml->load(realpath($tmpdir . 'content.xml'));
        $result = $xslt->transformToXml($xml);
        if ($result) {
            $result = Horde_Text_Filter::filter($result, 'xss');
        } else {
            $result = libxml_get_last_error()->message;
        }

        return array(
            $this->_mimepart->getMimeId() => array(
                'data' => $result,
                'status' => array(),
                'type' => 'text/html; charset=UTF-8'
            )
        );
    }

}
