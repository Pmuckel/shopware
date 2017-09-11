<?php declare(strict_types=1);

namespace Shopware\Search\Gateway\Resource;

use Shopware\Framework\Api2\ApiFlag\Required;
use Shopware\Framework\Api2\Field\FkField;
use Shopware\Framework\Api2\Field\IntField;
use Shopware\Framework\Api2\Field\ReferenceField;
use Shopware\Framework\Api2\Field\StringField;
use Shopware\Framework\Api2\Field\BoolField;
use Shopware\Framework\Api2\Field\DateField;
use Shopware\Framework\Api2\Field\SubresourceField;
use Shopware\Framework\Api2\Field\LongTextField;
use Shopware\Framework\Api2\Field\LongTextWithHtmlField;
use Shopware\Framework\Api2\Field\FloatField;
use Shopware\Framework\Api2\Field\TranslatedField;
use Shopware\Framework\Api2\Field\UuidField;
use Shopware\Framework\Api2\Resource\ApiResource;

class SearchTablesResource extends ApiResource
{
    public function __construct()
    {
        parent::__construct('s_search_tables');
        
        $this->fields['table'] = (new StringField('table'))->setFlags(new Required());
        $this->fields['referenzTable'] = new StringField('referenz_table');
        $this->fields['foreignKey'] = new StringField('foreign_key');
        $this->fields['where'] = new StringField('where');
    }
    
    public function getWriteOrder(): array
    {
        return [
            \Shopware\Search\Gateway\Resource\SearchTablesResource::class
        ];
    }
}