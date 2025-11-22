<?php

namespace MediaWiki\Extension\StructureSync\Tests;

use MediaWiki\Extension\StructureSync\Display\DisplaySpecBuilder;
use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Schema\InheritanceResolver;
use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use PHPUnit\Framework\TestCase;

class DisplaySpecBuilderTest extends TestCase
{

    public function testBuildSpec_SingleCategory_ReturnsSections()
    {
        // Mock CategoryModel
        $category = $this->createMock(CategoryModel::class);
        $category->method('getName')->willReturn('Person');
        $category->method('getLabel')->willReturn('Person');
        $category->method('getDisplaySections')->willReturn([
            [
                'name' => 'Contact',
                'properties' => ['Has email', 'Has phone']
            ]
        ]);

        // Mock Store
        $store = $this->createMock(WikiCategoryStore::class);
        $store->method('readCategory')->with('Person')->willReturn($category);

        // Mock Resolver
        $resolver = $this->createMock(InheritanceResolver::class);
        $resolver->method('getAncestors')->willReturn([]);

        $builder = new DisplaySpecBuilder($resolver, $store);
        $spec = $builder->buildSpec('Person');

        $this->assertCount(1, $spec['sections']);
        $this->assertEquals('Contact', $spec['sections'][0]['name']);
        $this->assertEquals('Person', $spec['sections'][0]['category']);
        $this->assertEquals(['Has email', 'Has phone'], $spec['sections'][0]['properties']);
    }

    public function testBuildSpec_Inheritance_MergesSections()
    {
        // Parent Category
        $parent = $this->createMock(CategoryModel::class);
        $parent->method('getName')->willReturn('Person');
        $parent->method('getDisplaySections')->willReturn([
            [
                'name' => 'Contact',
                'properties' => ['Has email']
            ]
        ]);

        // Child Category
        $child = $this->createMock(CategoryModel::class);
        $child->method('getName')->willReturn('Faculty');
        $child->method('getDisplaySections')->willReturn([
            [
                'name' => 'Contact',
                'properties' => ['Has phone'] // Should append
            ],
            [
                'name' => 'Academic',
                'properties' => ['Has department'] // New section
            ]
        ]);

        // Mock Store
        $store = $this->createMock(WikiCategoryStore::class);
        $store->method('readCategory')->willReturnMap([
            ['Person', $parent],
            ['Faculty', $child]
        ]);

        // Mock Resolver
        $resolver = $this->createMock(InheritanceResolver::class);
        $resolver->method('getAncestors')->with('Faculty')->willReturn([$parent]);

        $builder = new DisplaySpecBuilder($resolver, $store);
        $spec = $builder->buildSpec('Faculty');

        $this->assertCount(2, $spec['sections']);

        // Section 1: Contact (Merged)
        $this->assertEquals('Contact', $spec['sections'][0]['name']);
        $this->assertEquals('Faculty', $spec['sections'][0]['category']); // Overridden by child
        $this->assertEquals(['Has email', 'Has phone'], $spec['sections'][0]['properties']);

        // Section 2: Academic (New)
        $this->assertEquals('Academic', $spec['sections'][1]['name']);
        $this->assertEquals('Faculty', $spec['sections'][1]['category']);
        $this->assertEquals(['Has department'], $spec['sections'][1]['properties']);
    }
}
