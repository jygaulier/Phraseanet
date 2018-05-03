<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Form\Configuration;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;


class FtpExportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('ftp-enabled', CheckboxType::class, [
            'label'        => 'Enable FTP export',
            'help_message' => 'Available in multi-export tab',
        ]);
        $builder->add('ftp-user-access', CheckboxType::class, [
            'label'        => 'Enable FTP for users',
            'help_message' => 'By default it is available for admins',
        ]);
    }

    public function getName()
    {
        return null;
    }
}
