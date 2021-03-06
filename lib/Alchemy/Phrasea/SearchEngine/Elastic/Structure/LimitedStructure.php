<?php

namespace Alchemy\Phrasea\SearchEngine\Elastic\Structure;

use Alchemy\Phrasea\SearchEngine\Elastic\Mapping;
use Alchemy\Phrasea\SearchEngine\SearchEngineOptions;
use DomainException;

/**
 * Proxy structure request to underlying structure and filter results according
 * to user rights (handled in search options object).
 *
 * Private fields without access allowed in any collection are implicitely
 * removed from structure responses.
 *
 * @todo Strip unrestricted fields used only by disallowed collections.
 */
final class LimitedStructure implements Structure
{
    private $structure;
    private $search_options;

    public function __construct(Structure $structure, SearchEngineOptions $search_options)
    {
        $this->structure = $structure;
        $this->search_options = $search_options;
    }

    public function getAllFields()
    {
        return $this->limit($this->structure->getAllFields());
    }

    public function getUnrestrictedFields()
    {
        return $this->structure->getUnrestrictedFields();
    }

    public function getPrivateFields()
    {
        return $this->limit($this->structure->getPrivateFields());
    }

    /**
     * @return Field[]
     */
    public function getFacetFields()
    {
        return $this->limit($this->structure->getFacetFields());
    }

    public function getThesaurusEnabledFields()
    {
        return $this->limit($this->structure->getThesaurusEnabledFields());
    }

    public function getDateFields()
    {
        return $this->limit($this->structure->getDateFields());
    }

    public function get($name)
    {
        $field = $this->structure->get($name);
        return $field ? $this->limitField($field) : $field;
    }

    public function typeOf($name)
    {
        return $this->structure->typeOf($name);
    }

    public function isPrivate($name)
    {
        return $this->structure->isPrivate($name);
    }

    private function limit(array $fields)
    {
        $allowed_collections = $this->allowedCollections();
        // Filter private field collections (base_id) on which access is restricted.
        $limited_fields = [];
        foreach ($fields as $name => $field) {
            if ($field->isPrivate()) {
                $field = $this->limitField($field, $allowed_collections);
                // Private fields without collections can't be ever visible, we skip them
                if (!$field->getDependantCollections()) {
                    continue;
                }
            }
            $limited_fields[$name] = $field;
        }
        return $limited_fields;
    }

    private function limitField(Field $field, array $allowed_collections = null)
    {
        if ($allowed_collections === null) {
            $allowed_collections = $this->allowedCollections();
        }

        $collections = array_values(array_intersect(
            $field->getDependantCollections(),
            $allowed_collections
        ));

        return $field->withOptions([
            'used_by_collections' => $collections
        ]);
    }

    private function allowedCollections()
    {
        // Get all collections (base_id) with allowed private field access (user rights are computed in options object)
        $allowed_collections = [];
        foreach ($this->search_options->getBusinessFieldsOn() as $collection) {
            $allowed_collections[] = $collection->get_base_id();
        }
        return $allowed_collections;
    }
}
