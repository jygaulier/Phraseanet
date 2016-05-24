<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus;

class Term implements TermInterface
{
    private $value;
    private $context;

    public function __construct($value, $context = null)
    {
        $this->value   = $value;
        $this->context = $context;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function hasContext()
    {
        return $this->context !== null;
    }

    public function getContext()
    {
        return $this->context;
    }

    public function __toString()
    {
        return self::dump($this);
    }

    public static function parse($string)
    {
        $c = null;
        if(($p=strpos($t=$string, '(')) !== false) {
            $t = substr($string, 0, $p);
            if(($p=strpos($c = substr($string, $p+1), ')')) !== false) {
                $c = substr($c, 0, $p);
            }
        }

        return new self(trim($t), $c===null ? null : trim($c));
    }

    public static function dump(TermInterface $term)
    {
        if ($term->hasContext()) {
            return sprintf('"%s" context:"%s"', $term->getValue(), $term->getContext());
        }

        return sprintf('"%s"', $term->getValue());
    }
}
