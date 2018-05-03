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


class ModulesFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('thesaurus', CheckboxType::class, [
            'label'        => 'Enable thesaurus',
        ]);
        $builder->add('stories', CheckboxType::class, [
            'label'        => 'Enable multi-doc mode',
        ]);
        $builder->add('doc-substitution', CheckboxType::class, [
            'label'        => 'Enable HD substitution',
        ]);
        $builder->add('thumb-substitution', CheckboxType::class, [
            'label'        => 'Enable thumbnail substitution',
        ]);
        $builder->add('anonymous-report', CheckboxType::class, [
            'label'        => 'Anonymous report',
            'help_message' => 'Hide information about users',
        ]);
    }

    public function getName()
    {
        return null;
    }
}
