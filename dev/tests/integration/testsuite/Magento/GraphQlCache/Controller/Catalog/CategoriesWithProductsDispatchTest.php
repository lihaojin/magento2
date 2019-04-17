<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQlCache\Controller\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Tests cache debug headers and cache tag validation for a category with product query
 *
 * @magentoAppArea graphql
 * @magentoDbIsolation disabled
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CategoriesWithProductsDispatchTest extends \Magento\TestFramework\Indexer\TestCase
{
    const CONTENT_TYPE = 'application/json';

    /** @var \Magento\Framework\ObjectManagerInterface */
    private $objectManager;

    /** @var GraphQl */
    private $graphql;

    /** @var SerializerInterface */
    private $jsonSerializer;

    /** @var MetadataPool */
    private $metadataPool;

    /** @var Http */
    private $request;

    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass()
    {
        $db = Bootstrap::getInstance()->getBootstrap()
            ->getApplication()
            ->getDbInstance();
        if (!$db->isDbDumpExists()) {
            throw new \LogicException('DB dump does not exist.');
        }
        $db->restoreFromDbDump();

        parent::setUpBeforeClass();
    }

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->graphql = $this->objectManager->get(\Magento\GraphQl\Controller\GraphQl::class);
        $this->jsonSerializer = $this->objectManager->get(SerializerInterface::class);
        $this->metadataPool = $this->objectManager->get(MetadataPool::class);
        $this->request = $this->objectManager->get(Http::class);
    }

    /**
     * Test cache tags and debug header for category with products querying for products and category
     *
     * @magentoCache all enabled
     * @magentoDataFixture Magento/Catalog/_files/category_product.php
     *
     */
    public function testDispatchForCacheHeadersAndCacheTagsForCategoryWtihProducts(): void
    {
        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        /** @var ProductInterface $product */
        $product= $productRepository->get('simple333');
        $categoryId ='333';
        $query
            = <<<QUERY
query GetCategoryWithProducts(\$id: Int!, \$pageSize: Int!, \$currentPage: Int!) {
        category(id: \$id) {
            id
            description
            name
            product_count
            products(
                      pageSize: \$pageSize, 
                      currentPage: \$currentPage) {
                items {
                    id
                    name
                    attribute_set_id
                    url_key
                    sku
                    type_id
                    updated_at
                    url_key
                    url_path
                }
                total_count
            }
        }
    }
QUERY;
        $variables =[
            'id' => 333,
            'pageSize'=> 10,
            'currentPage' => 1
        ];
        $queryParams = [
            'query' => $query,
            'variables' => json_encode($variables),
            'operationName' => 'GetCategoryWithProducts'
        ];

        $this->request->setPathInfo('/graphql');
        $this->request->setMethod('GET');
        $this->request->setParams($queryParams);
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->graphql->dispatch($this->request);
        /** @var \Magento\Framework\App\Response\Http $response */
        $response = $this->objectManager->get(\Magento\Framework\App\Response\Http::class);
        /** @var  $registry \Magento\Framework\Registry */
        $registry = $this->objectManager->get(\Magento\Framework\Registry::class);
        $registry->register('use_page_cache_plugin', true, true);
        $result->renderResult($response);
        $this->assertEquals('MISS', $response->getHeader('X-Magento-Cache-Debug')->getFieldValue());
        $expectedCacheTags = ['cat_c','cat_c_' . $categoryId,'cat_p','cat_p_' . $product->getId(),'FPC'];
        $actualCacheTags = explode(',', $response->getHeader('X-Magento-Tags')->getFieldValue());
        $this->assertEquals($expectedCacheTags, $actualCacheTags);
    }
}
