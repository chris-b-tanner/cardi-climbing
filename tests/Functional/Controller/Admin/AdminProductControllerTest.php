<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Product;
use App\Tests\Support\FunctionalTestCase;

/** Settings > Products. Admin only. */
class AdminProductControllerTest extends FunctionalTestCase
{
    public function testTeamMembersCannotManageProducts(): void
    {
        $this->loginAsTeam();

        $this->assertForbidden('/admin/settings/products');
    }

    public function testAdminCanCreateAServiceProduct(): void
    {
        $this->loginAsAdmin();
        $this->assertPageLoads('/admin/settings/products');

        $name = 'PHPUnit product ' . $this->uniqueSuffix();
        $this->submitFormAt('/admin/settings/products/new', '/admin/settings/products/new', [
            'name'        => $name,
            'price'       => '7.50',
            'vatCode'     => Product::VAT_STANDARD,
            'productType' => Product::TYPE_SERVICE,
        ]);
        self::assertResponseRedirects();

        $product = $this->track($this->em()->getRepository(Product::class)->findOneBy(['name' => $name]));
        self::assertNotNull($product);
        self::assertSame('7.50', $product->getPrice());

        $this->assertPageLoads('/admin/settings/products/' . $product->getId() . '/edit');
    }
}
