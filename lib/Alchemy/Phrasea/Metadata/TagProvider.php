<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Metadata;

use PHPExiftool\Driver\TagProvider as ExiftoolTagProvider;


class TagProvider extends ExiftoolTagProvider
{
    public function __construct()
    {
        parent::__construct();

        $this['Phraseanet'] = function () {
            return [
                'PdfText'       => new Tag\PdfText(),
                'TfArchivedate' => new Tag\TfArchivedate(),
                'TfAtime'       => new Tag\TfAtime(),
                'TfBasename'    => new Tag\TfBasename(),
                'TfBits'        => new Tag\TfBits(),
                'TfChannels'    => new Tag\TfChannels(),
                'TfCtime'       => new Tag\TfCtime(),
                'TfDirname'     => new Tag\TfDirname(),
                'TfDuration'    => new Tag\TfDuration(),
                'TfEditdate'    => new Tag\TfEditdate(),
                'TfExtension'   => new Tag\TfExtension(),
                'TfFilename'    => new Tag\TfFilename(),
                'TfFilepath'    => new Tag\TfFilepath(),
                'TfHeight'      => new Tag\TfHeight(),
                'TfMimetype'    => new Tag\TfMimetype(),
                'TfMtime'       => new Tag\TfMtime(),
                'TfQuarantine'  => new Tag\TfQuarantine(),
                'TfRecordid'    => new Tag\TfRecordid(),
                'TfSize'        => new Tag\TfSize(),
                'TfWidth'       => new Tag\TfWidth(),
            ];
        };
    }

    public function getAll()
    {
        $all = parent::getAll();
        $all['Phraseanet'] = $this['Phraseanet'];

        return $all;
    }

    public function getLookupTable()
    {
        $table = parent::getLookupTable();

        $table['phraseanet'] = [
            'pdf-text'       => [
                'tagname'   => 'Pdf-Text',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\PdfText',
                'namespace' => 'Phraseanet'],
            'tf-archivedate' => [
                'tagname'   => 'Tf-Archivedate',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfArchivedate',
                'namespace' => 'Phraseanet'
            ],
            'tf-atime'       => [
                'tagname'   => 'Tf-Atime',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfAtime',
                'namespace' => 'Phraseanet'
            ],
            'tf-basename'    => [
                'tagname'   => 'Tf-Basename',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfBasename',
                'namespace' => 'Phraseanet'
            ],
            'tf-bits'        => [
                'tagname'   => 'Tf-Bits',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfBits',
                'namespace' => 'Phraseanet'
            ],
            'tf-channels'    => [
                'tagname'   => 'Tf-Channels',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfChannels',
                'namespace' => 'Phraseanet'
            ],
            'tf-ctime'      => [
                'tagname'   => 'Tf-Ctime',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfCtime',
                'namespace' => 'Phraseanet'
            ],
            'tf-dirname'     => [
                'tagname'   => 'Tf-Dirname',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfDirname',
                'namespace' => 'Phraseanet'
            ],
            'tf-duration'    => [
                'tagname'   => 'Tf-Duration',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfDuration',
                'namespace' => 'Phraseanet'
            ],
            'tf-editdate'    => [
                'tagname'   => 'Tf-Editdate',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfEditdate',
                'namespace' => 'Phraseanet'
            ],
            'tf-extension'   => [
                'tagname'   => 'Tf-Extension',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfExtension',
                'namespace' => 'Phraseanet'
            ],
            'tf-filename'    => [
                'tagname'   => 'Tf-Filename',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfFilename',
                'namespace' => 'Phraseanet'
            ],
            'tf-filepath'    => [
                'tagname'   => 'Tf-Filepath',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfFilepath',
                'namespace' => 'Phraseanet'
            ],
            'tf-height'      => [
                'tagname'   => 'Tf-Height',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfHeight',
                'namespace' => 'Phraseanet'
            ],
            'tf-mimetype'    => [
                'tagname'   => 'Tf-Mimetype',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfMimetype',
                'namespace' => 'Phraseanet'
            ],
            'tf-mtime'       => [
                'tagname'   => 'Tf-Mtime',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfMtime',
                'namespace' => 'Phraseanet'
            ],
            'tf-quarantine'  => [
                'tagname'   => 'Tf-Quarantine',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfQuarantine',
                'namespace' => 'Phraseanet'
            ],
            'tf-recordid'    => [
                'tagname'   => 'Tf-Recordid',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfRecordid',
                'namespace' => 'Phraseanet'
            ],
            'tf-size'        => [
                'tagname'   => 'Tf-Size',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfSize',
                'namespace' => 'Phraseanet'
            ],
            'tf-width'       => [
                'tagname'   => 'Tf-Width',
                'classname' => '\\Alchemy\\Phrasea\\Metadata\\Tag\\TfWidth',
                'namespace' => 'Phraseanet'
            ],
        ];

        return $table;
    }
}
