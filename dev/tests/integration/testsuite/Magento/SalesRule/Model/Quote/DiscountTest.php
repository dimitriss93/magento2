<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\SalesRule\Model\Quote;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Group;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\Subtotal;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Shipping;
use Magento\Quote\Model\ShippingAssignment;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Combine as CombineCondition;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\SalesRule\Test\Fixture\ProductCondition as ProductConditionFixture;
use Magento\SalesRule\Test\Fixture\ProductFoundInCartConditions as ProductFoundInCartConditionsFixture;
use Magento\SalesRule\Test\Fixture\Rule as RuleFixture;
use Magento\Tax\Model\ClassModel;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Test discount totals calculation model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DiscountTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;
    /**
     * @var SearchCriteriaBuilder
     */
    private $criteriaBuilder;
    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var DataFixtureStorage
     */
    private $fixtures;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var ClassModel
     */
    private $classModel;

    /**
     * @var Address
     */
    private $customerAddress;

    /**
     * @var Discount
     */
    private $discountCollector;

    /**
     * @var Subtotal
     */
    private $subtotalCollector;

    /**
     * @var Customer
     */
    private $customer;

    /**
     * @var ShippingAssignment
     */
    private $shippingAssignment;

    /**
     * @var Shipping
     */
    private $shipping;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var Quote\Address
     */
    private $quoteShippingAddress;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = Bootstrap::getObjectManager();
        $this->criteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->quoteRepository = $this->objectManager->get(CartRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->addressRepository = $this->objectManager->get(AddressRepositoryInterface::class);
        $this->classModel = $this->objectManager->create(ClassModel::class);
        $this->customerAddress = $this->objectManager->create(Address::class);
        $this->discountCollector = $this->objectManager->create(Discount::class);
        $this->subtotalCollector = $this->objectManager->create(Subtotal::class);
        $this->customer = $this->objectManager->create(Customer::class);
        $this->shippingAssignment = $this->objectManager->create(ShippingAssignment::class);
        $this->shipping = $this->objectManager->create(Shipping::class);
        $this->quote = $this->objectManager->create(Quote::class);
        $this->quoteShippingAddress = $this->objectManager->create(Quote\Address::class);
    }

    /**
     * @magentoDataFixture Magento/Checkout/_files/quote_with_bundle_product_with_dynamic_price.php
     * @dataProvider bundleProductWithDynamicPriceAndCartPriceRuleDataProvider
     * @param string $coupon
     * @param array $discounts
     * @param float $totalDiscount
     * @return void
     */
    #[
        AppIsolation(true),
        DataFixture(
            ProductConditionFixture::class,
            ['attribute' => 'sku', 'value' => 'bundle_product_with_dynamic_price'],
            'cond1'
        ),
        DataFixture(
            ProductConditionFixture::class,
            ['attribute' => 'sku', 'value' => 'simple1'],
            'cond2'
        ),
        DataFixture(
            ProductConditionFixture::class,
            ['attribute' => 'sku', 'value' => 'simple2'],
            'cond3'
        ),
        DataFixture(
            RuleFixture::class,
            ['coupon_code' => 'bundle_cc', 'discount_amount' => 50, 'actions' => ['$cond1$']],
            'rule1'
        ),
        DataFixture(
            RuleFixture::class,
            ['coupon_code' => 'simple1_cc', 'discount_amount' => 50, 'actions' => ['$cond2$']],
            'rule2'
        ),
        DataFixture(
            RuleFixture::class,
            ['coupon_code' => 'simple2_cc', 'discount_amount' => 50, 'actions' => ['$cond3$']],
            'rule3'
        ),
    ]
    public function testBundleProductWithDynamicPriceAndCartPriceRule(
        string $coupon,
        array $discounts,
        float $totalDiscount
    ): void {
        $quote = $this->getQuote('quote_with_bundle_product_with_dynamic_price');
        $quote->setCouponCode($coupon);
        $quote->collectTotals();
        $this->quoteRepository->save($quote);
        $this->assertEquals(21.98, $quote->getBaseSubtotal());
        $this->assertEquals($totalDiscount, $quote->getShippingAddress()->getDiscountAmount());
        $items = $quote->getAllItems();
        $this->assertCount(3, $items);
        /** @var Item $item*/
        $item = array_shift($items);
        $this->assertEquals('bundle_product_with_dynamic_price-simple1-simple2', $item->getSku());
        $this->assertEquals($discounts[$item->getSku()], $item->getDiscountAmount());
        $item = array_shift($items);
        $this->assertEquals('simple1', $item->getSku());
        $this->assertEquals(5.99, $item->getPrice());
        $this->assertEquals($discounts[$item->getSku()], $item->getDiscountAmount());
        $item = array_shift($items);
        $this->assertEquals('simple2', $item->getSku());
        $this->assertEquals(15.99, $item->getPrice());
        $this->assertEquals($discounts[$item->getSku()], $item->getDiscountAmount());
    }

    /**
     * @return array
     */
    public function bundleProductWithDynamicPriceAndCartPriceRuleDataProvider(): array
    {
        return [
            [
                'bundle_cc',
                [
                    'bundle_product_with_dynamic_price-simple1-simple2' => 0,
                    'simple1' => 3,
                    'simple2' => 7.99,
                ],
                -10.99
            ],
            [
                'simple1_cc',
                [
                    'bundle_product_with_dynamic_price-simple1-simple2' => 0,
                    'simple1' => 3,
                    'simple2' => 0,
                ],
                -3
            ],
            [
                'simple2_cc',
                [
                    'bundle_product_with_dynamic_price-simple1-simple2' => 0,
                    'simple1' => 0,
                    'simple2' => 8,
                ],
                -8
            ]
        ];
    }

    /**
     * @param string $reservedOrderId
     * @return Quote
     */
    private function getQuote(string $reservedOrderId): Quote
    {
        $searchCriteria = $this->criteriaBuilder->addFilter('reserved_order_id', $reservedOrderId)
            ->create();
        $carts = $this->quoteRepository->getList($searchCriteria)
            ->getItems();
        return array_shift($carts);
    }

    /**
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magento/Customer/_files/customer_address.php
     * @magentoDataFixture Magento/Customer/_files/customer_group.php
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    #[
        DbIsolation(true),
        DataFixture(CategoryFixture::class, as: 'c1'),
        DataFixture(CategoryFixture::class, as: 'c2'),
        DataFixture(CategoryFixture::class, as: 'c3'),
        DataFixture(ProductFixture::class, [
            'price' => 40,
            'sku' => 'p1',
            'category_ids' => ['$c1.id$']
        ], 'p1'),
        DataFixture(ProductFixture::class, [
            'price' => 30,
            'sku' => 'p2',
            'category_ids' => ['$c1.id$', '$c2.id$']
        ], 'p2'),
        DataFixture(ProductFixture::class, [
            'price' => 20,
            'sku' => 'p3',
            'category_ids' => ['$c2.id$', '$c3.id$']
        ], 'p3'),
        DataFixture(ProductFixture::class, [
            'price' => 10,
            'sku' => 'p4',
            'category_ids' => ['$c3.id$']
        ], 'p4'),

        DataFixture(
            ProductFoundInCartConditionsFixture::class,
            [
                'attribute' => 'category_ids',
                'value' => '$c1.id$',
                'operator' => '==',
                'conditions' => [
                    '1' => [
                        'type' => CombineCondition::class,
                        'aggregator' => 'all',
                        'value' => '1',
                        'new_child' => '',
                    ],
                    '1--1' => [
                        'type' => ProductCondition::class,
                        'attribute' => 'category_ids',
                        'operator' => '==',
                        'value' => '$c1.id$',
                    ]
                ],
            ],
            'cond1'
        ),
        DataFixture(
            ProductFoundInCartConditionsFixture::class,
            [
                'attribute' => 'category_ids',
                'value' => '$c2.id$',
                'operator' => '==',
                'conditions' => [
                    '1' => [
                        'type' => CombineCondition::class,
                        'aggregator' => 'all',
                        'value' => '1',
                        'new_child' => '',
                    ],
                    '1--1' => [
                        'type' => ProductCondition::class,
                        'attribute' => 'category_ids',
                        'operator' => '==',
                        'value' => '$c2.id$',
                    ]
                ],
            ],
            'cond2'
        ),
        DataFixture(
            ProductFoundInCartConditionsFixture::class,
            [
                'attribute' => 'category_ids',
                'value' => '$c3.id$',
                'operator' => '==',
                'conditions' => [
                    '1' => [
                        'type' => CombineCondition::class,
                        'aggregator' => 'all',
                        'value' => '1',
                        'new_child' => '',
                    ],
                    '1--1' => [
                        'type' => ProductCondition::class,
                        'attribute' => 'category_ids',
                        'operator' => '==',
                        'value' => '$c3.id$',
                    ]
                ],
            ],
            'cond3'
        ),
        DataFixture(
            RuleFixture::class,
            [
                'stop_rules_processing'=> 0,
                'coupon_code' => 'test',
                'discount_amount' => 10,
                'conditions' => ['$cond1$'],
                'simple_action' => Rule::BY_FIXED_ACTION,
                'sort_order' => 0
            ],
            'rule1'
        ),
        DataFixture(
            RuleFixture::class,
            [
                'discount_amount' => 5,
                'conditions' => ['$cond2$'],
                'simple_action' => Rule::BY_FIXED_ACTION,
                'sort_order' => 1
            ],
            'rule2'
        ),
        DataFixture(
            RuleFixture::class,
            [
                'stop_rules_processing'=> 0,
                'discount_amount' => 2,
                'conditions' => ['$cond3$'],
                'simple_action' => Rule::BY_FIXED_ACTION,
                'sort_order' => 2
            ],
            'rule3'
        ),
    ]
    public function testDiscountOnSimpleProductWithDiscardSubsequentRule(): void
    {
        $total = $this->quote([
            [
                'product'=> $this->fixtures->get('p1'),
                'qty'=>1
            ],
            [
                'product'=> $this->fixtures->get('p2'),
                'qty'=>1
            ],
            [
                'product'=> $this->fixtures->get('p3'),
                'qty'=>1
            ],
            [
                'product'=> $this->fixtures->get('p4'),
                'qty'=>1
            ]
        ]);
        $this->assertEquals(-32, $total->getDiscountAmount());
    }

    /**
     * Create quote and assert totals values
     *
     * @param array $quoteItems
     * @return Total
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function quote(array $quoteItems): Total
    {
        $customerTaxClassId = $this->getCustomerTaxClassId();
        $fixtureCustomerId = 1;
        /** @var Customer $customer */
        $customer = $this->customer->load($fixtureCustomerId);
        /** @var Group $customerGroup */
        $customerGroup = $this->objectManager->create(Group::class)->load('custom_group', 'customer_group_code');
        $customerGroup->setTaxClassId($customerTaxClassId)->save();
        $customer->setGroupId($customerGroup->getId())->save();
        $productTaxClassId = $this->getProductTaxClassId();
        $quoteShippingAddressDataObject = $this->getShippingAddressDataObject($fixtureCustomerId);
        $this->quoteShippingAddress->importCustomerAddressData($quoteShippingAddressDataObject);
        $this->quote->setStoreId(1)->setIsActive(true)->setIsMultiShipping(0)
            ->assignCustomerWithAddressChange($this->getCustomerById($customer->getId()))
            ->setShippingAddress($this->quoteShippingAddress)->setBillingAddress($this->quoteShippingAddress)
            ->setCheckoutMethod($customer->getMode())->setPasswordHash($customer->encryptPassword(
                $customer->getPassword()
            ))->setCouponCode('test');

        foreach ($quoteItems as $quoteItem) {
            $product = $quoteItem['product'] ?? null;
            if ($product === null) {
                /** @var Product $product */
                $product = $this->productRepository->get($quoteItem['sku'] ?? 'simple');
                $product->setTaxClassId($productTaxClassId)->save();
            }
            $this->quote->addProduct($product, $quoteItem['qty']);
        }

        $address = $this->quote->getShippingAddress();
        $this->shipping->setAddress($address);
        $this->shippingAssignment->setShipping($this->shipping);
        $this->shippingAssignment->setItems($address->getAllItems());
        /** @var  Total $total */
        $total = $this->objectManager->create(Total::class);
        $this->subtotalCollector->collect($this->quote, $this->shippingAssignment, $total);
        $this->discountCollector->collect($this->quote, $this->shippingAssignment, $total);
        return $total;
    }

    /**
     * Get customer tax class id
     *
     * @return int
     */
    protected function getCustomerTaxClassId()
    {
        //customerTaxClass
        $this->classModel->load('CustomerTaxClass2', 'class_name');
        return $this->classModel->getId();
    }

    /**
     * Get product tax class id
     *
     * @return int
     */
    protected function getProductTaxClassId()
    {
        //productTaxClass
        $this->classModel->load('ProductTaxClass1', 'class_name');
        return $this->classModel->getId();
    }

    /**
     * @param $fixtureCustomerId
     * @return AddressInterface
     * @throws LocalizedException
     */
    protected function getShippingAddressDataObject($fixtureCustomerId)
    {
        $fixtureCustomerAddressId = 1;
        $this->customerAddress->load($fixtureCustomerId);
        /** Set data which corresponds tax class fixture */
        $this->customerAddress->setCountryId('US')->setRegionId(12)->save();
        return $this->addressRepository->getById($fixtureCustomerAddressId);
    }

    /**
     * @param $id
     * @return CustomerInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getCustomerById($id)
    {
        /**
         * @var $customerRepository CustomerRepositoryInterface
         */
        $customerRepository = $this->objectManager->create(CustomerRepositoryInterface::class);
        return $customerRepository->getById($id);
    }
}
