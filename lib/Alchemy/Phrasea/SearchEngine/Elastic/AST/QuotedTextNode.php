<?php

namespace Alchemy\Phrasea\SearchEngine\Elastic\AST;

use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryContext;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryHelper;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\Field as StructureField;

class QuotedTextNode extends Node
{
    private $text;

    public function __construct($text)
    {
        $this->text = $text;
    }

    public function buildQuery(QueryContext $context)
    {
        $query_builder = function (array $fields) use ($context) {
            $index_fields = [];
            foreach ($fields as $field) {
                foreach ($context->localizeField($field) as $f) {
                    $index_fields[] = $f;
                }
            }
            if (!$index_fields) {
                return null;
            }
            return [
                'multi_match' => [
                    'type'   => 'phrase',
                    'fields' => $index_fields,
                    'query'  => $this->text,
                    'lenient'=> true,
                ]
            ];
        };

        $unrestricted_fields = $context->getUnrestrictedFields();
        $unrestricted_fields = StructureField::filterByValueCompatibility($unrestricted_fields, $this->text);
        $query = $query_builder($unrestricted_fields);

        $private_fields = $context->getPrivateFields();
        $private_fields = StructureField::filterByValueCompatibility($private_fields, $this->text);
        foreach (QueryHelper::wrapPrivateFieldQueries($private_fields, $query_builder) as $private_field_query) {
            $query = QueryHelper::applyBooleanClause($query, 'should', $private_field_query);
        }

        return $query;
    }

    public function getTermNodes()
    {
        return [];
    }

    public function __toString()
    {
        return sprintf('<exact_text:"%s">', $this->text);
    }
}
