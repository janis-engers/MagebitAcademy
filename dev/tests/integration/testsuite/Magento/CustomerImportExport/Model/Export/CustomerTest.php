<?php
/**
 * Copyright 2020 Adobe
 * All Rights Reserved.
 */

namespace Magento\CustomerImportExport\Model\Export;

use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Customer\Model\Attribute;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Import;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\ImportExport\Model\Export\Adapter\Csv;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Customer\Model\ResourceModel\Attribute\Collection;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Tests for customer export model.
 *
 * @magentoAppArea adminhtml
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Customer
     */
    protected $_model;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array
     */
    private $attributeValues;

    /**
     * @var array
     */
    private $attributeTypes;

    /**
     * @var Collection
     */
    private $attributeCollection;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->_model = $this->objectManager->create(Customer::class);
        $this->attributeCollection = $this->objectManager->create(Collection::class);
        $this->localeDate = $this->objectManager->create(TimezoneInterface::class);
    }

    /**
     * Export "Customer Main File".
     *
     * @magentoDataFixture Magento/Customer/_files/import_export/customers.php
     * @return void
     */
    public function testExport()
    {
        $this->processCustomerAttribute();
        $expectedAttributes = $this->getExpectedAttributes();
        $lines = $this->export($expectedAttributes);
        $this->checkExportData($lines, $expectedAttributes);
    }

    /**
     * Export with Multi Websites "Customer Main File".
     *
     * @magentoDataFixture Magento/Customer/_files/import_export/customers_with_websites.php
     * @return void
     */
    public function testExportWithMultiWebsites(): void
    {
        $this->processCustomerAttribute();
        $expectedAttributes = $this->getExpectedAttributes();
        $lines = $this->export($expectedAttributes);
        $this->checkExportData($lines, $expectedAttributes);
    }

    /**
     * Return attributes which should be exported.
     *
     * @return array
     */
    private function getExpectedAttributes(): array
    {
        $expectedAttributes = [];
        /** @var Attribute $attribute */
        foreach ($this->attributeCollection as $attribute) {
            $expectedAttributes[] = $attribute->getAttributeCode();
        }

        return array_diff($expectedAttributes, $this->_model->getDisabledAttributes());
    }

    /**
     * Prepare Customer attribute.
     *
     * @return void
     */
    private function processCustomerAttribute(): void
    {
        $this->initAttributeValues($this->attributeCollection);
        $this->initAttributeTypes($this->attributeCollection);
    }

    /**
     * Export customer.
     *
     * @param array $expectedAttributes
     * @return array
     */
    private function export(array $expectedAttributes): array
    {
        $this->_model->setWriter($this->objectManager->create(Csv::class));
        $data = $this->_model->export();

        $this->assertNotEmpty($data);

        $lines = $this->_csvToArray($data, 'email');
        $this->assertEquals(
            count($expectedAttributes),
            count(array_intersect($expectedAttributes, $lines['header'])),
            'Expected attribute codes were not exported.'
        );

        $this->assertNotEmpty($lines['data'], 'No data was exported.');

        return $lines;
    }

    /**
     * Check that exported data is correct.
     *
     * @param array $lines
     * @param array $expectedAttributes
     * @return void
     */
    private function checkExportData(array $lines, array $expectedAttributes): void
    {
        /** @var CustomerModel[] $customers */
        $customers = $this->objectManager->create(CustomerCollection::class);
        foreach ($customers as $customer) {
            $data = $this->processCustomerData($customer, $expectedAttributes);

            $data['created_at'] = $this->localeDate
                ->scopeDate(null, $data['created_at'], true)
                ->format('Y-m-d H:i:s');

            $data['updated_at'] = $this->localeDate
                ->scopeDate(null, $data['updated_at'], true)
                ->format('Y-m-d H:i:s');

            $exportData = $lines['data'][$data['email']];
            $exportData = $this->unsetDuplicateData($exportData);

            foreach ($data as $key => $value) {
                $this->assertEquals($value, $exportData[$key], "Attribute '{$key}' is not equal.");
            }
        }
    }

    /**
     * Initialize attribute option values.
     *
     * @param Collection $attributeCollection
     * @return CustomerTest
     */
    private function initAttributeValues(Collection $attributeCollection): CustomerTest
    {
        /** @var Attribute $attribute */
        foreach ($attributeCollection as $attribute) {
            $this->attributeValues[$attribute->getAttributeCode()] = $this->_model->getAttributeOptions($attribute);
        }

        return $this;
    }

    /**
     * Initialize attribute types.
     *
     * @param \Magento\Customer\Model\ResourceModel\Attribute\Collection $attributeCollection
     * @return CustomerTest
     */
    private function initAttributeTypes(Collection $attributeCollection): CustomerTest
    {
        /** @var Attribute $attribute */
        foreach ($attributeCollection as $attribute) {
            $this->attributeTypes[$attribute->getAttributeCode()] = $attribute->getFrontendInput();
        }

        return $this;
    }

    /**
     * Format Customer data as same as export data.
     *
     * @param CustomerModel $item
     * @param array $expectedAttributes
     * @return array
     */
    private function processCustomerData(CustomerModel $item, array $expectedAttributes): array
    {
        $data = [];
        foreach ($expectedAttributes as $attributeCode) {
            $attributeValue = $item->getData($attributeCode);

            if ($this->isMultiselect($attributeCode)) {
                $values = [];
                $attributeValue = explode(Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $attributeValue);
                foreach ($attributeValue as $value) {
                    $values[] = $this->getAttributeValueById($attributeCode, $value);
                }
                $data[$attributeCode] = implode(Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $values);
            } else {
                $data[$attributeCode] = $this->getAttributeValueById($attributeCode, $attributeValue);
            }
        }

        return $data;
    }

    /**
     * Check that attribute is multiselect type by attribute code.
     *
     * @param string $attributeCode
     * @return bool
     */
    private function isMultiselect(string $attributeCode): bool
    {
        return isset($this->attributeTypes[$attributeCode])
            && $this->attributeTypes[$attributeCode] === 'multiselect';
    }

    /**
     * Return attribute value by id.
     *
     * @param string $attributeCode
     * @param int|string $valueId
     * @return int|string|array
     */
    private function getAttributeValueById(string $attributeCode, $valueId)
    {
        if (isset($this->attributeValues[$attributeCode])
            && isset($this->attributeValues[$attributeCode][$valueId])
        ) {
            return $this->attributeValues[$attributeCode][$valueId];
        }

        return $valueId;
    }

    /**
     * Unset non-useful or duplicate data from exported file data.
     *
     * @param array $data
     * @return array
     */
    private function unsetDuplicateData(array $data): array
    {
        unset($data['_website']);
        unset($data['_store']);
        unset($data['password']);

        return $data;
    }

    /**
     * Test entity type code value
     */
    public function testGetEntityTypeCode()
    {
        $this->assertEquals('customer', $this->_model->getEntityTypeCode());
    }

    /**
     * Test type of attribute collection
     */
    public function testGetAttributeCollection()
    {
        $this->assertInstanceOf(Collection::class, $this->_model->getAttributeCollection());
    }

    /**
     * Test for method filterAttributeCollection()
     */
    public function testFilterAttributeCollection()
    {
        /** @var $collection Collection */
        $collection = $this->_model->getAttributeCollection();
        $collection = $this->_model->filterAttributeCollection($collection);
        /**
         * Check that disabled attributes is not existed in attribute collection
         */
        $existedAttributes = [];
        /** @var $attribute Attribute */
        foreach ($collection as $attribute) {
            $existedAttributes[] = $attribute->getAttributeCode();
        }
        $disabledAttributes = $this->_model->getDisabledAttributes();
        foreach ($disabledAttributes as $attributeCode) {
            $this->assertNotContains(
                $attributeCode,
                $existedAttributes,
                'Disabled attribute "' . $attributeCode . '" existed in collection'
            );
        }
        /**
         * Check that all overridden attributes were affected during filtering process
         */
        $overriddenAttributes = $this->_model->getOverriddenAttributes();
        /** @var $attribute Attribute */
        foreach ($collection as $attribute) {
            if (isset($overriddenAttributes[$attribute->getAttributeCode()])) {
                foreach ($overriddenAttributes[$attribute->getAttributeCode()] as $propertyKey => $property) {
                    $this->assertEquals(
                        $property,
                        $attribute->getData($propertyKey),
                        'Value of property "' . $propertyKey . '" is not equals'
                    );
                }
            }
        }
    }

    /**
     * Test for method filterEntityCollection()
     *
     * @magentoDataFixture Magento/Customer/_files/import_export/customers.php
     * @dataProvider filterDataProvider
     * @param string $locale
     * @param int $count
     * @param array $filter
     */
    public function testFilterEntityCollection(string $locale, int $count, array $filter)
    {
        $localeResolver = $this->objectManager->get(LocaleResolver::class);
        $localeResolver->setLocale($locale);

        $filter += [
            'email' => 'example.com',
            'store_id' => $this->objectManager->get(StoreManagerInterface::class)->getStore()->getId(),
        ];
        $parameters = [Export::FILTER_ELEMENT_GROUP => $filter];
        $this->_model->setParameters($parameters);
        /** @var $customers Collection */
        $collection = $this->_model->filterEntityCollection(
            $this->objectManager->create(
                CustomerCollection::class
            )
        );
        $collection->load();

        $this->assertCount($count, $collection);
    }

    /**
     * @return array
     */
    public static function filterDataProvider(): array
    {
        return [
            ['en_US', 1, ['created_at' => ['01/02/1999', '01/03/1999']]],
            ['en_US', 0, ['created_at' => ['02/01/1999', '02/02/1999']]],
            ['en_US', 2, ['created_at' => ['03/04/1999', null]]],
            ['en_US', 3, ['created_at' => [null, '05/07/1999']]],
            ['en_AU', 1, ['created_at' => ['02/01/1999', '02/02/1999']]],
            ['en_AU', 0, ['created_at' => ['01/02/1999', '01/03/1999']]],
            ['en_AU', 2, ['created_at' => ['04/03/1999', null]]],
            ['en_AU', 3, ['created_at' => [null, '07/05/1999']]],
            ['de_DE', 1, ['created_at' => ['02.01.1999', '03.01.1999']]],
            ['de_DE', 0, ['created_at' => ['01.02.1999', '01.03.1999']]],
            ['de_DE', 2, ['created_at' => ['04.03.1999', null]]],
            ['en_AU', 3, ['created_at' => [null, '07.05.1999']]],
        ];
    }

    /**
     * Export CSV string to array
     *
     * @param string $content
     * @param mixed $entityId
     * @return array
     */
    protected function _csvToArray($content, $entityId = null)
    {
        $data = ['header' => [], 'data' => []];

        $lines = str_getcsv($content, "\n", '"', '\\');
        foreach ($lines as $index => $line) {
            if ($index == 0) {
                $data['header'] = str_getcsv($line, ',', '"', '\\');
            } else {
                $row = array_combine($data['header'], str_getcsv($line, ',', '"', '\\'));
                if ($entityId !== null && !empty($row[$entityId])) {
                    $data['data'][$row[$entityId]] = $row;
                } else {
                    $data['data'][] = $row;
                }
            }
        }

        return $data;
    }
}
